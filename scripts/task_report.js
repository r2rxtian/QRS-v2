// task_report.js — Photo zoom, Remarks modal, and pagination controls

function showModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Each row keeps its full remark blocks in a hidden ".remarks-source" div
// (see task_report.php) purely so exportReportToPDF() can still read them --
// this just clones that markup into the modal instead of re-fetching
// anything, so the popup and the PDF are always showing the exact same data.
function openRemarksModal(button, subtitle) {
    const source = button.parentElement.querySelector('.remarks-source');
    document.getElementById('remarksModalBody').innerHTML = source ? source.innerHTML : '';
    document.getElementById('remarksModalSubtitle').textContent = subtitle || '';
    showModal('remarksModal');
}

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

        // The Remarks cell holds several labeled ".remark-block" groups
        // (Checklist / Findings / Completion Remarks). Rather than flatten
        // them into one plain string for autoTable to draw, pull out each
        // label and text line as its own { text, bold } segment -- the
        // label lines get drawn bold, the actual typed/answered content
        // stays regular weight (see the manual didParseCell/didDrawCell
        // handling for column 7 below, which is what actually renders
        // these with mixed font weights -- autoTable itself has no notion
        // of per-line styling within a single cell).
        function extractRemarksSegments(td) {
            const blocks = td.querySelectorAll('.remark-block');
            if (!blocks.length) {
                const text = td.textContent.trim();
                return text ? [{ text: text, bold: false }] : [];
            }
            const segments = [];
            Array.from(blocks).forEach(function (block, i) {
                if (i > 0) segments.push({ text: '', bold: false }); // blank line between blocks
                segments.push({ text: block.querySelector('.remark-block-label').textContent.trim() + ':', bold: true });
                Array.from(block.querySelectorAll('.remark-block-text')).forEach(function (el) {
                    segments.push({ text: el.textContent.trim(), bold: false });
                });
            });
            return segments;
        }

        const body = [];
        const rowPhotos = [];
        const rowRemarksSegments = [];
        for (const row of rows) {
            const cells = row.querySelectorAll('td');
            const textCells = [];
            for (let idx = 0; idx < 9; idx++) {
                if (idx === 7) {
                    rowRemarksSegments.push(extractRemarksSegments(cells[7]));
                    textCells.push(''); // drawn manually in didDrawCell instead
                } else {
                    textCells.push(cells[idx].textContent.trim());
                }
            }
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

        // Photos read as a small gallery rather than a thumbnail strip,
        // whether or not that particular row has photos -- so the Images
        // column reserves the same height on every row via a static
        // columnStyles.minCellHeight (applied uniformly to the whole column)
        // instead of conditionally per-row: setting minCellHeight only on
        // rows that had photos (via didParseCell) was what caused only the
        // first row to render tall while the rest stayed short.
        // THUMB_SIZE was previously 44mm ("dominant"), which reserved ~138mm
        // of the page for Images -- on a 287mm-wide landscape page that left
        // the Remarks column only ~27mm, wrapping its now-longer labeled
        // text (Checklist / Findings / Completion Remarks) into an
        // unreadable single-word-per-line mess. 28mm still reads as a real
        // gallery, just no longer at Remarks' expense.
        const THUMB_SIZE = 28; // mm square each photo is fit into, preserving aspect ratio
        const THUMB_GAP = 3;
        const THUMB_PAD = 3;
        const IMAGES_COL_WIDTH = THUMB_SIZE * 3 + THUMB_GAP * 2 + THUMB_PAD * 2;
        const IMAGES_ROW_HEIGHT = THUMB_SIZE + THUMB_PAD * 2;

        // Remarks is drawn manually (see didParseCell/didDrawCell below) so its
        // label lines ("Checklist:", "Findings / Observation:", "Completion
        // Remarks:") can render bold while the actual answered/typed content
        // under each one stays regular weight -- autoTable has no notion of
        // mixed font weights within a single cell's own auto-wrapped text.
        // A fixed (not 'auto') column width is required for this: the wrap
        // points have to be known before autoTable's own layout pass runs, so
        // there's no chicken-and-egg with an 'auto' width autoTable would
        // otherwise still be computing at that point.
        const FONT_SIZE = 6.5;
        const CELL_PADDING = 1;
        const OTHER_FIXED_COLS_WIDTH = 16 + 20 + 20 + 15 + 14 + 12 + 12 + 13; // every column except Remarks/Images
        const PAGE_MARGIN = 5;
        const REMARKS_COL_WIDTH = doc.internal.pageSize.getWidth() - PAGE_MARGIN * 2 - OTHER_FIXED_COLS_WIDTH - IMAGES_COL_WIDTH;
        const REMARKS_TEXT_WIDTH = REMARKS_COL_WIDTH - CELL_PADDING * 2;
        const LINE_HEIGHT = FONT_SIZE * doc.getLineHeightFactor() * 0.352778; // pt -> mm

        function buildRemarkLines(segments) {
            const lines = [];
            segments.forEach(function (seg) {
                if (seg.text === '') { lines.push({ text: '', bold: false }); return; }
                doc.setFont('helvetica', seg.bold ? 'bold' : 'normal');
                doc.splitTextToSize(seg.text, REMARKS_TEXT_WIDTH).forEach(function (wrapped) {
                    lines.push({ text: wrapped, bold: seg.bold });
                });
            });
            return lines;
        }
        doc.setFontSize(FONT_SIZE);
        const rowRemarksLines = rowRemarksSegments.map(buildRemarkLines);
        const rowRemarksHeights = rowRemarksLines.map(function (lines) {
            return Math.max(lines.length, 1) * LINE_HEIGHT + CELL_PADDING * 2;
        });
        doc.setFont('helvetica', 'normal');

        doc.autoTable({
            head: [headers],
            body: body,
            startY: 26,
            margin: { left: 5, right: 5 },
            styles: { fontSize: 6.5, cellPadding: 1, valign: 'middle' },
            headStyles: { fontSize: 6.5, halign: 'center', valign: 'middle' },
            // Without this, autoTable slices a row that doesn't fully fit in
            // the page's remaining space across the page break -- the photo
            // box then gets sized to whatever partial fragment of the row
            // height landed on that page (sometimes just a few mm) instead
            // of the full IMAGES_ROW_HEIGHT, which is what made some rows'
            // photos render small seemingly at random. This forces the whole
            // row onto the next page instead of splitting it.
            rowPageBreak: 'avoid',
            columnStyles: {
                0: { cellWidth: 16 },  // Task Name
                1: { cellWidth: 20 },  // Area
                2: { cellWidth: 20 },  // Scheduled Date
                3: { cellWidth: 15 },  // Biometrics
                4: { cellWidth: 14 },  // User
                5: { cellWidth: 12 },  // Start Time
                6: { cellWidth: 12 },  // End Time
                7: { cellWidth: REMARKS_COL_WIDTH }, // Remarks -- drawn manually, see below
                8: { cellWidth: 13 },  // Status
                9: { cellWidth: IMAGES_COL_WIDTH, minCellHeight: IMAGES_ROW_HEIGHT }, // Images -- dedicated space, same on every row
            },
            // Reserves this row's actual Remarks height (computed above from
            // its real line count) and blanks out the cell's own text so
            // autoTable's default single-weight text draw doesn't also fire
            // and double up with the manual bold/normal draw in didDrawCell.
            didParseCell: function (data) {
                if (data.section !== 'body' || data.column.index !== 7) return;
                data.cell.styles.minCellHeight = rowRemarksHeights[data.row.index];
                data.cell.text = [];
            },
            didDrawCell: function (data) {
                if (data.section === 'body' && data.column.index === 7) {
                    const lines = rowRemarksLines[data.row.index] || [];
                    doc.setFontSize(FONT_SIZE);
                    let y = data.cell.y + CELL_PADDING + LINE_HEIGHT * 0.8;
                    lines.forEach(function (line) {
                        if (line.text !== '') {
                            doc.setFont('helvetica', line.bold ? 'bold' : 'normal');
                            doc.text(line.text, data.cell.x + CELL_PADDING, y);
                        }
                        y += LINE_HEIGHT;
                    });
                    doc.setFont('helvetica', 'normal');
                    return;
                }
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
