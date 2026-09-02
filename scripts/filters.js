// filters.js — Generic client-side table filter dropdown + search box.
//
// Filter dropdown markup contract:
//   <div class="filter-wrap" data-table="tasksTable">
//     <button type="button" class="btn btn-secondary filter-btn" onclick="toggleFilterPanel(this)">
//       <i class="fas fa-sliders"></i> Filters <span class="filter-badge"></span>
//     </button>
//     <div class="filter-panel">
//       <div class="filter-group">
//         <div class="filter-group-title">Status</div>
//         <label class="filter-option"><input type="checkbox" data-filter="status" value="On-going" checked> On-going</label>
//         ...
//       </div>
//       <div class="filter-panel-actions">
//         <button type="button" class="btn btn-sm btn-secondary" onclick="clearFilterPanel(this)">Clear</button>
//         <button type="button" class="btn btn-sm btn-primary" onclick="applyFilterPanel(this)">Apply</button>
//       </div>
//     </div>
//   </div>
//
// Search box markup contract (optional, works standalone or alongside filters):
//   <input type="text" class="table-search-input" data-target="tasksTable" placeholder="Search...">
//
// Each filterable <tr> carries a matching data attribute, e.g. data-status="On-going".
// Rows failing the checkbox filters OR the search text get .filter-hidden (display:none !important).
// The two mechanisms are combined by the same applyTableFilters() so neither overwrites the
// other's hidden rows. If pagination.js manages the table, its pager is refreshed automatically.

function toggleFilterPanel(button) {
    const wrap = button.closest('.filter-wrap');
    const isOpen = wrap.classList.contains('open');
    document.querySelectorAll('.filter-wrap.open').forEach(w => { if (w !== wrap) w.classList.remove('open'); });
    wrap.classList.toggle('open', !isOpen);
}

// Custom "Sort By" dropdown (see .sort-dropdown in app.css) -- a plain div
// listbox standing in for a native <select>, whose open popup can't be
// restyled by CSS at all.
function toggleSortDropdown(trigger) {
    const dropdown = trigger.closest('.sort-dropdown');
    const isOpen = dropdown.classList.contains('open');
    document.querySelectorAll('.sort-dropdown.open').forEach(d => { if (d !== dropdown) d.classList.remove('open'); });
    dropdown.classList.toggle('open', !isOpen);
}

function selectSortOption(option) {
    const dropdown = option.closest('.sort-dropdown');
    dropdown.dataset.value = option.dataset.value || '';
    dropdown.querySelector('.sort-dropdown-trigger span').textContent = option.dataset.label || option.textContent.trim();
    dropdown.querySelectorAll('.sort-dropdown-option').forEach(o => o.classList.remove('selected'));
    option.classList.add('selected');
    dropdown.classList.remove('open');
}

document.addEventListener('click', function (e) {
    const sortOption = e.target.closest('.sort-dropdown-option');
    if (sortOption) {
        selectSortOption(sortOption);
        return;
    }

    if (!e.target.closest('.filter-wrap')) {
        document.querySelectorAll('.filter-wrap.open').forEach(w => w.classList.remove('open'));
    }
    if (!e.target.closest('.sort-dropdown')) {
        document.querySelectorAll('.sort-dropdown.open').forEach(d => d.classList.remove('open'));
    }
});

function applyTableFilters(tableId, options = {}) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const filterWrap = document.querySelector('.filter-wrap[data-table="' + tableId + '"]');
    const searchInput = document.querySelector('.table-search-input[data-target="' + tableId + '"]');

    const groups = {};
    let activeFilterCount = 0;
    if (filterWrap) {
        filterWrap.querySelectorAll('input[type="checkbox"][data-filter]').forEach(cb => {
            const key = cb.dataset.filter;
            if (!groups[key]) groups[key] = { total: 0, checked: [] };
            groups[key].total++;
            if (cb.checked) groups[key].checked.push(cb.value);
        });
        Object.keys(groups).forEach(key => {
            const group = groups[key];
            // A single-checkbox group is a plain on/off toggle (e.g. "Missed
            // Out"), not a multi-select "pick which of these categories" --
            // checked means "only show matches", unchecked means "no
            // restriction, show everything" (see applyTableFilters below).
            // A multi-checkbox group instead treats checked.length < total
            // as "actively filtering", down to and including all unchecked
            // meaning "show nothing".
            const isActive = group.total === 1 ? group.checked.length === 1 : group.checked.length < group.total;
            if (isActive) activeFilterCount++;
        });
    }

    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';

    const rows = table.tBodies[0].querySelectorAll('tr');
    rows.forEach(row => {
        let match = true;

        Object.keys(groups).forEach(key => {
            if (!match) return;
            const rowValue = row.dataset[key];
            const group = groups[key];
            if (group.total === 1) {
                if (group.checked.length === 1 && !group.checked.includes(rowValue)) {
                    match = false;
                }
            } else if (group.checked.length === 0 || !group.checked.includes(rowValue)) {
                match = false;
            }
        });

        if (match && query !== '') {
            match = row.textContent.toLowerCase().includes(query);
        }

        row.classList.toggle('filter-hidden', !match);
    });

    if (filterWrap) {
        const badge = filterWrap.querySelector('.filter-badge');
        if (badge) {
            if (activeFilterCount > 0) {
                badge.textContent = activeFilterCount;
                badge.style.display = 'inline-flex';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    if (window.__pagers && window.__pagers[tableId] && typeof window.__pagers[tableId].refresh === 'function') {
        window.__pagers[tableId].refresh({ preservePage: options.preservePage === true });
    }
}

// "Sort by" dropdown inside a Filters panel, option values shaped
// "colIndex:type:direction" (see sort-table.js). Applied together with the
// checkbox filters on the same Apply click, rather than sorting live on
// every header click like the old inline header-icon sort did.
function applySortFromPanel(wrap) {
    const dropdown = wrap.querySelector('.sort-dropdown');
    if (!dropdown || !dropdown.dataset.value) return;

    const [colIndex, type, direction] = dropdown.dataset.value.split(':');
    const sorter = window.__sorters && window.__sorters[wrap.dataset.table];
    if (sorter) sorter.sortBy(colIndex, type, direction);
}

function applyFilterPanel(el) {
    const wrap = el.closest('.filter-wrap');
    applyTableFilters(wrap.dataset.table);
    applySortFromPanel(wrap);
    wrap.classList.remove('open');
}

function clearFilterPanel(el) {
    const wrap = el.closest('.filter-wrap');
    // Group by data-filter key first so a single-checkbox toggle group
    // (see applyTableFilters) clears to its neutral OFF state instead of
    // being forced checked=true like every multi-checkbox group's "select
    // everything" reset.
    const byKey = {};
    wrap.querySelectorAll('.filter-panel input[type="checkbox"][data-filter]').forEach(cb => {
        const key = cb.dataset.filter;
        (byKey[key] = byKey[key] || []).push(cb);
    });
    Object.values(byKey).forEach(checkboxes => {
        const resetValue = checkboxes.length === 1 ? false : true;
        checkboxes.forEach(cb => cb.checked = resetValue);
    });
    const dropdown = wrap.querySelector('.sort-dropdown');
    if (dropdown) {
        dropdown.dataset.value = '';
        dropdown.querySelector('.sort-dropdown-trigger span').textContent = 'Default order';
        dropdown.querySelectorAll('.sort-dropdown-option').forEach(o => o.classList.remove('selected'));
        const defaultOption = dropdown.querySelector('.sort-dropdown-option[data-value=""]');
        if (defaultOption) defaultOption.classList.add('selected');
    }
    applyTableFilters(wrap.dataset.table);
}

document.addEventListener('input', function (e) {
    const input = e.target.closest('.table-search-input');
    if (!input) return;
    applyTableFilters(input.dataset.target);
});
