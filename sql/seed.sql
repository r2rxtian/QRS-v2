/*
 * QRS v2 — baseline data (T-SQL / SQL Server). Run after schema.sql.
 * Reflects the real System Requirements Specification data currently live
 * in the database: 2 roles (Admin/User) and the 12 real employee accounts
 * from the client's user list. No department concept -- access is based on
 * each user's own biometrics/employee_id, not a department.
 *
 * This is a ONE-TIME bootstrap script, not the ongoing way to add users --
 * once an Admin account exists and can log in, every subsequent user gets
 * added live through Settings > User Management (see api/users/create.php),
 * which writes straight to dbo.qrs_users, no SQL involved. This file only
 * matters again for a fresh install (schema.sql + this, in order, before
 * the app has any Admin to log in with) or as a record of the original
 * roles/roster.
 *
 * The real location catalog (~240 rows, Monitoring/Treatment) is NOT
 * seeded here — run sql/import_real_locations.php separately after this
 * file (it reads sql/locations_monitoring.csv + sql/locations_treatment.csv).
 *
 * full_name is NOT a qrs_users column -- it's always derived live from
 * dbo.lrn_master_list (matched on EmployeeID = employee_id), see
 * rules/constants.php's fullNameSql(). The names below are kept in the
 * VALUES tuple purely as a human-readable reference for whose employee_id
 * is whose, not inserted anywhere.
 *
 * Login credentials are NOT stored in qrs_users either (no username or
 * password_hash column) -- login resolves through the company-wide
 * dbo.lrnph_users table instead, bridged via dbo.lrn_master_list (see
 * auth/login_handler.php). A person only gains QRS v2 access once BOTH
 * exist: a row here (this file) AND a row in lrnph_users with a matching
 * biometrics number via the master list.
 */

USE LRNPH_OJT;
GO

-- ============================================================
-- Roles (2 rows)
-- ============================================================
INSERT INTO dbo.qrs_roles (name, description) VALUES
    ('Admin', 'Full access: create tasks, complete tasks, view reports.'),
    ('User',  'Complete assigned tasks and view reports.');
GO

-- ============================================================
-- Real users (from the client's user list + access matrix)
-- ============================================================
DECLARE @adminRoleId TINYINT = (SELECT id FROM dbo.qrs_roles WHERE name = 'Admin');
DECLARE @userRoleId TINYINT = (SELECT id FROM dbo.qrs_roles WHERE name = 'User');

;WITH RealUsers AS (
    SELECT * FROM (VALUES
        ('2024-40484', 'David, Ana Victoria Mendoza', 'admin'),
        ('2025-40545', 'Balagtas, Kim Labado',        'admin'),
        ('2018-6160',  'Malang, Mirasol Almayda',      'admin'),
        ('CA17-3580',  'Dionisio, Arjie Dacurin',      'admin'),
        ('2022-22461', 'Ocampo, Gerald Miranda',       'user'),
        ('2021-13096', 'David, Nicky Angeles',         'user'),
        ('2025-40848', 'Valencia, Lieart Simeon',      'user'),
        ('2025-40910', 'Primero, Jayson Verano',       'user'),
        ('2022-13939', 'Torres, Jesus Morado',         'user'),
        ('2024-40337', 'Bollido, Jojie Mabalda',       'user'),
        ('2024-28094', 'Jazmin, Robert Jr. Oller',     'user'),
        ('2021-12291', 'Figueroa, Jovi Maristela',     'user')
    ) AS t(employee_id, full_name, role_key)
)
INSERT INTO dbo.qrs_users (employee_id, role_id)
SELECT r.employee_id,
       CASE WHEN r.role_key = 'admin' THEN @adminRoleId ELSE @userRoleId END
FROM RealUsers r;
GO

PRINT 'Baseline data inserted: 2 roles, 12 real users. Next: run sql/import_real_locations.php.';
