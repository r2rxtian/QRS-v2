<?php
/**
 * QRTS DB credentials. Kept inside conn/ (protected by conn/.htaccess,
 * "Require all denied") so this travels with a plain FTP upload to any
 * new server instead of needing to be recreated by hand each time.
 */

// Main Production Server (10.2.0.9)
// define('QRS_DB_DRIVER', 'sqlsrv');
// define('QRS_DB_HOST', '10.2.0.9');
// define('QRS_DB_NAME', 'LRNPH_OJT');
// define('QRS_DB_TABLE_PREFIX', 'qrs_');   // tables live in dbo, prefixed to avoid colliding with existing OJT tables
// define('QRS_DB_USER', 'acdavid');
// define('QRS_DB_PASS', '4rthurd4v1d@123');

// Testing Server (10.2.0.167)
define('QRS_DB_DRIVER', 'sqlsrv');
define('QRS_DB_HOST', '10.2.0.167');
define('QRS_DB_NAME', 'LRNPH_OJT');
define('QRS_DB_TABLE_PREFIX', 'qrs_');   // tables live in dbo, prefixed to avoid colliding with existing OJT tables
define('QRS_DB_USER', 'acdavid');
define('QRS_DB_PASS', '4rthurd4v1d@123');
