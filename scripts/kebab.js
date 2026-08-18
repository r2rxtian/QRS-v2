/**
 * Row action "kebab" (⋮) dropdown menus.
 * Markup expected:
 *   <div class="kebab-wrap">
 *     <button type="button" class="kebab-btn" onclick="toggleKebab(this)"><i class="fas fa-ellipsis-vertical"></i></button>
 *     <div class="kebab-menu"> ...items... </div>
 *   </div>
 */
function toggleKebab(button) {
    const menu = button.nextElementSibling;
    const isOpen = menu.classList.contains('open');
    closeAllKebabs();
    if (!isOpen) {
        menu.classList.add('open');
        positionKebab(button, menu);
    }
}

// Picks whichever direction actually has more room, measured against real
// nearby boundaries rather than just the viewport edges -- opening
// downward should stop before the pagination bar below the table (not
// just the bottom of the screen), and opening upward should stop before
// whatever sits above the table (a bulk-actions bar, in particular).
// A raw "is this the last row -> always open up" rule (the previous
// approach) gets this wrong for a short table: its last row is also one
// of its first, so forcing it upward just traded one collision (menu
// dropping into empty space / over pagination) for a worse one (menu
// opening straight into the bulk-actions bar above the table).
//
// Picking a direction isn't enough on its own, though -- a table with
// only one or two rows can be genuinely too short to fully fit the menu
// on *either* side (not enough room above the bulk-actions bar, not
// enough room below before the pagination bar). Rather than let it
// overflow past whichever boundary anyway, position is set explicitly
// (not just toggled between the two fixed CSS offsets) and clamped so it
// never crosses either boundary -- worst case it sits flush against the
// bar it's closest to instead of overlapping it.
function positionKebab(button, menu) {
    const wrap = button.parentElement;
    const buttonRect = button.getBoundingClientRect();
    const wrapRect = wrap.getBoundingClientRect();
    const menuHeight = menu.offsetHeight;

    const card = button.closest('.card');
    const pagination = card ? card.querySelector('.pagination-container') : null;
    const paginationVisible = pagination && pagination.offsetParent !== null;
    const belowBoundary = paginationVisible ? pagination.getBoundingClientRect().top : window.innerHeight;

    // The boundary for opening *up* is whatever sits above the table inside
    // the same card (a bulk-actions bar, most notably) -- not the table's
    // own top edge, which is only as far as the header row and says
    // nothing about what's beyond it.
    const bulkActions = card ? card.querySelector('.bulk-actions') : null;
    const aboveBoundary = bulkActions ? bulkActions.getBoundingClientRect().bottom : (card ? card.getBoundingClientRect().top : 0);

    const spaceBelow = belowBoundary - buttonRect.bottom;
    const spaceAbove = buttonRect.top - aboveBoundary;

    let viewportTop;
    if (spaceBelow >= menuHeight || spaceBelow >= spaceAbove) {
        menu.classList.remove('drop-up');
        viewportTop = Math.min(buttonRect.bottom + 4, belowBoundary - menuHeight);
    } else {
        menu.classList.add('drop-up');
        viewportTop = Math.max(buttonRect.top - 4 - menuHeight, aboveBoundary);
    }
    // Last-resort safety net so it's never clipped by the viewport itself
    // (e.g. a very short browser window) even after the clamping above.
    viewportTop = Math.max(4, Math.min(viewportTop, window.innerHeight - menuHeight - 4));

    // Inline top/bottom override the CSS class's fixed offsets (needed
    // just for the open/slide-up vs slide-down animation direction now),
    // so the clamped position above always wins regardless of direction.
    menu.style.top = (viewportTop - wrapRect.top) + 'px';
    menu.style.bottom = 'auto';
}

function closeAllKebabs() {
    document.querySelectorAll('.kebab-menu.open').forEach(m => m.classList.remove('open'));
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.kebab-wrap')) {
        closeAllKebabs();
    }
});
