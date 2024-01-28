set(CTEST_SITE "${SITENAME}")
set(CTEST_BUILD_NAME "${BUILDNAME}")
set(CTEST_CMAKE_GENERATOR "Unix Makefiles")
set(CTEST_SOURCE_DIRECTORY "/cdash")
set(CTEST_BINARY_DIRECTORY "/cdash/_build")
set(CTEST_UPDATE_COMMAND git)
set(CTEST_UPDATE_VERSION_ONLY 1)

# Start with an empty build directory.
ctest_empty_binary_directory("${CTEST_BINARY_DIRECTORY}")

# CMake config variables.
set(cfg_options
  "-DCDASH_DIR_NAME="
  "-DCDASH_SERVER=localhost:8080"
)

# Backup .env file
file(MAKE_DIRECTORY "${CTEST_BINARY_DIRECTORY}/env_backup/")
configure_file(
  "${CTEST_SOURCE_DIRECTORY}/.env"
  "${CTEST_BINARY_DIRECTORY}/env_backup/.env"
  COPYONLY
)

ctest_start(Continuous)
ctest_update()
ctest_submit(PARTS Update)
ctest_configure(OPTIONS "${cfg_options}")
ctest_submit(PARTS Configure)
ctest_test(
  RETURN_VALUE test_status
  CAPTURE_CMAKE_ERROR cmake_errors
  STOP_ON_FAILURE
)
if (NOT "${test_status}" EQUAL 0)
  message(SEND_ERROR "some tests did not pass cleanly")
endif()
ctest_submit(PARTS Test Done)

# Restore original .env file
file(RENAME
  "${CTEST_BINARY_DIRECTORY}/env_backup/.env"
  "${CTEST_SOURCE_DIRECTORY}/.env"
)
execute_process(
  COMMAND npm run prod
  WORKING_DIRECTORY "${CTEST_SOURCE_DIRECTORY}"
)
