/*
 * QRS v2 — schema (T-SQL / SQL Server), current as of the System
 * Requirements Specification alignment (task_type/location_type, the
 * Observation/Recommendation checklist, Admin/User roles). Tables live in
 * the "dbo" schema of the shared LRNPH_OJT database, all prefixed "qrs_"
 * (e.g. dbo.qrs_tasks) to avoid colliding with that database's existing
 * tables (acd_*, AP_*, activity_logs, blog_permissions, etc.). Connects as
 * the "acdavid" login (see conn/db.php) — no separate setup/login script
 * needed.
 *
 * This file is the up-to-date baseline for a from-scratch rebuild; it is
 * NOT run against the live database (which already has this exact
 * structure — it got there incrementally via sql/migrations/, since
 * dropped once folded in here).
 *
 * Notes on type translation:
 *   - IDENTITY(1,1) instead of AUTO_INCREMENT
 *   - No native ENUM type: VARCHAR + CHECK constraint instead
 *   - BIT instead of TINYINT(1) for booleans
 *   - DATETIME2 instead of DATETIME; SYSDATETIME() instead of NOW()
 *   - No "ON UPDATE CURRENT_TIMESTAMP": updated_at is set explicitly by the
 *     app on every UPDATE, not a DB trigger.
 *   - NVARCHAR(MAX) instead of JSON (SQL Server has JSON *functions* but no
 *     native JSON column type)
 *   - Status/derived values (qrs_task_locations.status, task status
 *     generally) are recomputed by the application on every write, never
 *     left for a trigger to maintain.
 *
 * No FOREIGN KEY constraints (matches this company's existing convention,
 * e.g. the other LRNPH_OJT tables). Referential integrity between
 * role_id/task_id/location_id/*_by columns and their parent tables is
 * enforced entirely at the application layer (see authz/authz.php and the
 * api/ endpoints) rather than by the database. Primary key, UNIQUE, and
 * CHECK constraints are still used.
 *
 * No department concept -- this app is used by a single QA team (access is
 * based on each user's own biometrics/employee_id, not a department), so
 * there is no qrs_departments table and no department_id column anywhere.
 */

USE LRNPH_OJT;
GO

-- ============================================================
-- dbo.qrs_roles — fixed set of 5 rows, seeded by seed.sql
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
                                                            -- password is stored in this table (see
                                                            -- sql/migrations/0003 and 0004)
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
    findings_observation  VARCHAR(MAX)   NULL,       -- captured at scan-start, optional (renamed from present_state_remark)
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
    CONSTRAINT CK_qrs_tl_status CHECK (status IN ('pending','in_progress','completed','missed'))
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

PRINT 'dbo.qrs_* tables created. Next: run seed.sql.';
