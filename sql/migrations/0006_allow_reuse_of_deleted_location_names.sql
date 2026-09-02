SET XACT_ABORT ON;
BEGIN TRANSACTION;

-- The old UNIQUE constraint included soft-deleted rows, preventing a new
-- location from reusing their names. Active names remain unique.
IF EXISTS (
    SELECT 1
    FROM sys.key_constraints
    WHERE name = 'UQ_qrs_locations_name'
      AND parent_object_id = OBJECT_ID('dbo.qrs_locations')
)
BEGIN
    ALTER TABLE dbo.qrs_locations DROP CONSTRAINT UQ_qrs_locations_name;
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_qrs_locations_active_name'
      AND object_id = OBJECT_ID('dbo.qrs_locations')
)
BEGIN
    CREATE UNIQUE INDEX UX_qrs_locations_active_name
    ON dbo.qrs_locations(name)
    WHERE deleted_at IS NULL;
END;

COMMIT TRANSACTION;
