(function () {
    'use strict';

    const timers = new Map();
    const expiring = new Set();

    function formatRemaining(totalSeconds) {
        const seconds = Math.max(0, Math.ceil(totalSeconds));
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        return [hours, minutes, secs].map(value => String(value).padStart(2, '0')).join(':');
    }

    function register(root) {
        const elements = [];
        if (root.matches && root.matches('[data-expiration-countdown]')) elements.push(root);
        if (root.querySelectorAll) elements.push(...root.querySelectorAll('[data-expiration-countdown]'));

        elements.forEach(element => {
            if (element.dataset.countdownReady === '1') return;
            const id = Number(element.dataset.taskLocationId);
            const remaining = Number(element.dataset.remainingSeconds);
            if (!id || !Number.isFinite(remaining)) return;

            element.dataset.countdownReady = '1';
            timers.set(element, {
                id,
                deadline: Date.now() + Math.max(0, remaining) * 1000,
            });
        });
    }

    function markMissed(id, taskId) {
        document.querySelectorAll(`[data-expiration-countdown][data-task-location-id="${id}"]`).forEach(element => {
            element.textContent = 'Missed';
            element.classList.add('is-expired');
            timers.delete(element);

            const locationItem = element.closest('.scan-location-list-item');
            if (locationItem) {
                locationItem.disabled = true;
                locationItem.removeAttribute('onclick');
                locationItem.classList.remove('pending', 'current', 'active');
                locationItem.classList.add('missed');
                const status = locationItem.querySelector('.scan-location-list-status');
                if (status) {
                    status.textContent = 'Missed';
                    status.className = 'scan-location-list-status missed';
                }
                locationItem.querySelector('.scan-location-list-chevron')?.remove();
            }

            const locationTag = element.closest('.location-tag');
            if (locationTag) {
                locationTag.classList.add('is-missed');
                locationTag.querySelector('button')?.remove();
            }
        });

        if (taskId) {
            const taskRow = document.querySelector(`#tasksTable tr[data-task-id="${taskId}"]`);
            if (taskRow) {
                taskRow.dataset.missed = 'Yes';
                taskRow.dataset.status = 'Missed Out';
                const badge = taskRow.querySelector('td:nth-last-child(2) .status-badge');
                if (badge) {
                    badge.className = 'status-badge status-missed';
                    badge.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Missed Out';
                }
            }
        }

        document.dispatchEvent(new CustomEvent('qrs:location-expired', {
            detail: { taskLocationId: id, taskId },
        }));
    }

    async function requestExpiration(ids) {
        ids.forEach(id => expiring.add(id));
        const formData = new FormData();
        formData.set('csrf_token', QRS_CSRF_TOKEN);
        ids.forEach(id => formData.append('task_location_ids[]', id));

        try {
            const response = await fetch('../api/task_locations/expire.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Expiration failed.');

            const confirmed = new Set();
            (data.expired || []).forEach(item => {
                confirmed.add(Number(item.task_location_id));
                markMissed(Number(item.task_location_id), Number(item.task_id));
            });

            // The display uses whole seconds while SQL Server retains
            // fractions. If the request lands a fraction early, retry soon.
            ids.filter(id => !confirmed.has(id)).forEach(id => {
                timers.forEach(timer => {
                    if (timer.id === id) timer.deadline = Date.now() + 1500;
                });
            });
        } catch (error) {
            ids.forEach(id => {
                timers.forEach(timer => {
                    if (timer.id === id) timer.deadline = Date.now() + 5000;
                });
            });
            console.error(error);
        } finally {
            ids.forEach(id => expiring.delete(id));
        }
    }

    function tick() {
        const due = new Set();
        timers.forEach((timer, element) => {
            if (!element.isConnected) {
                timers.delete(element);
                return;
            }

            const remaining = (timer.deadline - Date.now()) / 1000;
            element.textContent = formatRemaining(remaining);
            element.setAttribute('aria-label', `${Math.max(0, Math.ceil(remaining))} seconds until this location expires`);
            if (remaining <= 0 && !expiring.has(timer.id)) due.add(timer.id);
        });

        if (due.size) requestExpiration(Array.from(due));
    }

    document.addEventListener('DOMContentLoaded', () => {
        register(document);
        tick();
        window.setInterval(tick, 1000);
        new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(register)))
            .observe(document.body, { childList: true, subtree: true });
    });
})();
