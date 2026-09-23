<?php
/**
 * Closes the app-shell chrome opened by appshell_start.php.
 * Also includes the shared Task Detail popup (view/manage a task's
 * location status without navigating to a separate page) so any
 * appshell page can call openTaskDetailModal(taskId).
 */
require_once __DIR__ . '/../auth/csrf.php';
$realtimeCursor = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM ' . T_AUDIT_LOG)->fetchColumn();
?>
    </main>
</div>

<!-- Shared Task Detail Modal -->
<div id="taskDetailModal" class="modal-overlay">
    <div class="modal modal-lg task-detail-dialog">
        <div class="modal-header">
            <div class="modal-header-row">
                <div class="modal-header-icon task-detail-modal-icon">
                    <i class="fas fa-map-location-dot"></i>
                </div>
                <div>
                    <h3 class="modal-title" id="taskDetailTitle">Task Overview & Management</h3>
                    <p class="modal-subtitle">Task: <strong id="taskDetailTaskName"></strong></p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeTaskDetailModal()" aria-label="Close modal">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="modal-body" id="taskDetailBody">
            <div style="text-align:center; padding: 40px; color: var(--gray-500);">Loading…</div>
        </div>
    </div>
</div>

<script>
window.QRS_CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
window.QRS_REALTIME_CURSOR = <?= (int) $realtimeCursor ?>;
var QRS_CSRF_TOKEN = window.QRS_CSRF_TOKEN;
var QRS_REALTIME_CURSOR = window.QRS_REALTIME_CURSOR;
window.QRS_SESSION_CONFIG = <?= json_encode([
    'idleTimeoutMs' => SESSION_IDLE_TIMEOUT_MINUTES * 60 * 1000,
    'heartbeatIntervalMs' => SESSION_TOKEN_REFRESH_MINUTES * 60 * 1000,
    'warningBeforeMs' => 60 * 1000,
]) ?>;
</script>
<script src="../scripts/toast.js?v=2"></script>
<script src="../scripts/motion.js?v=4"></script>
<script src="../scripts/session-guard.js"></script>
<script src="../scripts/realtime-sync.js?v=5"></script>
<script src="../scripts/task-countdowns.js"></script>
<script src="../scripts/task-detail-modal.js?v=4"></script>
<script src="../scripts/location-search.js"></script>
<script src="../scripts/logout-confirm.js"></script>
