// task-detail-modal.js — shared "view/manage a task's location status"
// popup, available on every page that includes appshell_end.php.
// Replaces the old dedicated task_locations.php page.

function zoomPhoto(img) {
    const zoomedPhoto = document.createElement('div');
    zoomedPhoto.classList.add('zoomed-photo');
    zoomedPhoto.innerHTML = '<button type="button" class="zoomed-photo-close" aria-label="Close"><i class="fas fa-times"></i></button><img src="' + img.src + '">';
    document.body.appendChild(zoomedPhoto);
    zoomedPhoto.querySelector('.zoomed-photo-close').onclick = function () { document.body.removeChild(zoomedPhoto); };
    zoomedPhoto.style.display = 'flex';
}

function showTaskDetailMessage(text, type) {
    const body = document.getElementById('taskDetailBody');
    if (!body) return;
    const existing = body.querySelector('.detail-inline-message');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.className = 'detail-inline-message ' + (type === 'error' ? 'error-message' : 'success-message');
    el.style.marginBottom = '16px';
    el.style.padding = '10px 14px';
    el.style.borderRadius = '10px';
    el.style.fontSize = '13.5px';
    el.style.fontWeight = '500';
    if (type === 'error') {
        el.style.background = '#FEE2E2';
        el.style.color = '#991B1B';
    } else {
        el.style.background = '#D1FAE5';
        el.style.color = '#065F46';
    }
    el.textContent = text;
    body.prepend(el);
}

async function openTaskDetailModal(taskId, taskName) {
    const modal = document.getElementById('taskDetailModal');
    const body = document.getElementById('taskDetailBody');
    const taskNameEl = document.getElementById('taskDetailTaskName');

    modal.dataset.taskId = taskId;
    taskNameEl.textContent = taskName || '';
    body.innerHTML = '<div style="text-align:center; padding: 40px; color: var(--gray-500);">Loading…</div>';
    modal.classList.add('active');

    await refreshTaskDetailModal();
}

function armTaskDetailScheduleWake() {
    if (window.__qrsTaskDetailScheduleWakeTimer) {
        clearTimeout(window.__qrsTaskDetailScheduleWakeTimer);
        window.__qrsTaskDetailScheduleWakeTimer = null;
    }
    const wake = document.getElementById('taskDetailScheduleWake');
    const seconds = Number(wake?.dataset.scheduledStartSeconds || 0);
    if (!wake || !Number.isFinite(seconds) || seconds <= 0) return;
    window.__qrsTaskDetailScheduleWakeTimer = setTimeout(async () => {
        window.__qrsTaskDetailScheduleWakeTimer = null;
        try { await refreshTaskDetailModal(); } catch (_) {}
    }, Math.min((seconds * 1000) + 250, 2147000000));
}

function closeTaskDetailModal() {
    document.getElementById('taskDetailModal').classList.remove('active');
    if (window.__qrsTaskListNeedsRefresh) {
        window.__qrsTaskListNeedsRefresh = false;
        window.QRSRealtime?.refresh();
    }
}

async function refreshTaskDetailModal() {
    const modal = document.getElementById('taskDetailModal');
    const body = document.getElementById('taskDetailBody');
    const taskId = modal.dataset.taskId;
    if (!taskId) return;

    try {
        const response = await fetch('../api/tasks/detail_partial.php?task_id=' + encodeURIComponent(taskId));
        body.innerHTML = await response.text();
        armTaskDetailScheduleWake();
    } catch (err) {
        body.innerHTML = '<p style="color: var(--danger);">Could not load task details. Please try again.</p>';
    }
}

async function unassignLocationInModal(button) {
    const modal = document.getElementById('taskDetailModal');
    const taskId = modal.dataset.taskId;
    const tag = button.closest('.location-tag');
    const locationId = tag.dataset.locationId;

    button.disabled = true;
    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('task_id', taskId);
    formData.set('location_id', locationId);

    try {
        const response = await fetch('../api/task_locations/unassign.php', { method: 'POST', body: formData });
        let data = null;
        try {
            data = await response.json();
        } catch (_) {}

        if (!response.ok || !data) {
            throw new Error(data?.message || 'Server error (' + response.status + '). Please try again.');
        }

        await refreshTaskDetailModal();
        showTaskDetailMessage(data.message, data.type || (data.success ? 'success' : 'error'));
        window.__qrsTaskListNeedsRefresh = true;
    } catch (err) {
        button.disabled = false;
        showTaskDetailMessage(err.message || 'Could not reach the server. Please try again.', 'error');
    }
}

async function submitAssignLocationsInModal(submitButton) {
    const modal = document.getElementById('taskDetailModal');
    const taskId = modal.dataset.taskId;
    const list = document.getElementById('modalLocationSelect');
    if (!list) return;

    const selectedIds = Array.from(list.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    if (!selectedIds.length) {
        showTaskDetailMessage('Please select at least one location to assign.', 'error');
        return;
    }

    const btn = submitButton || modal.querySelector('.checklist-footer button');
    if (btn) btn.disabled = true;

    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('task_id', taskId);
    selectedIds.forEach(id => formData.append('location_ids[]', id));

    try {
        const response = await fetch('../api/task_locations/assign.php', { method: 'POST', body: formData });
        let data = null;
        try {
            data = await response.json();
        } catch (_) {}

        if (!response.ok || !data) {
            throw new Error(data?.message || 'Server error (' + response.status + '). Please try again.');
        }

        await refreshTaskDetailModal();
        showTaskDetailMessage(data.message, data.type || (data.success ? 'success' : 'error'));

        // The modal updates immediately; the underlying list reconciles when
        // this modal closes (and other clients receive the same change by SSE).
        window.__qrsTaskListNeedsRefresh = true;
    } catch (err) {
        if (btn) btn.disabled = false;
        showTaskDetailMessage(err.message || 'Could not reach the server. Please try again.', 'error');
    }
}

// Deliberately no click-outside-to-close here -- this modal can hold an
// in-progress "assign more locations" selection, and an accidental click
// on the backdrop shouldn't be able to lose it. Same reasoning as the
// photo zoom overlay (scripts/task_report.js / scripts/task-detail-modal.js
// zoomPhoto()): closing is via the X button (closeTaskDetailModal()) only.
