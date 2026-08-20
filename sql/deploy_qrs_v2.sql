/* ============================================================================
 * QRS v2 — full deployment package (T-SQL / SQL Server)
 * ============================================================================
 * Run top to bottom (in SSMS, or however the target database is reached) to
 * stand up everything this app owns: the 8 dbo.qrs_* tables, the 2 fixed
 * roles, the 12 real employee accounts, and the real 240-row location
 * catalog (Monitoring + Treatment). Just CREATE TABLE / INSERT commands --
 * which server or database this runs against is not this file's concern.
 *
 * sql/schema.sql and sql/seed.sql are UNCHANGED and still the ones actively
 * maintained during development against the current LRNPH_OJT database --
 * this file is a separate, deployment-only package generated from them.
 *
 * ----------------------------------------------------------------------------
 * Dependency note: dbo.lrn_master_list / dbo.lrnph_users
 * ----------------------------------------------------------------------------
 * This app's login and every displayed person's name depend on two tables
 * it does NOT own or create here -- dbo.lrn_master_list (real name/
 * department per employee) and dbo.lrnph_users (company-wide login
 * credentials), referenced unqualified (same database as the tables
 * below). The copies this app was built and tested against (in LRNPH_OJT)
 * are themselves just a backup -- the authoritative copies live in the
 * supervisors' own main database, which is where this script is headed,
 * so both tables are expected to already be there. Nothing to do here;
 * noting it so the assumption is on record, not silently relied on.
 *
 * ----------------------------------------------------------------------------
 * Type translation notes (unchanged from sql/schema.sql)
 * ----------------------------------------------------------------------------
 *   - IDENTITY(1,1) instead of AUTO_INCREMENT
 *   - No native ENUM type: VARCHAR + CHECK constraint instead
 *   - BIT instead of TINYINT(1) for booleans
 *   - DATETIME2 instead of DATETIME; SYSDATETIME() instead of NOW()
 *   - No FOREIGN KEY constraints (matches this company's existing
 *     convention) -- referential integrity between role_id/task_id/
 *     location_id/*_by columns and their parent tables is enforced at the
 *     application layer (see authz/authz.php and the api/ endpoints), not
 *     by the database. Primary key, UNIQUE, and CHECK constraints are
 *     still used.
 *   - No department concept -- this app is used by a single QA team,
 *     access is based on each user's own biometrics/employee_id.
 * ============================================================================
 */

-- ============================================================
-- SECTION 1: SCHEMA — 8 tables
-- ============================================================

-- ============================================================
-- dbo.qrs_roles — fixed set of 2 rows, seeded in Section 2
-- ============================================================
CREATE TABLE dbo.qrs_roles (
    id            TINYINT IDENTITY(1,1) PRIMARY KEY,
    name          VARCHAR(30)   NOT NULL,
    description   VARCHAR(255)  NULL,
    created_at    DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT UQ_qrs_roles_name UNIQUE (name)
);
GO

-- ============================================================
-- dbo.qrs_users
-- ============================================================
CREATE TABLE dbo.qrs_users (
    id                    INT IDENTITY(1,1) PRIMARY KEY,
    employee_id           VARCHAR(20)    NOT NULL,        -- matches dbo.lrn_master_list.EmployeeID (see
                                                            -- rules/constants.php's T_MASTER_LIST/fullNameSql())
                                                            -- for display name, and bridges to
                                                            -- dbo.lrnph_users (via lrn_master_list.BiometricsID)
                                                            -- for login credentials -- neither full_name nor a
                                                            -- password is stored in this table
    role_id               TINYINT        NOT NULL,
    avatar_initials       VARCHAR(4)     NULL,
    avatar_color          CHAR(7)        NULL,
    is_active             BIT            NOT NULL DEFAULT 1,
    failed_login_attempts SMALLINT       NOT NULL DEFAULT 0,
    locked_until          DATETIME2      NULL,
    last_login_at         DATETIME2      NULL,
    theme_dark            BIT            NULL,             -- optional server-side persistence of the accessibility popover's prefs (low priority)
    accent_color          CHAR(7)        NULL,             -- optional, low priority
    created_at            DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    updated_at            DATETIME2      NOT NULL DEFAULT SYSDATETIME(),  -- app sets this explicitly on every UPDATE
    deleted_at            DATETIME2      NULL,             -- soft delete/deactivate
    CONSTRAINT UQ_qrs_users_employee_id UNIQUE (employee_id)
);
GO
CREATE INDEX IX_qrs_users_role ON dbo.qrs_users(role_id);
GO

-- ============================================================
-- dbo.qrs_locations
-- ============================================================
CREATE TABLE dbo.qrs_locations (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    name          VARCHAR(150)   NOT NULL,
    qr_token      CHAR(16)       NOT NULL,    -- random hex, what's actually encoded in the printed QR (not the name)
    location_type VARCHAR(12)    NOT NULL DEFAULT 'Treatment',  -- 'Monitoring' | 'Treatment' -- matches the client's two separate catalogs
    is_active     BIT            NOT NULL DEFAULT 1,   -- retired locations kept for history, hidden from assignment picker
    created_by    INT            NOT NULL,
    created_at    DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    updated_at    DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    deleted_at    DATETIME2      NULL,
    CONSTRAINT UQ_qrs_locations_name UNIQUE (name),
    CONSTRAINT UQ_qrs_locations_qr_token UNIQUE (qr_token),
    CONSTRAINT CK_qrs_locations_location_type CHECK (location_type IN ('Monitoring', 'Treatment'))
);
GO
-- Location status (Available / Location Assigned) is computed on read by the
-- app: assigned iff any qrs_task_locations row exists with unassigned_at IS NULL.

-- ============================================================
-- dbo.qrs_tasks — no status column; computed on read
-- ============================================================
CREATE TABLE dbo.qrs_tasks (
    id             INT IDENTITY(1,1) PRIMARY KEY,
    name           VARCHAR(200)   NOT NULL,
    owner_id       INT            NOT NULL,   -- creator, always session-derived, never client-submitted
    task_type      VARCHAR(12)    NOT NULL DEFAULT 'Treatment',  -- 'Monitoring' | 'Treatment' -- fixed at creation, constrains which locations can be assigned
    created_at     DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    updated_at     DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    deleted_at     DATETIME2      NULL,      -- soft delete
    CONSTRAINT CK_qrs_tasks_task_type CHECK (task_type IN ('Monitoring', 'Treatment'))
);
GO
CREATE INDEX IX_qrs_tasks_owner ON dbo.qrs_tasks(owner_id);
CREATE INDEX IX_qrs_tasks_deleted ON dbo.qrs_tasks(deleted_at);
GO

-- ============================================================
-- dbo.qrs_task_locations — assignment + per-day visit record, combined
-- ============================================================
CREATE TABLE dbo.qrs_task_locations (
    id                    INT IDENTITY(1,1) PRIMARY KEY,
    task_id               INT            NOT NULL,
    location_id           INT            NOT NULL,
    task_date             DATE           NOT NULL,   -- defaults to CAST(SYSDATETIME() AS DATE) at assignment (app-set)
    assigned_by           INT            NOT NULL,
    assigned_at           DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    unassigned_by         INT            NULL,
    unassigned_at         DATETIME2      NULL,       -- non-NULL = removed from active roster; row + history kept
    -- Observation/Recommendation checklist (4 fixed items, per the client's spec).
    -- Each answer is Yes/No/N/A; a remark is required at the app layer when the
    -- paired answer is No or N/A.
    spot_spray_answer     VARCHAR(3)     NULL,
    spot_spray_remark     VARCHAR(MAX)   NULL,
    misting_answer        VARCHAR(3)     NULL,
    misting_remark        VARCHAR(MAX)   NULL,
    mist_blower_answer    VARCHAR(3)     NULL,
    mist_blower_remark    VARCHAR(MAX)   NULL,
    monitoring_answer     VARCHAR(3)     NULL,
    monitoring_remark     VARCHAR(MAX)   NULL,
    findings_observation  VARCHAR(MAX)   NULL,       -- captured at scan-start, optional
    completion_remark     VARCHAR(MAX)   NULL,       -- captured at photo-submit, optional
    start_time            DATETIME2      NULL,
    scanned_by            INT            NULL,
    end_time              DATETIME2      NULL,
    completed_by          INT            NULL,       -- may differ from scanned_by (handoff); re-confirms their own biometrics/staff code at completion
    status                VARCHAR(12)    NOT NULL DEFAULT 'pending',  -- app-recomputed on every write, never independently settable
    created_at            DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    updated_at            DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    CONSTRAINT CK_qrs_tl_spot_spray_answer  CHECK (spot_spray_answer  IN ('Yes', 'No', 'N/A')),
    CONSTRAINT CK_qrs_tl_misting_answer     CHECK (misting_answer     IN ('Yes', 'No', 'N/A')),
    CONSTRAINT CK_qrs_tl_mist_blower_answer CHECK (mist_blower_answer IN ('Yes', 'No', 'N/A')),
    CONSTRAINT CK_qrs_tl_monitoring_answer  CHECK (monitoring_answer  IN ('Yes', 'No', 'N/A')),
    CONSTRAINT CK_qrs_tl_status CHECK (status IN ('pending','in_progress','completed'))
);
GO
CREATE INDEX IX_qrs_tl_task_date ON dbo.qrs_task_locations(task_id, task_date);
CREATE INDEX IX_qrs_tl_location ON dbo.qrs_task_locations(location_id);
CREATE INDEX IX_qrs_tl_scanned_by ON dbo.qrs_task_locations(scanned_by);
CREATE INDEX IX_qrs_tl_completed_by ON dbo.qrs_task_locations(completed_by);
CREATE INDEX IX_qrs_tl_active_assignment ON dbo.qrs_task_locations(location_id, unassigned_at);
GO
-- Status derivation (app code, not a trigger):
--   start_time IS NULL                    -> 'pending'
--   start_time set, end_time IS NULL      -> 'in_progress'
--   end_time set                          -> 'completed'
-- "One active row per (task, location, day)" is enforced by the app
-- (check-before-insert), not a DB constraint.

-- ============================================================
-- dbo.qrs_task_location_photos
-- ============================================================
CREATE TABLE dbo.qrs_task_location_photos (
    id                 INT IDENTITY(1,1) PRIMARY KEY,
    task_location_id   INT            NOT NULL,
    photo_type         VARCHAR(10)    NOT NULL DEFAULT 'after',  -- reserved for future 'before'; v1 only ever writes 'after'
    stored_filename    VARCHAR(64)    NOT NULL,   -- server-generated random name, NEVER derived from client input
    original_filename  VARCHAR(255)   NOT NULL,   -- kept for display/audit only, never used to build a filesystem path
    mime_type          VARCHAR(100)   NOT NULL,   -- from finfo/getimagesize detection, not client Content-Type
    file_size_bytes    INT            NOT NULL,
    uploaded_by        INT            NOT NULL,
    uploaded_at        DATETIME2      NOT NULL DEFAULT SYSDATETIME(),
    deleted_at         DATETIME2      NULL,
    CONSTRAINT CK_qrs_tlp_photo_type CHECK (photo_type IN ('after','before'))
);
GO
CREATE INDEX IX_qrs_tlp_task_location ON dbo.qrs_task_location_photos(task_location_id);
GO

-- ============================================================
-- dbo.qrs_audit_log
-- ============================================================
CREATE TABLE dbo.qrs_audit_log (
    id            BIGINT IDENTITY(1,1) PRIMARY KEY,
    user_id       INT            NULL,   -- nullable for failed logins / system actions
    action        VARCHAR(60)    NOT NULL,   -- 'login.success', 'task.create', 'task_location.unassign', 'photo.upload', ...
    entity_type   VARCHAR(30)    NULL,   -- 'task' | 'location' | 'task_location' | 'user'
    entity_id     INT            NULL,
    details       NVARCHAR(MAX)  NULL,   -- JSON text (SQL Server has JSON_VALUE/JSON_QUERY functions, no native JSON type)
    ip_address    VARCHAR(45)    NULL,
    user_agent    VARCHAR(255)   NULL,
    created_at    DATETIME2      NOT NULL DEFAULT SYSDATETIME()
);
GO
CREATE INDEX IX_qrs_audit_entity ON dbo.qrs_audit_log(entity_type, entity_id);
CREATE INDEX IX_qrs_audit_user ON dbo.qrs_audit_log(user_id);
CREATE INDEX IX_qrs_audit_created ON dbo.qrs_audit_log(created_at);
GO

-- ============================================================
-- dbo.qrs_rate_limit_events — destructive-action throttling
-- (login-attempt limiting instead uses dbo.qrs_users.failed_login_attempts / locked_until directly)
-- ============================================================
CREATE TABLE dbo.qrs_rate_limit_events (
    id            BIGINT IDENTITY(1,1) PRIMARY KEY,
    event_key     VARCHAR(150)   NOT NULL,   -- e.g. 'delete:task:42'
    ip_address    VARCHAR(45)    NULL,
    occurred_at   DATETIME2      NOT NULL DEFAULT SYSDATETIME()
);
GO
CREATE INDEX IX_qrs_rle_key_time ON dbo.qrs_rate_limit_events(event_key, occurred_at);
GO

PRINT 'Section 1 done: 8 dbo.qrs_* tables created.';
GO

-- ============================================================
-- SECTION 2: ROLES + REAL USERS (12 accounts)
-- ============================================================

INSERT INTO dbo.qrs_roles (name, description) VALUES
    ('Admin', 'Full access: create tasks, complete tasks, view reports.'),
    ('User',  'Complete assigned tasks and view reports.');
GO

-- full_name is NOT a qrs_users column -- always derived live from
-- dbo.lrn_master_list (see the OPEN ITEM at the top of this file). Names
-- below are kept in the VALUES tuple purely as a human-readable reference
-- for whose employee_id is whose, not inserted anywhere. Same for login
-- credentials -- not stored here, resolved via dbo.lrnph_users.
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

PRINT 'Section 2 done: 2 roles, 12 real users inserted.';
GO

-- ============================================================
-- SECTION 3: REAL LOCATION CATALOG (240 rows: 148 Monitoring, 92 Treatment)
-- ============================================================
-- Generated from sql/locations_monitoring.csv + sql/locations_treatment.csv
-- using the same normalization sql/import_real_locations.php uses
-- (whitespace collapsed, blank rows skipped, exact case-insensitive
-- duplicates skipped -- none were found in either file). qr_token values
-- are pre-generated random hex, baked in as literals so this file has no
-- external dependency on the CSVs or a PHP runtime at deploy time.
-- Attributed to whichever real (non-test) Admin account is inserted first
-- in Section 2 above (David, Ana Victoria Mendoza / 2024-40484).

DECLARE @adminId INT = (SELECT TOP 1 id FROM dbo.qrs_users WHERE employee_id = '2024-40484');

INSERT INTO dbo.qrs_locations (name, qr_token, created_by, location_type) VALUES
    ('PEST CONTROL MONITORING- ILT #1- G.O. Changing Area', '5ca62025ee499869', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #2- General Office', 'cf5d59b32fb76afb', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #3- General Office Lobby', 'aa702f36b10ad722', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #4- Phase 4 Unloading Bay', 'f4b76b317de7b4c0', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #5- Receiving Office', 'cd530c9a2884be3f', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #6- Canteen GF', '127b0d2282e7f9f8', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #7- Canteen GF', '0d4aced01cb2cca2', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #8- Canteen Kitchen', '8e7358e598e97e5c', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #9- Canteen 2F', 'd118d61365b7e221', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #10- Laundry Area', '8b6a705b3d00902a', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #11- Male CR', 'ff8b50a847242972', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #12- Female CR', '0bc15c34966507d2', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #13- Locker', '9f2924e210c77695', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #14- Engineering Workshop', 'effcb7d07b070d60', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #15- Phase 1 Hallway', 'ec5413ad96feaf6e', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #16- Phase 1 Hallway - Dough Mixing', 'e9eadb5f06d41866', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #17- Phase 1 Moulding Extension', '153aedb2ac5a5d56', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #18- Phase 1 Moulding Extension 2F', '205977d77c0423e4', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #19- Phase 1 Loading Bay', '48db202a73ba4147', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #20- Phase 1 Boxing Area', '9c86a78803ff83d5', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #21- Phase 1 Coating Area', '5d67b8ec09d62656', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #22- Phase 3 Blue Tray', '40d7ce59f65f983c', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #23- Phase 3 Cheesecake', '80f72e7ff04accc0', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #24- Phase 1 Utensils', 'e2fa21eae20824e9', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #25- Phase 3 Pasteurizer', 'db51765db124908a', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #26- Phase 3 Production', '2ca0e02909a5f7df', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #27- Phase 3 Kitchen', '4296a08fa3b46188', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #28- Phase 1 Ingredients', '8920661e3cff90e2', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #29- Phase 1 Stagging Area', 'ef421e95ebc4fd2a', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #30- Phase 1 Egg Room - Changing Area', 'b313d9c911fea475', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #31- Phase 1 Egg Room', '969485203c4fc7b5', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #32- Phase 2 Entrance', '483aab4603dc624f', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #33- Phase 2 Changing Room', 'f68d637156b89d0c', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #34- Phase 2 Hallway', '62aaaeaccf1b9f18', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #35- Phase 2 Hallway', '56cb7f961275456e', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #36- Phase 2 Hallway', '45475069df1099d2', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #37- FG Warehouse 2', '44f400f980b90332', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #38- Phase 2 Hallway', '1e1329f42f530fbc', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #39- Phase 2 Oven Area', '831ca8896cbc92b6', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #40- Phase 2 Production Line', '972cb41721d7c064', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #41- Foundation Hallway', '4def327ee9ff22a8', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #42- Foundation Changing Area', 'bf85f2c7402d9b37', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #43- Foundation Area', '780b15403e8a6dad', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #44- Passion Hallway', '158c4515770c297d', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #45- Passion Hallway', '42f6f92c58be6e8e', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #46- Training Center Extension', '6ef5365b577329b8', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #47- Utensils', 'bed39ffccca6b2c4', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #48- Phase 4 Hallway', '2cab7e7190bb54ef', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #49- Phase 4 Hallway', '3ad8990e071e896b', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #50- Phase 4 Changing Room', '2f781358ec687a00', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #51- Phase 4 Chocolate Production', '283627a442f1ef48', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #52- Phase 4 Utensils', '03a3852c4cec0510', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #53- Phase 4 Boxing Room', 'c5ecf650e3551c66', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #54- PM Warehouse', 'e9616cadb1b65db9', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- ILT #55- Unloading Bay', '823cc3e5e44688e4', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #1- Canteen Stock Room', 'f88852f21b8a6a88', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #2- Canteen Stock Room', '4fc0bf54507abebb', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #3- RM Receiving/Unloading', '80c320033c4f2dda', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #4- FG Warehouse', 'bddb827584a78ccf', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #5- FG Warehouse', '8289ce236a27026b', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #6- FG Warehouse', '98f3f73a95574192', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #7- FG Warehouse', '1f1ae8728bc6e4dd', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #8- FG Warehouse', '65ab757fb8904046', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #9- FG Warehouse', 'cc60a4ac7fb1cc6a', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #10- FG Warehouse', '14ffaedd7613370b', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #11- FG Warehouse', '69c00d722ac4340a', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #12- RM Warehouse', '0d10788140f27a47', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #13- RM Warehouse', 'a5ae1a770aa44df2', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #14- RM Warehouse', 'fa7fce8e5fa19f9a', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #15- RM Warehouse', 'cf73f3af171b23bc', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #16- RM Warehouse', 'ba089c796d4c4de6', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #17- RM Warehouse', '41c87c2421990b2f', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #18- PM Warehouse', '823a3177f1ab348d', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #19- PM Warehouse', '73b3c4ae0a1d7ed4', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #20- PM Warehouse', 'a64a405cb8876ffd', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #21- PM Warehouse', '85638391f2b74f1d', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #22- FG Warehouse 2', '94f9c9f30b72dc31', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #23- FG Warehouse 2', '8560f573f863c375', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #24- Document Room', 'd99835a96cbad035', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SGT #25- Document Room', 'acbacecbeb750872', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #1- Canteen Perimeter', '9295e22877d90f46', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #2- Canteen Perimeter', 'a37165201225f0ca', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #3- Canteen Perimeter', '9b7309b89cd7de14', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #4- Canteen Perimeter', '4aaf15ce2b4de861', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #5- Canteen Perimeter', '945c062db0c61813', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #6- Canteen Perimeter', '048ffb76578a9a29', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #7- Phase 1 Back Hallway', '8ec124993b393904', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #8- Phase 1 Back Hallway', 'f4a598e82ab81134', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #9- Phase 1 Back Hallway', '65578a1002caf04b', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #10- Phase 1 Back Hallway', '9760d43fc1ce10df', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #11- Phase 3 Back Hallway', '5f90331083394001', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #12- Phase 3 Back Hallway', '09b780cabc9604cd', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #13- Phase 3 Back Hallway', 'c92646832fdca7be', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #14- Phase 3 Back Hallway', '3b206e83bb558374', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #15- Loading Bay', 'ecfe5d5eaeb05ac3', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #16- Loading Bay', 'a7bff4c156b3433b', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #17- Loading Bay', '9616ce375b7f2ebc', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #18- Passion Garden', '641fff7fd26ce566', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #19- Passion Garden', 'eef4e6ef9a2bdcbc', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RBS #20- Passion Garden', 'c12bf958b36b31b8', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #1- Canteen Perimeter', '25424457e489a2bb', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #2- Canteen Perimeter', '0b092b4632e7f342', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #3- Back Hallway - Exit', '845e002ca85851a2', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #4- RM Receiving / Unloading', '1a79aceadc41aef9', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #5- Phase 1 Back Hallway', 'd8d69782b1a89504', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #6- Phase 1 Back Hallway', 'ec871f8c4df7c687', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #7- Phase 1 Back Hallway', '9f460ea45b6e621c', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #8- Phase 1 Back Hallway', '41688b5ff3908326', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #9- Phase 1 Back Hallway', '6ae0e87a3a58bc32', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #10- Phase 3 Back Hallway', '491ddde0a354bfa7', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #11- Passion Perimeter', '515d97c5cd7fdf65', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- RCT #12- Passion Perimeter', '273bdb1a5fda56ed', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- CT #1- Canteen Perimeter', 'b4d6696868a1d689', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- CT #2- Canteen Perimeter', '890cdae4daf9711c', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- CT #3- Canteen Perimeter', '9021821fa12cafd9', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- CT #4- Garbage Area', '42e6d8129c11b469', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #1- Female Comfort Room', 'ae93684a2db0686b', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #2- Female Comfort Room', 'ec4c9dc843bf4aa8', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #3- Male Comfort Room', '1c8e856ec4a9c6a9', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #4- Male Comfort Room', 'aa000ec0a585b7c4', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #5- Female Locker', 'bf7181b4c1e91c89', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #6- Female Locker', 'd99dec4abf8cd89f', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #7- Male Locker', '66a0821546e5ee1b', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #8- Male Locker', '8e2e748bc2861f2c', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #9- Canteen Kitchen', 'b9cf49f2a757e860', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #10- Canteen Kitchen', 'df9a4620318aaff0', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #11- Canteen Kitchen', 'ba91b23c35535d31', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #12- Canteen Kitchen', '08db5c4aaf96e603', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #13- Foundation', 'd82f5993a934bd48', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #14- Foundation', '1f979f52724fd0dc', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- TARDIS #15- Foundation', '93d9d3e73269d8ed', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #1- Material Warehouse', 'c334e82a8f8b8a2c', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #2- Material Warehouse', '0bb8c964b8d2467a', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #3- Material Warehouse', 'ac9810c9a4ef5cbb', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #4- Material Warehouse', '01ece3de2c423d87', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #5- Material Warehouse', 'b915c7cce85f1966', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #6- Material Warehouse', 'f86623270be352c5', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #7- Material Warehouse', 'b32bb71cbc76a27d', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #8- Material Warehouse', 'a24a5ea65ff197e8', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #9- Material Warehouse', '270ce453576568c0', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- SPP #10- Material Warehouse', '588ca616edeed60c', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- FBS #1- Canteen Perimeter', '75e4a1675bcffcac', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- FBS #2- Canteen Perimeter', '2ded4974ab439f40', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- FBS #3- Phase 1 Back Hallway', 'be01468b593b258b', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- FBS #4- Phase 1 Back Hallway', '20a78fd15060f3ef', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- FBS #5- Back Hallway - Exit', 'e9493e4a7d427e43', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- FBS #6- Garbage Area', '796ea253aa1c78c8', @adminId, 'Monitoring'),
    ('PEST CONTROL MONITORING- FBS #7- FGW Loading Bay', 'd2f7eef7f95de20e', @adminId, 'Monitoring'),
    ('PESTCON-Treatment, P1, Egg Room', '3c463d2c2f2f66a4', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Ingredients', '094979eaf80c56b9', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Flour Sieving Area', 'ae075f9ae4626142', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Hallway between Ingredients & Chiller Packaging', '27b1381b48cb9ecb', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Dough Mixing', 'fc4bc82733686df7', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Molding 1', 'f4ed4ac3804e7025', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Molding 2 - First Floor', 'c77eb8a78c747836', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Molding 2 - Second Floor', 'ea72d859274877a5', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Oven Area', 'b61c05a76dc8fb7e', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Spray Booths', 'c1b2a8e246108a87', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Coating', 'ba20625da4791342', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Air shower (Coating Area)', 'f16608883abd769e', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, UV', 'b28b5f7074b8dfab', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Boxing Room 1 - Storage', '23a0212cc5fbdacf', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Outer Packaging', '40c1b91cf20374d0', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Outer Packaging Hallway', 'dd7b407e7d348adb', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Cone Machine (old)', '248611b184249b90', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Cone Machine (new)', 'bb9e5f4415200f5f', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Changing Room PR1', 'b44990e792f99ea1', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P1, Utensils', '60469d33c6323d96', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Bread Production Line', '5f6e5f6bf9e4a793', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Egg Spray', '21cbcceb1574d4ee', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Oven area', 'a4c64ca48e6c5547', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Proofer', 'a1d23e0f9a784fa5', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Holding Area', '09eed8a3a1aedfc4', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Blast freezer 1', '3be64bd7e0374e11', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Blast freezer 2', '807a8df2e411035a', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Inner Packaging', '5ce7d6725ebabaf2', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Outer Packaging', '34eaadbedb77b398', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, hallways', '824bf0b3ffc573d5', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Ingredients Room', '6b5612db4136e63f', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Dough Mixing', '0c08d356b0dbbf9e', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Ice machines Area', '3806243f37933890', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Changing Room', '9b8c3b610f6116d3', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P2, Utensils', '26e8e1f0775bd5c9', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P3, Kitchen', 'cc36c9043e2b1976', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P3, Pasteurizer', 'c6ac65f3290e26dd', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P3, Cheesecake', '25ff969ea0fa754a', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P3, Assembly 1', '775895b6d3dd17df', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P3, Assembly 1 Extension', 'd503b4658c0e4630', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P3, Hallway', '4a4f744c9954c58a', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P3, Chiller Packaging', '4271dc0ee9cc68cf', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Cargo Lifter', '8e8023743bf32694', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Cargo Lifter Anteroom', '412f560a74729967', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Cargo Lifter Hallway', '3d2b7dbfcf0b15b7', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Boxing Area', 'd929dd4e08a6fc19', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Packing area', 'cf37d8eeaf478724', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Chocolate Production Line (Main Room)', '07247c9a311143a4', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Changing Room', '8183bd98121afe01', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Ganache', 'cfb27e11351ba12b', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Utensils Area', 'c4cf565e86b9c549', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Chocolate Production Line (Big Room)', '603386c3824f0f3d', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Hallway 1', 'f2812c8cb5742efb', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, Hallway 2', '4dc54c74e1f90653', @adminId, 'Treatment'),
    ('PESTCON-Treatment, P4, TV Room', 'e4440440d91c4a82', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GF, Gluten Free (Big Area)', 'cfe75d3fe1ce0141', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GF, GF molding Area (Room 2)', 'e5f1ee46926f958b', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GF, GF Utensils', '311425e8161b59a1', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GF, GF Baking/Oven Area', 'e996eef20c08ffd1', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GF, Hallway to Changing Room GF', '28724df56e63eb2a', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GF, Gelato (Room 1)', '2a13e0fbdd3eca1b', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Archive', 'c10128016a75d74e', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Laboratory', 'a513c02881739407', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Foundation', '6fe56f3dc88a408a', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Passion', 'c69a12de3b9e4668', @adminId, 'Treatment'),
    ('PESTCON-Treatment, RM Warehouse', '7d1b0649f3a048c7', @adminId, 'Treatment'),
    ('PESTCON-Treatment, PM Warehouse', '796859f7ee08d3e4', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Back Hallway', 'ec8132eb2ff19e00', @adminId, 'Treatment'),
    ('PESTCON-Treatment, FG Warehouse Loading Bay', '6fe128e9ac049ddc', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Receiving Office', '00f352082112ff40', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Receiving/Unloading Bay', '2c6387602783f891', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Canteen', '1f021c80e2c27825', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Canteen Kitchen', '0e1258aec0831857', @adminId, 'Treatment'),
    ('PESTCON-Treatment, First Class Lounge', 'd62ba00022023255', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Document Room', '75fa4801441208ad', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Clinic', 'ae21f8bb815718d8', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Laundry', '8b777dc87738b54a', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Male Locker', 'abf289eb9541d392', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Female Locker', 'e3027f484953319a', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Male CR/Changing Room', 'fc9ef9fa8bedeb4b', @adminId, 'Treatment'),
    ('PESTCON-Treatment, Female CR/Changing Room', '0a7c09cdb65f619c', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO - Execs Office', '9f2faa9868fb881e', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO - HR Lobby', 'bc34a428a064ac67', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO Lobby', '8c67aa62eea6b559', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO Meeting Room', '5681151cf89cb0a1', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO Male CR', '9edcc40d388a61bf', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO Female CR', '1550d0e5b9b42c98', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO Pantry', '1db581a29510598d', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO Labels Office', 'a200a9c1accc8ca6', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO Finance', '517246c5bc4e51b6', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO Proper (Employees Area)', 'ae7f8834ffaa44f3', @adminId, 'Treatment'),
    ('PESTCON-Treatment, GO VIP Entrance', '9e6f85e1ccffe9ee', @adminId, 'Treatment');
GO

PRINT 'Section 3 done: 240 real locations inserted (148 Monitoring, 92 Treatment).';
GO

PRINT '============================================================';
PRINT 'QRS v2 deployment complete: 8 tables, 2 roles, 12 users, 240 locations.';
PRINT 'Remember: see the OPEN ITEM at the top of this file before anyone';
PRINT 'tries to log in -- login depends on lrn_master_list/lrnph_users';
PRINT 'being reachable from this database too.';
PRINT '============================================================';
GO
