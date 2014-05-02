GitHub Project/Repository proxy downloader.
============================

This class will handle downloading, removing **master-** folder prefix, repacking and proxying back the project as a download.

Reason for making it, is that there seems no option to remove the master- prefix in the zip package download.

    **Requires** PHP5 >= 5.2.0, cURL, ZipArchive, safe_mode & open_basedir off

Example usage:
===
Include the class and simply pass the repo address as a parameter. 

    new GitDL('https://github.com/lcherone/GitDL');

