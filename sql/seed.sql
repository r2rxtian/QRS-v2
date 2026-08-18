/*
 * QRS v2 — baseline data (T-SQL / SQL Server). Run after schema.sql.
 * Reflects the real System Requirements Specification data currently live
 * in the database: 2 roles (Admin/User), 1 department (Pest Control
 * Program), and the 12 real employee accounts from the client's user list.
 *
 * The real location catalog (~240 rows, Monitoring/Treatment) is NOT
 * seeded here — run sql/import_real_locations.php separately after this
 * file (it reads sql/locations_monitoring.csv + sql/locations_treatment.csv).
 *
 * IMPORTANT — passwords: all 12 accounts get the SAME temporary password:
 * ChangeMe123!
 * There is no "force password change on first login" feature built, so
 * distribute this password and have each person change it via their
 * account/settings page. The hash below was generated with PHP's
 * password_hash('ChangeMe123!', PASSWORD_DEFAULT) — do not hand-edit it.
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
-- Department (single team, no per-department concept in the spec)
-- ============================================================
INSERT INTO dbo.qrs_departments (name, site_code) VALUES
    ('Quality Assurance', 'LRN');
GO

-- ============================================================
-- Real users (from the client's user list + access matrix)
-- ============================================================
DECLARE @adminRoleId TINYINT = (SELECT id FROM dbo.qrs_roles WHERE name = 'Admin');
DECLARE @userRoleId TINYINT = (SELECT id FROM dbo.qrs_roles WHERE name = 'User');
DECLARE @deptId SMALLINT = (SELECT id FROM dbo.qrs_departments WHERE name = 'Quality Assurance');
DECLARE @tempHash VARCHAR(255) = '$2y$10$eImpdPsufRMDKgmdD899oO9mQ9hmZWX8mNWL7tMCYjrujoZRF7W7O'; -- ChangeMe123!

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
-- username reuses employee_id (rather than NULL) -- dbo.qrs_users.username has
-- a plain UNIQUE constraint, and SQL Server only allows ONE NULL per unique
-- column across the whole table, so 12 simultaneous NULLs fails.
INSERT INTO dbo.qrs_users (employee_id, username, full_name, password_hash, role_id, department_id)
SELECT r.employee_id, r.employee_id, r.full_name, @tempHash,
       CASE WHEN r.role_key = 'admin' THEN @adminRoleId ELSE @userRoleId END,
       @deptId
FROM RealUsers r;
GO

PRINT 'Baseline data inserted: 2 roles, 1 department, 12 real users (temp password: ChangeMe123!). Next: run sql/import_real_locations.php.';
