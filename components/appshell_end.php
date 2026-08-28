<?php
/**
 * Closes the app-shell chrome opened by appshell_start.php.
 * Also includes the shared Task Detail popup (view/manage a task's
 * location status without navigating to a separate page) so any
 * appshell page can call openTaskDetailModal(taskId).
 */
require_once __DIR__ . '/../auth/csrf.php';
?>
    </main>
</div>

<!-- Shared Task Detail Modal -->
<div id="taskDetailModal" class="modal-overlay">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="taskDetailTitle">Task Details</h3>
                <p class="modal-subtitle">Task: <strong id="taskDetailTaskName"></strong></p>
            </div>
            <button type="button" class="modal-close" onclick="closeTaskDetailModal()">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="modal-body" id="taskDetailBody">
            <div style="text-align:center; padding: 40px; color: var(--gray-500);">Loading…</div>
        </div>
    </div>
</div>

<script>const QRS_CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;</script>
<script src="../scripts/motion.js"></script>
<script src="../scripts/session-guard.js"></script>
<script src="../scripts/task-detail-modal.js"></script>
<script src="../scripts/location-search.js"></script>
<script src="../scripts/logout-confirm.js"></script>
