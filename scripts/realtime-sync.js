(function () {
    'use strict';

    let refreshPromise = null;
    let refreshQueued = false;
    let debounceTimer = null;
    const UPDATE_FLASH_CLASS = 'qrs-update-flash';

    function stableMarkup(element) {
        const clone = element.cloneNode(true);
        clone.classList?.remove(UPDATE_FLASH_CLASS);
        clone.querySelectorAll?.('.' + UPDATE_FLASH_CLASS).forEach(node => node.classList.remove(UPDATE_FLASH_CLASS));
        clone.querySelectorAll?.('[data-expiration-countdown]').forEach(countdown => {
            countdown.textContent = '';
            countdown.removeAttribute('data-countdown-ready');
            countdown.removeAttribute('data-remaining-seconds');
            countdown.removeAttribute('aria-label');
        });
        return clone.outerHTML.trim();
    }

    function rowKey(row, index) {
        return row.dataset.taskId
            || row.dataset.id
            || row.dataset.locationId
            || row.dataset.userId
            || 'row-index-' + index;
    }

    function prepareUpdateHighlights(current, next) {
        const highlighted = [];

        if (next.tagName === 'TBODY') {
            const currentRows = new Map(Array.from(current.rows).map((row, index) => [rowKey(row, index), stableMarkup(row)]));
            Array.from(next.rows).forEach((row, index) => {
                const previousMarkup = currentRows.get(rowKey(row, index));
                if (previousMarkup === undefined || previousMarkup !== stableMarkup(row)) {
                    row.classList.add(UPDATE_FLASH_CLASS);
                    highlighted.push(row);
                }
            });
        } else if (next.classList.contains('stat-tiles')) {
            const currentTiles = Array.from(current.querySelectorAll('.stat-tile'));
            Array.from(next.querySelectorAll('.stat-tile')).forEach((tile, index) => {
                if (!currentTiles[index] || stableMarkup(currentTiles[index]) !== stableMarkup(tile)) {
                    tile.classList.add(UPDATE_FLASH_CLASS);
                    highlighted.push(tile);
                }
            });
        }

        return highlighted;
    }

    function clearUpdateHighlights(elements) {
        window.setTimeout(() => {
            elements.forEach(element => element.classList.remove(UPDATE_FLASH_CLASS));
        }, 1250);
    }

    function replaceRegion(current, next) {
        const highlighted = prepareUpdateHighlights(current, next);
        current.replaceWith(next);
        clearUpdateHighlights(highlighted);
        return next;
    }

    function flashElement(element) {
        if (!element || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        element.classList.remove(UPDATE_FLASH_CLASS);
        void element.offsetWidth;
        element.classList.add(UPDATE_FLASH_CLASS);
        clearUpdateHighlights([element]);
    }

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
                if (stableMarkup(current) === stableMarkup(next)) {
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

                replaceRegion(current, next);
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
        replaceRegion,
        flash: flashElement,
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
