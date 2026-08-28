/**
 * Lightweight client-side table pagination for the mockup pages.
 * No backend involved — purely re-shows/hides <tbody> rows and renders page buttons.
 *
 * Usage:
 *   <table id="myTable">...<tbody> rows...</tbody></table>
 *   <div id="myPagination"></div>
 *   <script>paginateTable({ tableId: 'myTable', paginationId: 'myPagination', rowsPerPage: 10 });</script>
 *
 * Returns a controller: { setRowsPerPage(n) } — useful for a "Show N entries" selector.
 * Pass rowsPerPage: 'all' (or call setRowsPerPage('all')) to show every row on one page.
 */
function paginateTable(options) {
    const table = document.getElementById(options.tableId);
    const paginationEl = document.getElementById(options.paginationId);
    let rowsPerPage = options.rowsPerPage || 10;
    if (!table || !paginationEl) return;

    const tbody = table.tBodies[0];
    let rows = Array.from(tbody.querySelectorAll('tr:not(.filter-hidden)'));
    let totalRows = rows.length;
    let totalPages = 1;
    let currentPage = 1;

    function effectiveRowsPerPage() {
        return rowsPerPage === 'all' ? Math.max(totalRows, 1) : rowsPerPage;
    }

    function showPage(page) {
        totalPages = Math.max(1, Math.ceil(totalRows / effectiveRowsPerPage()));
        currentPage = Math.min(Math.max(1, page), totalPages);
        const perPage = effectiveRowsPerPage();
        const start = (currentPage - 1) * perPage;
        const end = start + perPage;

        const nowVisible = [];
        rows.forEach((row, index) => {
            const shouldShow = index >= start && index < end;
            row.style.display = shouldShow ? '' : 'none';
            if (shouldShow) nowVisible.push(row);
        });

        // Small entrance for whichever rows this call just made visible --
        // covers the very first showPage(1) on page load and every later
        // page click alike, since both just end up here. This used to live
        // in scripts/motion.js instead, applied once up front to every row
        // in the table regardless of pagination -- for a table with
        // hundreds of rows (Manage Locations' 240+) that meant a single
        // stagger spread across all of them, so paging forward before a
        // row's turn in that stagger arrived landed on rows still sitting
        // at opacity:0, i.e. blank. Doing it here instead of there means
        // it only ever runs across whatever's actually visible right now
        // (a page's worth, ~10-50 rows), and it's a plain synchronous call
        // in the same function that decides visibility -- no cross-script
        // load-order assumptions left to get wrong.
        //
        // stagger is given as a total { amount }, not a fixed per-row
        // delay, specifically so this stays safe if a table's row count
        // grows a lot -- a fixed per-row delay (the old 0.03s/row that
        // caused the bug above) always re-introduces the same failure as
        // soon as ANY visible group gets large enough, and "Show entries:
        // All" makes that trivial to hit on any table, today or after
        // future data growth, by showing every row as one single visible
        // group with nothing paginated away to bound it. An { amount }
        // spreads the existing 0.3s duration across however many rows are
        // in that group instead of adding to it per row, so the whole
        // reveal finishes in roughly the same ~0.3-0.4s whether it's 10
        // rows or 10,000.
        if (window.gsap && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            gsap.fromTo(nowVisible, { opacity: 0, y: 10 }, {
                opacity: 1, y: 0, duration: 0.3, stagger: { amount: 0.3, from: 'start' }, ease: 'power2.out', overwrite: true, clearProps: 'all',
            });
        }

        renderControls();
    }

    function renderControls() {
        paginationEl.innerHTML = '';
        if (totalRows === 0) return;

        const info = document.createElement('div');
        info.className = 'pagination-info';
        const perPage = effectiveRowsPerPage();
        const start = (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, totalRows);
        info.innerHTML = `Showing <strong>${start}</strong> to <strong>${end}</strong> of <strong>${totalRows}</strong> entries`;

        const links = document.createElement('div');
        links.className = 'pagination-links';
        links.style.display = 'flex';
        links.style.gap = '4px';

        const addButton = (label, page, disabled, active, isNumber) => {
            const btn = document.createElement(disabled ? 'span' : 'a');
            btn.textContent = label;
            btn.className = 'btn-page' + (isNumber ? ' btn-page-num' : '') + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            if (!disabled) {
                btn.href = '#';
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    showPage(page);
                });
            }
            links.appendChild(btn);
        };

        addButton('Previous', currentPage - 1, currentPage === 1, false);

        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);

        if (startPage > 1) {
            addButton('1', 1, false, false, true);
            if (startPage > 2) {
                const dots = document.createElement('span');
                dots.className = 'dots';
                dots.textContent = '...';
                links.appendChild(dots);
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            addButton(String(i), i, false, i === currentPage, true);
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const dots = document.createElement('span');
                dots.className = 'dots';
                dots.textContent = '...';
                links.appendChild(dots);
            }
            addButton(String(totalPages), totalPages, false, false, true);
        }

        addButton('Next', currentPage + 1, currentPage === totalPages, false);

        paginationEl.appendChild(info);
        paginationEl.appendChild(links);
    }

    showPage(1);

    const controller = {
        setRowsPerPage(n) {
            rowsPerPage = n;
            showPage(1);
        },
        /** Re-reads row order/count from the live DOM (call after sorting, filtering, or adding/removing rows) and jumps back to page 1. */
        refresh() {
            rows = Array.from(tbody.querySelectorAll('tr:not(.filter-hidden)'));
            totalRows = rows.length;
            showPage(1);
        }
    };

    // Global registry so other scripts (e.g. filters.js) can refresh this table's
    // pagination without the page needing to expose its own pager variable.
    window.__pagers = window.__pagers || {};
    window.__pagers[options.tableId] = controller;

    return controller;
}
