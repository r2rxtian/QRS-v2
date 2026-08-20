<?php
require_once __DIR__ . '/../conn/db.php';
require_once __DIR__ . '/../rules/constants.php';

/**
 * Writes one row to the audit log. $details is JSON-encoded with the HEX_*
 * flags so it's safe even if later reflected into an inline <script> block
 * anywhere (defense in depth — output should still be escaped at render time).
 */
function writeAuditLog(?int $userId, string $action, ?string $entityType, ?int $entityId, array $details = []): void
{
    $stmt = db()->prepare('
        INSERT INTO ' . T_AUDIT_LOG . ' (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        json_encode($details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}
