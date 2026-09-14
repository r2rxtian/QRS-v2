// qradmin.js — Pagination and sorting for all tasks list

function armAllTasksScheduleWake() {
    if (window.__qrsAllTasksScheduleWakeTimer) {
        clearTimeout(window.__qrsAllTasksScheduleWakeTimer);
        window.__qrsAllTasksScheduleWakeTimer = null;
    }
    const tableBody = document.querySelector('#allTasksTable tbody[data-scheduled-wake-seconds]');
    const seconds = Number(tableBody?.dataset.scheduledWakeSeconds || 0);
    if (!tableBody || !Number.isFinite(seconds) || seconds <= 0) return;
    window.__qrsAllTasksScheduleWakeTimer = setTimeout(() => {
        tableBody.dataset.scheduledWakeSeconds = '0';
        window.__qrsAllTasksScheduleWakeTimer = null;
        refreshAllTasksView().catch(() => {});
    }, Math.min((seconds * 1000) + 250, 2147000000));
}

async function refreshAllTasksView() {
    const response = await fetch(window.location.href, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!response.ok) throw new Error('Could not refresh the task list.');

    const incoming = new DOMParser().parseFromString(await response.text(), 'text/html');
    const currentStats = document.querySelector('.stat-tiles');
    const nextStats = incoming.querySelector('.stat-tiles');
    if (currentStats && nextStats) currentStats.replaceWith(nextStats);

    const currentBody = document.querySelector('#allTasksTable tbody');
    const nextBody = incoming.querySelector('#allTasksTable tbody');
    if (!currentBody || !nextBody) throw new Error('The refreshed task table was not available.');
    nextBody.dataset.skipMountAnimation = 'true';
    currentBody.replaceWith(nextBody);
    window.__pagers?.allTasksTable?.refresh({ preservePage: true });
    armAllTasksScheduleWake();
}

document.addEventListener('qrs:realtime-synced', armAllTasksScheduleWake);

document.addEventListener('DOMContentLoaded', function() {
    // 10, matching the same "Show entries" default used everywhere else
    // this selector appears (Task Manager, Manage Locations, Task Report).
    const allTasksPager = paginateTable({ tableId: 'allTasksTable', paginationId: 'allTasksPagination', rowsPerPage: 10 });
    makeSortable('allTasksTable', allTasksPager);
    armAllTasksScheduleWake();

    window.onAllTasksEntriesChange = function(value) {
        allTasksPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
    };
});
