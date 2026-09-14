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

    // Reuse an existing controller if another initializer discovers the
    // same table. This prevents duplicate component mounts and tweens.
    window.__pagers = window.__pagers || {};
    if (window.__pagers[options.tableId]) {
        return window.__pagers[options.tableId];
    }

    let tbody = table.tBodies[0];
    let rows = Array.from(tbody.querySelectorAll('tr:not(.filter-hidden)'));
    let totalRows = rows.length;
    let totalPages = 1;
    let currentPage = 1;
    let hasMounted = false;
    const rowHeights = new WeakMap();
    const wrapper = table.closest('.table-wrapper');

    function effectiveRowsPerPage() {
        return rowsPerPage === 'all' ? Math.max(totalRows, 1) : rowsPerPage;
    }

    function stabilizeWrapperHeight() {
        if (!wrapper) return;

        if (options.stabilizeHeight === false) {
            wrapper.classList.remove('table-wrapper--paginated');
            wrapper.style.removeProperty('--table-page-min-height');
            return;
        }

        // Newly mounted rows are still visible when this first runs. Cache
        // their natural heights before pagination hides later pages.
        Array.from(tbody.rows).forEach(row => {
            const height = row.getBoundingClientRect().height;
            if (height > 0) rowHeights.set(row, height);
        });

        const perPage = effectiveRowsPerPage();
        let tallestBody = 0;
        for (let start = 0; start < rows.length; start += perPage) {
            const pageHeight = rows.slice(start, start + perPage).reduce((sum, row) => {
                return sum + (rowHeights.get(row) || 0);
            }, 0);
            tallestBody = Math.max(tallestBody, pageHeight);
        }

        const headerHeight = table.tHead ? table.tHead.getBoundingClientRect().height : 0;
        const scrollbarAllowance = wrapper.scrollWidth > wrapper.clientWidth ? 14 : 0;
        const measuredHeight = Math.ceil(headerHeight + tallestBody + scrollbarAllowance);

        // Reserve the tallest page for the CURRENT page-size selection. This
        // prevents page-to-page shake while still allowing 25 -> 10 entries
        // (or a filtered/deleted data set) to shrink the wrapper immediately.
        wrapper.classList.add('table-wrapper--paginated');
        const nextMinHeight = measuredHeight + 'px';
        if (wrapper.style.getPropertyValue('--table-page-min-height') !== nextMinHeight) {
            wrapper.style.setProperty('--table-page-min-height', nextMinHeight);
        }
    }

    function showPage(page, animateMount) {
        totalPages = Math.max(1, Math.ceil(totalRows / effectiveRowsPerPage()));
        currentPage = Math.min(Math.max(1, page), totalPages);
        const perPage = effectiveRowsPerPage();
        const start = (currentPage - 1) * perPage;
        const end = start + perPage;

        stabilizeWrapperHeight();

        const nowVisible = [];
        rows.forEach((row, index) => {
            const shouldShow = index >= start && index < end;
            row.style.display = shouldShow ? '' : 'none';
            if (shouldShow) nowVisible.push(row);
        });

        // Entrance motion belongs to a table component mount, not to its
        // pagination state. Filtering, sorting, page clicks, and ordinary
        // refreshes only update visibility and never replay this tween.
        // A total stagger duration keeps the one mount animation bounded.
        const shouldAnimateMount = animateMount && !hasMounted;
        hasMounted = true;
        if (shouldAnimateMount && window.gsap && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            const mountTimeline = gsap.timeline({ defaults: { ease: 'power2.out' } });
            mountTimeline.fromTo(nowVisible, { opacity: 0, y: 10 }, {
                opacity: 1,
                y: 0,
                duration: 0.3,
                stagger: { amount: 0.3, from: 'start' },
                overwrite: true,
                clearProps: 'all',
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
                    showPage(page, false);
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

    showPage(1, options.animateOnMount !== false);

    const controller = {
        setRowsPerPage(n) {
            rowsPerPage = n;
            showPage(1, false);
        },
        /**
         * Re-read row order/count from the live DOM. User-driven filtering
         * and sorting reset to page 1 by default; silent server sync can keep
         * the page the user is currently reading.
         */
        refresh(refreshOptions = {}) {
            const requestedPage = refreshOptions.preservePage ? currentPage : 1;
            const nextTbody = table.tBodies[0];
            delete nextTbody.dataset.skipMountAnimation;
            tbody = nextTbody;
            rows = Array.from(tbody.querySelectorAll('tr:not(.filter-hidden)'));
            totalRows = rows.length;
            // showPage clamps the requested page when the refreshed result set
            // has fewer pages than before.
            showPage(requestedPage, false);
        }
    };

    // Global registry so other scripts (e.g. filters.js) can refresh this table's
    // pagination without the page needing to expose its own pager variable.
    window.__pagers[options.tableId] = controller;

    return controller;
}
