<?php
/*=========================================================================
  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) Kitware, Inc. All rights reserved.
  See LICENSE or http://www.cdash.org/licensing/ for details.

  This software is distributed WITHOUT ANY WARRANTY; without even
  the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
  PURPOSE. See the above copyright notices for more information.
=========================================================================*/

use App\Models\User;
use CDash\Model\Label;
use CDash\Model\LabelEmail;
use CDash\Model\Project;
use CDash\Model\SubProject;
use CDash\Model\UserProject;
use Illuminate\Support\Facades\DB;

class ProjectHandler extends AbstractXmlHandler
{
    private $SubProject;
    private $SubProjectPosition;
    private $Dependencies; // keep an array of dependencies in order to remove them
    private $SubProjects; // keep an array of subprojects in order to remove them
    private $CurrentDependencies; // The dependencies of the current SubProject.
    private $Emails; // Email addresses associated with the current SubProject.
    private $ProjectNameMatches;

    /** Constructor */
    public function __construct(Project $project)
    {
        parent::__construct($project);

        // Only actually track stuff and write it into the database if the
        // Project.xml file's name element matches this project's name in the
        // database.
        //
        $this->ProjectNameMatches = true;

        $this->SubProjectPosition = 1;
    }

    /** startElement function */
    public function startElement($parser, $name, $attributes): void
    {
        parent::startElement($parser, $name, $attributes);

        // Check that the project name matches
        if ($name == 'PROJECT') {
            if (get_project_id($attributes['NAME']) != $this->GetProject()->Id) {
                add_log('Wrong project name: ' . $attributes['NAME'],
                    'ProjectHandler::startElement', LOG_ERR, $this->GetProject()->Id);
                $this->ProjectNameMatches = false;
            }
        }

        if (!$this->ProjectNameMatches) {
            return;
        }

        if ($name == 'PROJECT') {
            $this->SubProjects = [];
            $this->Dependencies = [];
        } elseif ($name == 'SUBPROJECT') {
            $this->CurrentDependencies = [];
            $this->SubProject = new SubProject();
            $this->SubProject->SetProjectId($this->GetProject()->Id);
            $this->SubProject->SetName($attributes['NAME']);
            if (array_key_exists('GROUP', $attributes)) {
                $this->SubProject->SetGroup($attributes['GROUP']);
            }
            $this->Emails = [];
        } elseif ($name == 'DEPENDENCY') {
            // A DEPENDENCY is expected to be:
            //
            //  - another subproject that already exists
            //    (from a previous element in this submission)
            //
            $dependentProject = new SubProject();
            $dependentProject->SetName($attributes['NAME']);
            $dependentProject->SetProjectId($this->GetProject()->Id);
            // The subproject's Id is automatically loaded once its name & projectid
            // are set.
            $this->CurrentDependencies[] = $dependentProject->GetId();
        } elseif ($name == 'EMAIL') {
            $this->Emails[] = $attributes['ADDRESS'];
        }
    }

    /** endElement function */
    public function endElement($parser, $name): void
    {
        parent::endElement($parser, $name);

        if (!$this->ProjectNameMatches) {
            return;
        }

        if ($name == 'PROJECT') {
            foreach ($this->SubProjects as $subproject) {
                if (config('cdash.delete_old_subprojects')) {
                    // Remove dependencies that do not exist anymore,
                    // but only for those relationships where both sides
                    // are present in $this->SubProjects.
                    $dependencyids = $subproject->GetDependencies();
                    $removeids = array_diff($dependencyids, $this->Dependencies[$subproject->GetId()]);

                    foreach ($removeids as $removeid) {
                        if (array_key_exists($removeid, $this->SubProjects)) {
                            $subproject->RemoveDependency(intval($removeid));
                        } else {
                            // TODO: (williamjallen) Rewrite this loop to not make repetitive queries
                            $dep = DB::select('SELECT name FROM subproject WHERE id=?', [intval($removeid)])[0] ?? [];
                            $dep = $dep !== [] ? $dep->name : intval($removeid);
                            add_log(
                                "Not removing dependency $dep($removeid) from " .
                                $subproject->GetName() .
                                ' because it is not a SubProject element in this Project.xml file',
                                'ProjectHandler:endElement', LOG_WARNING, $this->GetProject()->Id);
                        }
                    }
                }

                // Add dependencies that were queued up as we processed the DEPENDENCY
                // elements:
                //
                foreach ($this->Dependencies[$subproject->GetId()] as $addid) {
                    if (array_key_exists($addid, $this->SubProjects)) {
                        $subproject->AddDependency(intval($addid));
                    } else {
                        add_log(
                            'impossible condition: should NEVER see this: unknown DEPENDENCY clause should prevent this case',
                            'ProjectHandler:endElement', LOG_WARNING, $this->GetProject()->Id);
                    }
                }
            }

            if (config('cdash.delete_old_subprojects')) {
                // Delete old subprojects that weren't included in this file.
                $previousSubProjectIds = $this->GetProject()->GetSubProjects()->pluck('id')->toArray();
                foreach ($previousSubProjectIds as $previousId) {
                    $found = false;
                    foreach ($this->SubProjects as $subproject) {
                        if ($subproject->GetId() == $previousId) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $subProjectToRemove = new SubProject();
                        $subProjectToRemove->SetId($previousId);
                        $subProjectToRemove->Delete();
                        add_log("Deleted " . $subProjectToRemove->GetName() . " because it was not mentioned in Project.xml",
                            'ProjectHandler:endElement', LOG_WARNING,
                            $this->GetProject()->Id);
                    }
                }
            }
        } elseif ($name == 'SUBPROJECT') {
            // Insert the SubProject.
            $this->SubProject->SetPosition($this->SubProjectPosition);
            $this->SubProject->Save();
            $this->SubProjectPosition++;

            // Insert the label.
            $Label = new Label;
            $Label->Text = $this->SubProject->GetName();
            $Label->Insert();

            $this->SubProjects[$this->SubProject->GetId()] = $this->SubProject;

            // Handle dependencies here too.
            $this->Dependencies[$this->SubProject->GetId()] = [];
            foreach ($this->CurrentDependencies as $dependencyid) {
                $added = false;

                if ($dependencyid !== false && is_numeric($dependencyid)) {
                    if (array_key_exists($dependencyid, $this->SubProjects)) {
                        $this->Dependencies[$this->SubProject->GetId()][] = $dependencyid;
                        $added = true;
                    }
                }

                if (!$added) {
                    add_log('Project.xml DEPENDENCY of ' . $this->SubProject->GetName() .
                        ' not mentioned earlier in file.',
                        'ProjectHandler:endElement', LOG_WARNING, $this->GetProject()->Id);
                }
            }

            foreach ($this->Emails as $email) {
                // Check if the user is in the database.
                $user = new User();

                $posat = strpos($email, '@');
                if ($posat !== false) {
                    $user->firstname = substr($email, 0, $posat);
                    $user->lastname = substr($email, $posat + 1);
                } else {
                    $user->firstname = $email;
                    $user->lastname = $email;
                }
                $user->email = $email;
                $user->password = password_hash($email, PASSWORD_DEFAULT);
                $user->admin = false;
                $existing_user = User::where('email', $email)->first();
                if ($existing_user) {
                    $userid = $existing_user->id;
                } else {
                    $user->save();
                    $userid = $user->id;
                }

                $UserProject = new UserProject();
                $UserProject->UserId = $userid;
                $UserProject->ProjectId = $this->GetProject()->Id;
                if (!$UserProject->FillFromUserId()) {
                    // This user wasn't already subscribed to this project.
                    $UserProject->EmailType = 3; // any build
                    $UserProject->EmailCategory = 54; // everything except warnings
                    $UserProject->Save();
                }

                // Insert the labels for this user
                $LabelEmail = new LabelEmail;
                $LabelEmail->UserId = $userid;
                $LabelEmail->ProjectId = $this->GetProject()->Id;

                $Label = new Label;
                $Label->SetText($this->SubProject->GetName());
                $labelid = $Label->GetIdFromText();
                if (!empty($labelid)) {
                    $LabelEmail->LabelId = $labelid;
                    $LabelEmail->Insert();
                }
            }
        }
    }

    /** text function */
    public function text($parser, $data)
    {
        $element = $this->getElement();
        if ($element == 'PATH') {
            $this->SubProject->SetPath($data);
        }
    }
}
