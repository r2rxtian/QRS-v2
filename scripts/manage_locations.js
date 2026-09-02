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

async function refreshLocationsView() {
    const response = await fetch(window.location.href, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!response.ok) throw new Error('Could not refresh the location list.');

    const nextDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
    const replacements = [
        ['.stat-tiles', '.stat-tiles'],
        ['#locationsTable tbody', '#locationsTable tbody'],
    ];
    if (!nextDocument.querySelector('#locationsTable tbody')) {
        throw new Error('The refreshed location view was not available.');
    }
    replacements.forEach(([currentSelector, nextSelector]) => {
        const current = document.querySelector(currentSelector);
        const next = nextDocument.querySelector(nextSelector);
        if (current && next) current.replaceWith(next);
    });

    window.__pagers?.locationsTable?.refresh();
    const selectAll = document.getElementById('select_all');
    if (selectAll) selectAll.checked = false;
    updateLocationSelectedCount();
}

function confirmDeleteLocation(button) {
    closeAllKebabs();
    const row = button.closest('tr');
    const locationId = row.dataset.locationId;
    // .location-name's own textContent also picks up the whitespace/newlines
    // sitting between the icon div and the name <span> in the PHP template's
    // markup -- harmless as plain text, but #confirmModal .modal-body is
    // white-space:pre-line (so multi-line messages render correctly), which
    // turned that stray whitespace into visible blank lines around the name.
    // Reading the <span> itself skips the icon markup entirely.
    const locationName = row.querySelector('.location-name span').textContent.trim();
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
                await refreshLocationsView();
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

// Set once a create actually succeeds, so closeAddLocationModal() knows
// whether the table behind it needs a reload -- closing out of the plain
// form step (Cancel, or the X before ever submitting) shouldn't reload for
// no reason.
let _addLocationCreated = false;

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

        if (!data.success) {
            showMessage(data.message, data.type || 'error');
            return;
        }

        // Stays open on success instead of closing immediately -- the QR
        // code is the whole point of adding a location, so the user gets a
        // chance to actually see and download it here rather than it only
        // ever existing as a tiny thumbnail back in the table row.
        _addLocationCreated = true;
        input.value = '';
        await refreshLocationsView();

        const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(data.data.qr_token);
        const qrImg = document.getElementById('addLocationQrImg');
        qrImg.src = qrUrl;
        qrImg.dataset.downloadName = data.data.name;
        qrImg.dataset.qrToken = data.data.qr_token; // kept separately so Print can request its own larger size, independent of the smaller preview src
        document.getElementById('addLocationQrName').textContent = data.data.name + ' · ' + data.data.location_type;

        document.getElementById('addLocationFormStep').style.display = 'none';
        document.getElementById('addLocationQrStep').style.display = 'block';
        document.getElementById('addLocationCancelBtn').style.display = 'none';
        document.getElementById('addLocationSubmitBtn').style.display = 'none';
        document.getElementById('addLocationDownloadBtn').style.display = '';
        document.getElementById('addLocationPrintBtn').style.display = '';
        document.getElementById('addLocationDoneBtn').style.display = '';
    } catch (err) {
        showMessage('Could not reach the server. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

// Fetched as a blob (not a plain <a download> on the cross-origin QR URL)
// so it forces an actual file save reliably regardless of the browser's own
// cross-origin download-attribute quirks -- the QR API sends permissive
// CORS headers, so this works without needing our own server in the middle.
async function downloadAddLocationQr() {
    const img = document.getElementById('addLocationQrImg');
    const btn = document.getElementById('addLocationDownloadBtn');
    btn.disabled = true;
    try {
        const response = await fetch(img.src);
        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);
        const safeName = (img.dataset.downloadName || 'location').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
        const a = document.createElement('a');
        a.href = objectUrl;
        a.download = safeName + '-qr.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(objectUrl);
    } catch (err) {
        showMessage('Could not download the QR code. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

// Same new-window-and-print approach as the table's own printQR() (see
// above), just reading from the modal's own preview image/name instead of a
// table row -- printQR() itself can't be reused directly since it locates
// its image via button.closest('tr'), which doesn't exist in this modal.
function printAddLocationQr() {
    const img = document.getElementById('addLocationQrImg');
    const name = img.dataset.downloadName || 'Location';
    const safeName = escapeHtml(name);
    const printUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(img.dataset.qrToken || '');
    const win = window.open('', '_blank', 'width=400,height=500');
    win.document.write('<html><head><title>Print QR — ' + safeName + '</title>');
    win.document.write('<style>body{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;font-family:sans-serif;}img{width:250px;height:250px;}</style>');
    win.document.write('</head><body>');
    win.document.write('<img src="' + printUrl + '">');
    win.document.write('<h3>' + safeName + '</h3>');
    win.document.write('</body></html>');
    win.document.close();
    win.focus();
    win.print();
}

// Resets the modal back to its form step every time it closes. The table is
// already synchronized in the background as soon as creation succeeds.
function closeAddLocationModal() {
    closeModal('addLocationModal');
    _addLocationCreated = false;

    document.getElementById('addLocationFormStep').style.display = '';
    document.getElementById('addLocationQrStep').style.display = 'none';
    document.getElementById('addLocationCancelBtn').style.display = '';
    document.getElementById('addLocationSubmitBtn').style.display = '';
    document.getElementById('addLocationDownloadBtn').style.display = 'none';
    document.getElementById('addLocationPrintBtn').style.display = 'none';
    document.getElementById('addLocationDoneBtn').style.display = 'none';

}

// Reads the row's own current data straight out of the table -- no extra
// round-trip to fetch a location by id just to populate a form that's
// already sitting right there on screen.
function openEditLocationModal(button) {
    closeAllKebabs();
    const row = button.closest('tr');
    const locationId = row.dataset.locationId;
    const locationType = row.dataset.loctype;
    const locationName = row.querySelector('.location-name span').textContent.trim();

    document.getElementById('edit_location_id_input').value = locationId;
    document.getElementById('edit_location_name_input').value = locationName;
    document.getElementById('edit_location_type_display').textContent = locationType;

    showModal('editLocationModal');
}

async function submitEditLocation() {
    const locationId = document.getElementById('edit_location_id_input').value;
    const input = document.getElementById('edit_location_name_input');
    const btn = document.getElementById('editLocationSubmitBtn');
    const name = input.value.trim();

    if (!name) {
        showMessage('Please enter a location name.', 'error');
        return;
    }

    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('location_id', locationId);
    formData.set('location_name', name);

    btn.disabled = true;
    try {
        const response = await fetch('../api/locations/update.php', { method: 'POST', body: formData });
        const data = await response.json();

        closeModal('editLocationModal');
        showToast(data.message, data.type || (data.success ? 'success' : 'error'));

        if (data.success) {
            await refreshLocationsView();
        }
    } catch (err) {
        closeModal('editLocationModal');
        showToast('Could not reach the server. Please try again.', 'error');
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
        showMessage(
            data.message,
            data.success ? 'success' : 'error',
            data.success ? 'Import Successful' : 'Import Unsuccessful'
        );

        if (data.success) {
            fileInput.value = '';
            updateCsvSelectedFilename();
            await refreshLocationsView();
        }
    } catch (err) {
        closeModal('csvModal');
        showMessage('Could not reach the server. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

function updateCsvSelectedFilename() {
    const fileInput = document.getElementById('csv_file_input');
    const filename = document.getElementById('csvSelectedFile');
    if (!fileInput || !filename) return;

    const selectedFile = fileInput.files[0];
    filename.textContent = selectedFile ? selectedFile.name : 'No file selected';
    filename.title = selectedFile ? selectedFile.name : '';
    filename.classList.toggle('has-file', Boolean(selectedFile));
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
                await refreshLocationsView();
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

function showMessage(message, type = 'info', title = '') {
    const titleEl = document.getElementById('msgTitle');
    const bodyEl = document.getElementById('msgBody');
    titleEl.textContent = title || (type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Notification');
    bodyEl.textContent = message;
    showModal('messageModal');
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('csv_file_input')?.addEventListener('change', updateCsvSelectedFilename);
    updateCsvSelectedFilename();

    // 10, matching the same "Show entries" default used everywhere else
    // this selector appears (Task Manager, All Tasks, Task Report).
    const locationsPager = paginateTable({ tableId: 'locationsTable', paginationId: 'locationsPagination', rowsPerPage: 10 });
    makeSortable('locationsTable', locationsPager);

    window.onLocationsEntriesChange = function(value) {
        locationsPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
    };
});
