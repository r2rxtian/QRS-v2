// task_report.js — Photo zoom and pagination controls

function zoomPhoto(img) {
    var zoomedPhoto = document.createElement('div');
    zoomedPhoto.classList.add('zoomed-photo');
    zoomedPhoto.innerHTML = '<button type="button" class="zoomed-photo-close" aria-label="Close"><i class="fas fa-times"></i></button><img src="' + img.src + '">';
    document.body.appendChild(zoomedPhoto);
    zoomedPhoto.querySelector('.zoomed-photo-close').onclick = function () { document.body.removeChild(zoomedPhoto); };
    zoomedPhoto.style.display = 'flex';
}

// Loads an <img> src (same-origin, under assets/uploads/photos/) and
// re-encodes it as a JPEG data URL via canvas -- jsPDF can't fetch a
// relative/remote URL itself, and re-encoding to JPEG sidesteps any format
// support gaps (PNG/WEBP originals) in older jsPDF builds.
const _pdfPhotoCache = {};
function loadImageForPdf(src) {
    if (_pdfPhotoCache[src]) return _pdfPhotoCache[src];
    _pdfPhotoCache[src] = new Promise(function (resolve, reject) {
        const img = new Image();
        img.onload = function () {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            canvas.getContext('2d').drawImage(img, 0, 0);
            resolve({
                dataUrl: canvas.toDataURL('image/jpeg', 0.85),
                width: img.naturalWidth,
                height: img.naturalHeight,
            });
        };
        img.onerror = reject;
        img.src = src;
    });
    return _pdfPhotoCache[src];
}

// Exports the report table -- respecting active filters/search, ignoring
// which page pagination.js currently has visible -- to a PDF, including
// each row's attached photos as thumbnails in an Images column.
async function exportReportToPDF() {
    const btn = document.getElementById('exportPdfBtn');
    const originalBtnHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';

    try {
        const table = document.getElementById('reportTable');
        const rows = Array.from(table.tBodies[0].querySelectorAll('tr:not(.filter-hidden)'));

        const headers = ['Task Name', 'Area', 'Scheduled Date', 'Biometrics', 'User', 'Start Time', 'End Time', 'Remarks', 'Status', 'Images'];

        // Start/End Time already carry a full "YYYY-MM-DD HH:MM:SS" on screen,
        // but the date is redundant here since Scheduled Date already shows
        // it -- stripping it in the export keeps those columns compact
        // without losing information.
        function compactTimeText(text) {
            const m = text.match(/^\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2})$/);
            return m ? m[1] : text;
        }

        const body = [];
        const rowPhotos = [];
        for (const row of rows) {
            const cells = row.querySelectorAll('td');
            const textCells = Array.from(cells).slice(0, 9).map(td => td.textContent.trim());
            textCells[5] = compactTimeText(textCells[5]); // Start Time
            textCells[6] = compactTimeText(textCells[6]); // End Time
            const imgEls = Array.from(cells[9].querySelectorAll('img'));

            const photos = [];
            for (const imgEl of imgEls) {
                try {
                    photos.push(await loadImageForPdf(imgEl.src));
                } catch (e) {
                    // Skip a photo that fails to load rather than aborting the whole export.
                }
            }
            rowPhotos.push(photos);
            body.push([...textCells, photos.length ? '' : 'No photos']);
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape' });
        doc.setFontSize(14);
        doc.text('Task Report', 14, 15);
        doc.setFontSize(10);
        doc.text('Generated: ' + new Date().toLocaleString(), 14, 21);

        // Photos dominate the row -- a row with pictures should read as
        // dramatically bigger than a plain text row, closer to a small
        // gallery than a thumbnail strip. Every other column is squeezed to
        // the minimum width its own content needs to make room, and page
        // margins are tightened to free up a bit more width for it.
        const THUMB_SIZE = 44; // mm square each photo is fit into, preserving aspect ratio
        const THUMB_GAP = 3;
        const THUMB_PAD = 3;
        const IMAGES_COL_WIDTH = THUMB_SIZE * 3 + THUMB_GAP * 2 + THUMB_PAD * 2;

        doc.autoTable({
            head: [headers],
            body: body,
            startY: 26,
            margin: { left: 5, right: 5 },
            styles: { fontSize: 6.5, cellPadding: 1, valign: 'middle' },
            headStyles: { fontSize: 6.5, halign: 'center', valign: 'middle' },
            columnStyles: {
                0: { cellWidth: 16 },  // Task Name
                1: { cellWidth: 20 },  // Area
                2: { cellWidth: 20 },  // Scheduled Date
                3: { cellWidth: 15 },  // Biometrics
                4: { cellWidth: 14 },  // User
                5: { cellWidth: 12 },  // Start Time
                6: { cellWidth: 12 },  // End Time
                7: { cellWidth: 'auto' }, // Remarks -- gets whatever's left
                8: { cellWidth: 13 },  // Status
                9: { cellWidth: IMAGES_COL_WIDTH }, // Images -- dedicated, dominant space
            },
            didParseCell: function (data) {
                if (data.section !== 'body' || data.column.index !== 9) return;
                const photos = rowPhotos[data.row.index] || [];
                if (photos.length > 0) {
                    data.cell.styles.minCellHeight = THUMB_SIZE + THUMB_PAD * 2;
                }
            },
            didDrawCell: function (data) {
                if (data.section !== 'body' || data.column.index !== 9) return;
                const photos = rowPhotos[data.row.index] || [];
                // Box size adapts to whatever the row's actual height turns
                // out to be (capped at THUMB_SIZE) rather than assuming
                // minCellHeight always took effect -- keeps every photo
                // strictly inside its own row's bounds, never bleeding into
                // the row above/below.
                const boxSize = Math.max(0, Math.min(THUMB_SIZE, data.cell.height - THUMB_PAD * 2));
                photos.forEach(function (photo, i) {
                    const boxX = data.cell.x + THUMB_PAD + i * (boxSize + THUMB_GAP);
                    if (boxSize <= 0 || boxX + boxSize > data.cell.x + data.cell.width) return;
                    const boxY = data.cell.y + Math.max(0, (data.cell.height - boxSize) / 2);
                    const ratio = Math.min(boxSize / photo.width, boxSize / photo.height);
                    const w = photo.width * ratio;
                    const h = photo.height * ratio;
                    doc.addImage(photo.dataUrl, 'JPEG', boxX + (boxSize - w) / 2, boxY + (boxSize - h) / 2, w, h);
                });
            },
        });

        doc.save('task-report-' + new Date().toISOString().slice(0, 10) + '.pdf');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const reportPager = paginateTable({ tableId: 'reportTable', paginationId: 'reportPagination', rowsPerPage: 10 });
    makeSortable('reportTable', reportPager);

    window.onEntriesChange = function(value) {
        reportPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
    };

    // Arriving here via a "View in Task Report" link (e.g. from a completed
    // task's detail modal) pre-fills the search box server-side with the
    // task name -- apply that filter immediately instead of waiting for the
    // user to retype it.
    const searchInput = document.querySelector('.table-search-input[data-target="reportTable"]');
    if (searchInput && searchInput.value) {
        applyTableFilters('reportTable');
    }
});
