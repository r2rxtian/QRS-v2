(function () {
    'use strict';

    let refreshPromise = null;
    let refreshQueued = false;
    let debounceTimer = null;

    function currentModule() {
        const page = location.pathname.split('/').pop();
        return ({
            'dashboard.php': 'dashboard',
            'tasks.php': 'tasks',
            'qradmin.php': 'tasks',
            'manage_locations.php': 'locations',
            'task_report.php': 'reports',
            'scan.php': 'scan',
            'user_management.php': 'users',
            'audit_logs.php': 'audit',
        })[page] || 'all';
    }

    function shouldHandle(scopes) {
        const module = currentModule();
        if (!Array.isArray(scopes)) return true;
        if (module === 'audit') return scopes.includes('audit') || scopes.includes('all');
        return scopes.includes('all') || scopes.includes(module);
    }

    function restoreTableState() {
        document.querySelectorAll('table[id]').forEach(table => {
            // Filtering already refreshes the registered paginator. Calling
            // both paths mounted the same replacement tbody twice.
            if (typeof applyTableFilters === 'function') {
                applyTableFilters(table.id, { preservePage: true });
            } else {
                window.__pagers?.[table.id]?.refresh({ preservePage: true });
            }
        });
    }

    async function refreshLiveRegions() {
        if (refreshPromise) {
            refreshQueued = true;
            return refreshPromise;
        }

        refreshPromise = (async () => {
            const response = await fetch(location.href, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error('Real-time refresh failed.');

            const incoming = new DOMParser().parseFromString(await response.text(), 'text/html');
            document.querySelectorAll('[data-realtime-region]').forEach(current => {
                const key = current.dataset.realtimeRegion;
                const next = incoming.querySelector(`[data-realtime-region="${CSS.escape(key)}"]`);
                if (next) {
                    // Background synchronization is not a user navigation or
                    // component entrance; do not replay table mount motion.
                    if (next.tagName === 'TBODY') next.dataset.skipMountAnimation = 'true';
                    current.replaceWith(next);
                }
            });

            restoreTableState();

            const detailModal = document.getElementById('taskDetailModal');
            if (detailModal?.classList.contains('active') && typeof refreshTaskDetailModal === 'function') {
                await refreshTaskDetailModal();
            }

            const range = document.getElementById('barRangeSelect');
            if (range && typeof loadBarChart === 'function') loadBarChart(range.value);

            document.dispatchEvent(new CustomEvent('qrs:realtime-synced'));
        })().catch(error => {
            console.error(error);
        }).finally(() => {
            refreshPromise = null;
            if (refreshQueued) {
                refreshQueued = false;
                refreshLiveRegions();
            }
        });

        return refreshPromise;
    }

    function scheduleRefresh(scopes) {
        if (!shouldHandle(scopes)) return;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(refreshLiveRegions, 200);
    }

    window.QRSRealtime = {
        refresh: refreshLiveRegions,
        schedule: scheduleRefresh,
    };

    if (!window.EventSource) return;
    const cursor = Number(window.QRS_REALTIME_CURSOR || 0);
    const source = new EventSource('../api/realtime/events.php?cursor=' + encodeURIComponent(cursor));
    source.addEventListener('sync', event => {
        try {
            scheduleRefresh(JSON.parse(event.data).scopes);
        } catch (error) {
            scheduleRefresh();
        }
    });
})();
