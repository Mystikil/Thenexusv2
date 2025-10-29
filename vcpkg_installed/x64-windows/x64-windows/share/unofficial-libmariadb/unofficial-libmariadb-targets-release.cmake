#----------------------------------------------------------------
# Generated CMake target import file for configuration "Release".
#----------------------------------------------------------------

# Commands may need to know the format version.
set(CMAKE_IMPORT_FILE_VERSION 1)

# Import target "unofficial::libmariadb" for configuration "Release"
set_property(TARGET unofficial::libmariadb APPEND PROPERTY IMPORTED_CONFIGURATIONS RELEASE)
set_target_properties(unofficial::libmariadb PROPERTIES
  IMPORTED_IMPLIB_RELEASE "${_IMPORT_PREFIX}/lib/libmariadb.lib"
  IMPORTED_LOCATION_RELEASE "${_IMPORT_PREFIX}/bin/libmariadb.dll"
  )

list(APPEND _cmake_import_check_targets unofficial::libmariadb )
list(APPEND _cmake_import_check_files_for_unofficial::libmariadb "${_IMPORT_PREFIX}/lib/libmariadb.lib" "${_IMPORT_PREFIX}/bin/libmariadb.dll" )

# Commands beyond this point should not need to know the version.
set(CMAKE_IMPORT_FILE_VERSION)
