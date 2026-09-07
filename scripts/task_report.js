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

        // Start/End Time carry a full "YYYY-MM-DD HH:MM:SS" on screen --
        // stacked onto two lines (date, then time) rather than left as one
        // long horizontal string, an embedded "\n" is all autoTable needs
        // to render and auto-size a multi-line cell on its own, no manual
        // didDrawCell handling required (unlike Remarks below, which needs
        // per-line bold/normal styling autoTable has no built-in way to
        // express). Narrower per-line text is what lets these two columns
        // give some of their width back to User (see columnStyles).
        function stackDateTime(text) {
            const m = text.match(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/);
            return m ? m[1] + '\n' + m[2] : text;
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
            textCells[5] = stackDateTime(textCells[5]); // Start Time
            textCells[6] = stackDateTime(textCells[6]); // End Time
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

        // Company identity block at the top-left of the first page. The
        // contact details intentionally sit directly beside the logo, matching
        // the layout used on La Rose Noire's controlled paper forms.
        const LOGO_PATH = '../assets/images/la-rose-noire-logo.png';
        const LOGO_SIZE = 26; // mm square
        const COMPANY_TEXT_X = 5 + LOGO_SIZE + 4;
        const PAGE_RIGHT = doc.internal.pageSize.getWidth() - 5;
        try {
            const logo = await loadImageForPdf(LOGO_PATH);
            doc.addImage(logo.dataUrl, 'JPEG', 5, 5, LOGO_SIZE, LOGO_SIZE);
        } catch (e) {
            // Missing/unreachable logo shouldn't block the export.
        }

        doc.setTextColor(0, 0, 0);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);
        doc.text('La Rose Noire Philippines, Inc.', COMPANY_TEXT_X, 9);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.5);
        doc.text('Lot 1-A & B, Clark IE-05 Area, M.A. Roxas Highway,', COMPANY_TEXT_X, 13.5);
        doc.text('Clark Freeport Zone, Philippines', COMPANY_TEXT_X, 17);
        doc.text('Tel: +63 45 499-3010   |   Fax: +63 45 499 2346', COMPANY_TEXT_X, 21);
        doc.text('Email: office@la-rose-noire.com', COMPANY_TEXT_X, 25);

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.text('Task Report', PAGE_RIGHT, 14, { align: 'right' });
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8);
        doc.text('Generated: ' + new Date().toLocaleString(), PAGE_RIGHT, 20, { align: 'right' });

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
        const OTHER_FIXED_COLS_WIDTH = 16 + 20 + 20 + 15 + 16 + 14 + 14 + 13; // every column except Remarks/Images
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
            startY: 34, // clears the enlarged (26mm) logo's bottom edge at y=31
            margin: { left: 5, right: 5, bottom: 16 }, // keeps table rows clear of the document-control footer drawn below
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
            // halign set per column (not left at headStyles' single default)
            // so each column's header sits over body text aligned the same
            // way, instead of every header forcing to center while body
            // text defaults to left -- free-text columns (name/area/user/
            // remarks) stay left-aligned since centering a variable-length
            // name reads worse, short fixed-format values (date/time/code/
            // status) center to match their own centered header.
            columnStyles: {
                0: { cellWidth: 16, halign: 'left' },    // Task Name
                1: { cellWidth: 20, halign: 'left' },    // Area
                2: { cellWidth: 20, halign: 'center' },  // Scheduled Date
                3: { cellWidth: 15, halign: 'center' },  // Biometrics
                4: { cellWidth: 16, halign: 'left', cellPadding: { top: 1, right: 1.5, bottom: 1, left: 1.5 } },    // User -- widened; Start/End Time need less width now that each stacks onto two shorter lines instead of one long one
                5: { cellWidth: 14, halign: 'center', cellPadding: { top: 1, right: 1, bottom: 1, left: 1 } },  // Start Time
                6: { cellWidth: 14, halign: 'center', cellPadding: { top: 1, right: 1, bottom: 1, left: 1 } },  // End Time
                7: { cellWidth: REMARKS_COL_WIDTH, halign: 'left' }, // Remarks -- drawn manually, see below
                8: { cellWidth: 13, halign: 'center' },  // Status
                9: { cellWidth: IMAGES_COL_WIDTH, halign: 'center', minCellHeight: IMAGES_ROW_HEIGHT }, // Images -- dedicated space, same on every row
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
                    // Centered within the row's actual height (data.cell.height),
                    // not the Remarks column's own (often shorter) minCellHeight --
                    // the Images column's fixed minCellHeight is frequently what
                    // sets the real row height, and every other column already
                    // centers via valign:'middle', so a short remark was
                    // previously left sitting at the top of a much taller row
                    // instead of matching its neighbors.
                    const textBlockHeight = Math.max(lines.length, 1) * LINE_HEIGHT;
                    let y = data.cell.y + (data.cell.height - textBlockHeight) / 2 + LINE_HEIGHT * 0.8;
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
                // Group centered horizontally in the (fixed-width) cell instead
                // of always starting flush left -- a row with 1 photo used to
                // sit at the left edge of space reserved for 3, while a row
                // with 3 filled it entirely; the reserved column width and
                // thumb size are unchanged, only where the used portion sits
                // within it.
                const groupWidth = photos.length > 0 ? photos.length * boxSize + (photos.length - 1) * THUMB_GAP : 0;
                const groupStartX = data.cell.x + Math.max(THUMB_PAD, (data.cell.width - groupWidth) / 2);
                photos.forEach(function (photo, i) {
                    const boxX = groupStartX + i * (boxSize + THUMB_GAP);
                    if (boxSize <= 0 || boxX + boxSize > data.cell.x + data.cell.width) return;
                    const boxY = data.cell.y + Math.max(0, (data.cell.height - boxSize) / 2);
                    const ratio = Math.min(boxSize / photo.width, boxSize / photo.height);
                    const w = photo.width * ratio;
                    const h = photo.height * ratio;
                    doc.addImage(photo.dataUrl, 'JPEG', boxX + (boxSize - w) / 2, boxY + (boxSize - h) / 2, w, h);
                });
            },
        });

        // Document-control footer, printed on every page: report title +
        // document code on the left, effectivity date + revision on the
        // right (same layout as the company's other controlled forms, e.g.
        // the FSMS Risk Assessment form). Effectivity Date is a fixed value
        // your QA/compliance process assigns, not something computed from
        // "today" each export -- update it here once that date is set.
        const FOOTER_TITLE = 'QR Task Check Accomplishment Report';
        const FOOTER_DOC_CODE = 'Document Code: IMS-F-091';
        const FOOTER_EFFECTIVITY = 'Effectivity Date: 08-27-2026'; // placeholder -- replace with the officially assigned date
        const FOOTER_REVISION = 'Revision: 00';

        const pageCount = doc.internal.getNumberOfPages();
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        for (let p = 1; p <= pageCount; p++) {
            doc.setPage(p);
            doc.setDrawColor(180);
            doc.line(5, pageHeight - 11, pageWidth - 5, pageHeight - 11);
            doc.setFontSize(7.5);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(0, 0, 0);
            doc.text(FOOTER_TITLE, 5, pageHeight - 7);
            doc.text(FOOTER_DOC_CODE, 5, pageHeight - 3.5);
            doc.text(FOOTER_EFFECTIVITY, pageWidth - 5, pageHeight - 7, { align: 'right' });
            doc.text(FOOTER_REVISION, pageWidth - 5, pageHeight - 3.5, { align: 'right' });
        }
        doc.setTextColor(0, 0, 0);

        doc.save('task-report-' + new Date().toISOString().slice(0, 10) + '.pdf');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
    }
}

function mountTaskReportTable() {
    const table = document.getElementById('reportTable');
    if (!table || table.dataset.paginationMounted === 'true') return;
    table.dataset.paginationMounted = 'true';

    // Apply a server-prefilled search before pagination captures/animates the
    // first visible row set. Doing it afterward would swap rows underneath an
    // already-running entrance timeline.
    const searchInput = document.querySelector('.table-search-input[data-target="reportTable"]');
    const dateFromInput = document.querySelector('.filter-wrap[data-table="reportTable"] input[data-filter-date="from"]');
    const dateToInput = document.querySelector('.filter-wrap[data-table="reportTable"] input[data-filter-date="to"]');
    if ((searchInput && searchInput.value) || (dateFromInput && dateFromInput.value) || (dateToInput && dateToInput.value)) {
        applyTableFilters('reportTable');
    }

    // Match Task Manager and Manage Locations: one paginator owns the table
    // mount, sorting, entry-limit changes, and its single GSAP row reveal.
    const reportPager = paginateTable({
        tableId: 'reportTable',
        paginationId: 'reportPagination',
        rowsPerPage: 10,
    });
    makeSortable('reportTable', reportPager);

    window.reportPager = reportPager;
    window.onEntriesChange = function(value) {
        reportPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
    };

}

// This script is placed after the report DOM and before the heavier PDF
// libraries. Mount immediately so third-party downloads cannot postpone the
// initial pagination/animation lifecycle.
mountTaskReportTable();
