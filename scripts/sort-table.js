/**
 * Table sorting, driven from the table's own "Filters" dropdown (a
 * "Sort by" <select>) instead of clickable column headers -- headers no
 * longer carry a sort-direction icon at all, since that icon's inline
 * width next to variable-length header text was causing header rows to
 * misalign at narrower widths.
 *
 * Usage:
 *   const pager = paginateTable({ tableId: 'myTable', paginationId: 'myPagination' });
 *   makeSortable('myTable', pager); // registers window.__sorters['myTable']
 *
 * The Filters panel's "Sort by" <select> option values are "colIndex:type:direction",
 * e.g. <option value="0:text:asc">Task Name (A-Z)</option> -- see filters.js's
 * applySortFromPanel(), which reads this and calls the registered sorter.
 */
function makeSortable(tableId, pager) {
    const table = document.getElementById(tableId);
    if (!table) {
        return { sortBy: function () {} };
    }

    let tbody = table.tBodies[0];

    function sortBy(colIndex, type, direction) {
        if (colIndex === '' || colIndex === null || colIndex === undefined) return;
        colIndex = parseInt(colIndex, 10);
        if (Number.isNaN(colIndex)) return;

        // Live-region synchronization can replace tbody without replacing
        // the table itself, so always sort the currently mounted body.
        tbody = table.tBodies[0];
        const rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort((rowA, rowB) => {
            const cellA = rowA.children[colIndex];
            const cellB = rowB.children[colIndex];
            // A cell can carry data-sort-value for a machine-sortable form
            // (e.g. raw "2026-08-20") distinct from its human-friendly
            // display text (e.g. "Aug 20, 2026", which wouldn't sort
            // chronologically as plain text).
            const textA = (cellA && cellA.dataset.sortValue) || (cellA ? cellA.textContent.trim() : '');
            const textB = (cellB && cellB.dataset.sortValue) || (cellB ? cellB.textContent.trim() : '');

            let result;
            if (type === 'number') {
                result = (parseFloat(textA) || 0) - (parseFloat(textB) || 0);
            } else {
                result = textA.localeCompare(textB, undefined, { sensitivity: 'base' });
            }

            return direction === 'desc' ? -result : result;
        });

        rows.forEach(row => tbody.appendChild(row));

        if (pager && typeof pager.refresh === 'function') {
            pager.refresh();
        }
    }

    const controller = { sortBy };
    window.__sorters = window.__sorters || {};
    window.__sorters[tableId] = controller;

    return controller;
}
