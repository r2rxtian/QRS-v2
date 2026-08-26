// manage_locations.js — Location management, QR printing, modals, and pagination

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = checkbox.checked);
    updateLocationSelectedCount();
}

function updateLocationSelectedCount() {
    const el = document.getElementById('locationSelectedCount');
    if (!el) return;
    const count = document.querySelectorAll('.row-check:checked').length;
    el.textContent = count > 0 ? `${count} ${count === 1 ? 'entry' : 'entries'} selected` : '';
}

document.addEventListener('change', function (e) {
    if (e.target.classList.contains('row-check')) updateLocationSelectedCount();
});

function confirmDeleteLocation(button) {
    closeAllKebabs();
    const row = button.closest('tr');
    const locationId = row.dataset.locationId;
    const locationName = row.querySelector('.location-name').textContent;
    document.getElementById('confirmBody').textContent = `Are you sure you want to delete "${locationName}"?`;

    const yesBtn = document.getElementById('confirmBtn');
    const newYesBtn = yesBtn.cloneNode(true);
    yesBtn.parentNode.replaceChild(newYesBtn, yesBtn);

    newYesBtn.addEventListener('click', async function () {
        newYesBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.set('csrf_token', QRS_CSRF_TOKEN);
            formData.set('location_id', locationId);

            const response = await fetch('../api/locations/delete.php', { method: 'POST', body: formData });
            const data = await response.json();

            closeModal('confirmModal');
            showToast(data.message, data.type || (data.success ? 'success' : 'error'));

            if (data.success) {
                // Reload (not just row.remove()) so the stat tiles above the
                // table -- Total Locations, Available, Currently Assigned --
                // stay in sync instead of showing pre-delete counts until
                // the user manually refreshes.
                setTimeout(() => window.location.reload(), 1000);
            }
        } catch (err) {
            closeModal('confirmModal');
            showToast('Could not reach the server. Please try again.', 'error');
        } finally {
            newYesBtn.disabled = false;
        }
    });

    showModal('confirmModal');
}

async function submitAddLocation() {
    const input = document.getElementById('location_name_input');
    const btn = document.getElementById('addLocationSubmitBtn');
    const name = input.value.trim();

    if (!name) {
        showMessage('Please enter a location name.', 'error');
        return;
    }

    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('location_name', name);
    formData.set('location_type', document.getElementById('location_type_input').value);

    btn.disabled = true;
    try {
        const response = await fetch('../api/locations/create.php', { method: 'POST', body: formData });
        const data = await response.json();

        closeModal('addLocationModal');
        showMessage(data.message, data.type || (data.success ? 'success' : 'error'));

        if (data.success) {
            input.value = '';
            setTimeout(() => window.location.reload(), 1000);
        }
    } catch (err) {
        closeModal('addLocationModal');
        showMessage('Could not reach the server. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

async function submitCsvImport() {
    const fileInput = document.getElementById('csv_file_input');
    const btn = document.getElementById('csvUploadSubmitBtn');

    if (!fileInput.files.length) {
        showMessage('Please choose a CSV file first.', 'error');
        return;
    }

    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('csv_file', fileInput.files[0]);

    btn.disabled = true;
    try {
        const response = await fetch('../api/locations/import_csv.php', { method: 'POST', body: formData });
        const data = await response.json();

        closeModal('csvModal');
        showMessage(data.message, data.type || (data.success ? 'success' : 'error'));

        if (data.success) {
            fileInput.value = '';
            setTimeout(() => window.location.reload(), 1200);
        }
    } catch (err) {
        closeModal('csvModal');
        showMessage('Could not reach the server. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

function deleteSelected() {
    const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.value);
    if (!ids.length) {
        showMessage('No locations selected.', 'error');
        return;
    }

    document.getElementById('confirmBody').textContent = `Are you sure you want to delete ${ids.length} selected location(s)? This cannot be undone.`;

    const yesBtn = document.getElementById('confirmBtn');
    const newYesBtn = yesBtn.cloneNode(true);
    yesBtn.parentNode.replaceChild(newYesBtn, yesBtn);

    newYesBtn.addEventListener('click', async function () {
        newYesBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.set('csrf_token', QRS_CSRF_TOKEN);
            ids.forEach(id => formData.append('location_ids[]', id));

            const response = await fetch('../api/locations/bulk_delete.php', { method: 'POST', body: formData });
            const data = await response.json();

            closeModal('confirmModal');
            showToast(data.message, data.type || (data.success ? 'success' : 'error'));

            if (data.success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        } catch (err) {
            closeModal('confirmModal');
            showToast('Could not reach the server. Please try again.', 'error');
        } finally {
            newYesBtn.disabled = false;
        }
    });

    showModal('confirmModal');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function printQR(locationName, button) {
    closeAllKebabs();
    const img = button.closest('tr').querySelector('.qr-thumb');
    const safeName = escapeHtml(locationName);
    const win = window.open('', '_blank', 'width=400,height=500');
    win.document.write('<html><head><title>Print QR — ' + safeName + '</title>');
    win.document.write('<style>body{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;font-family:sans-serif;}img{width:250px;height:250px;}</style>');
    win.document.write('</head><body>');
    win.document.write('<img src="' + img.src.replace('100x100', '250x250') + '">');
    win.document.write('<h3>' + safeName + '</h3>');
    win.document.write('</body></html>');
    win.document.close();
    win.focus();
    win.print();
}

function showModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function showMessage(message, type = 'info') {
    const titleEl = document.getElementById('msgTitle');
    const bodyEl = document.getElementById('msgBody');
    titleEl.textContent = type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Notification';
    bodyEl.textContent = message;
    showModal('messageModal');
}

document.addEventListener('DOMContentLoaded', function() {
    // 10, matching the same "Show entries" default used everywhere else
    // this selector appears (Task Manager, All Tasks, Task Report).
    const locationsPager = paginateTable({ tableId: 'locationsTable', paginationId: 'locationsPagination', rowsPerPage: 10 });
    makeSortable('locationsTable', locationsPager);

    window.onLocationsEntriesChange = function(value) {
        locationsPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
    };
});
