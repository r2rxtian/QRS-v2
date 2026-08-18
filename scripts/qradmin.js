// qradmin.js — Pagination and sorting for all tasks list

document.addEventListener('DOMContentLoaded', function() {
    const allTasksPager = paginateTable({ tableId: 'allTasksTable', paginationId: 'allTasksPagination', rowsPerPage: 6 });
    makeSortable('allTasksTable', allTasksPager);
});
