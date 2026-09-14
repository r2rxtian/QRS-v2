 --run new query in LRNPH_OJT
 --This SQL script is a safe, idempotent database migration
 --adds support for custom scheduled date/times to task locations in SQL Server.
 SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF COL_LENGTH('dbo.qrs_task_locations', 'scheduled_at') IS NULL
BEGIN
    ALTER TABLE dbo.qrs_task_locations
        ADD scheduled_at DATETIME2 NULL;
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'IX_qrs_tl_task_scheduled_at'
      AND object_id = OBJECT_ID('dbo.qrs_task_locations')
)
BEGIN
    CREATE INDEX IX_qrs_tl_task_scheduled_at
        ON dbo.qrs_task_locations(task_id, scheduled_at);
END;

COMMIT TRANSACTION;