SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_qrs_tl_status'
      AND parent_object_id = OBJECT_ID('dbo.qrs_task_locations')
)
BEGIN
    ALTER TABLE dbo.qrs_task_locations DROP CONSTRAINT CK_qrs_tl_status;
END;

ALTER TABLE dbo.qrs_task_locations
ADD CONSTRAINT CK_qrs_tl_status
CHECK (status IN ('pending', 'in_progress', 'completed', 'missed'));

COMMIT TRANSACTION;
