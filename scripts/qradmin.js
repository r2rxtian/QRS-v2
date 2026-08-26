// qradmin.js — Pagination and sorting for all tasks list

document.addEventListener('DOMContentLoaded', function() {
    // 10, matching the same "Show entries" default used everywhere else
    // this selector appears (Task Manager, Manage Locations, Task Report).
    const allTasksPager = paginateTable({ tableId: 'allTasksTable', paginationId: 'allTasksPagination', rowsPerPage: 10 });
    makeSortable('allTasksTable', allTasksPager);

    window.onAllTasksEntriesChange = function(value) {
        allTasksPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
    };
});
