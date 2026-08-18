<?php
/**
 * Shared PDO connection factory for QRS v2 (SQL Server via pdo_sqlsrv).
 * Credentials live in conn/config.php, protected by conn/.htaccess
 * ("Require all denied") so they're never web-reachable directly, but
 * still travel with the project on a plain FTP deploy.
 *
 * Tables live directly in the "dbo" schema of the shared LRNPH_OJT database,
 * prefixed "qrs_" (e.g. dbo.qrs_tasks) to avoid colliding with that
 * database's existing tables (acd_*, AP_*, activity_logs, etc.).
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf('sqlsrv:Server=%s;Database=%s;TrustServerCertificate=true', QRS_DB_HOST, QRS_DB_NAME);

    $pdo = new PDO($dsn, QRS_DB_USER, QRS_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    ]);

    return $pdo;
}
