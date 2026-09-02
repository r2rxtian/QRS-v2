SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID('dbo.qrs_maintenance_state', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.qrs_maintenance_state (
        job_name    VARCHAR(80) NOT NULL PRIMARY KEY,
        last_run_at DATETIME2   NULL
    );
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.qrs_maintenance_state
    WHERE job_name = 'resolve_task_locations'
)
BEGIN
    INSERT INTO dbo.qrs_maintenance_state (job_name, last_run_at)
    VALUES ('resolve_task_locations', NULL);
END;

COMMIT TRANSACTION;
