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
            let anyRegionChanged = false;
            let tableChanged = false;

            document.querySelectorAll('[data-realtime-region]').forEach(current => {
                const key = current.dataset.realtimeRegion;
                const next = incoming.querySelector(`[data-realtime-region="${CSS.escape(key)}"]`);
                if (!next) return;

                // Deduplicate: if the content has not changed, do not mutate the DOM
                // Region attributes can carry live state too (for example,
                // seconds until the next exact schedule). Do not discard an
                // attribute-only update as if the region were unchanged.
                if (current.outerHTML.trim() === next.outerHTML.trim()) {
                    return;
                }

                anyRegionChanged = true;

                if (next.tagName === 'TBODY') {
                    tableChanged = true;
                    next.dataset.skipMountAnimation = 'true';

                    // Pre-sync visibility/filter states onto incoming rows by ID before mounting
                    // to prevent layout thrashing and the flash of unpaginated rows
                    const table = current.closest('table');
                    const pager = table ? window.__pagers?.[table.id] : null;
                    if (pager && current.rows.length > 0) {
                        const currentStates = new Map();
                        Array.from(current.rows).forEach(r => {
                            const rowId = r.dataset.taskId || r.dataset.id || r.dataset.locationId || r.dataset.userId;
                            if (rowId) {
                                currentStates.set(rowId, {
                                    display: r.style.display,
                                    filterHidden: r.classList.contains('filter-hidden')
                                });
                            }
                        });

                        Array.from(next.rows).forEach(r => {
                            const rowId = r.dataset.taskId || r.dataset.id || r.dataset.locationId || r.dataset.userId;
                            const prev = rowId ? currentStates.get(rowId) : null;
                            if (prev) {
                                r.style.display = prev.display;
                                if (prev.filterHidden) r.classList.add('filter-hidden');
                            }
                        });
                    }
                }

                current.replaceWith(next);
            });

            if (tableChanged) {
                restoreTableState();
            }

            const detailModal = document.getElementById('taskDetailModal');
            if (detailModal?.classList.contains('active') && typeof refreshTaskDetailModal === 'function') {
                await refreshTaskDetailModal();
            }

            const range = document.getElementById('barRangeSelect');
            if (range && typeof loadBarChart === 'function') loadBarChart(range.value);

            if (anyRegionChanged) {
                document.dispatchEvent(new CustomEvent('qrs:realtime-synced'));
            }
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
        debounceTimer = setTimeout(refreshLiveRegions, 300);
    }

    window.QRSRealtime = {
        refresh: refreshLiveRegions,
        schedule: scheduleRefresh,
    };

    if (!window.EventSource) return;
    let cursor = Number(window.QRS_REALTIME_CURSOR || 0);
    const url = '../api/realtime/events.php' + (cursor > 0 ? '?cursor=' + encodeURIComponent(cursor) : '');
    const source = new EventSource(url);
    source.addEventListener('sync', event => {
        try {
            const data = JSON.parse(event.data);
            if (data.cursor && Number(data.cursor) > cursor) {
                cursor = Number(data.cursor);
                window.QRS_REALTIME_CURSOR = cursor;
            }
            scheduleRefresh(data.scopes);
        } catch (error) {
            scheduleRefresh();
        }
    });
})();
