// audit_logs.js — pagination/sort wiring for pages/audit_logs.php.

document.addEventListener('DOMContentLoaded', function () {
    const auditPager = paginateTable({ tableId: 'auditLogTable', paginationId: 'auditLogPagination', rowsPerPage: 10 });
    makeSortable('auditLogTable', auditPager);

    window.onAuditLogEntriesChange = function (value) {
        auditPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
    };
});
