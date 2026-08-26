// select-dropdown.js — Custom form-select dropdown (see .select-dropdown in
// app.css). A native <select>'s open popup is drawn by the OS/browser and
// can't be restyled by CSS at all, so this is a plain div listbox standing
// in for one. Mirrors its selection into the real <select id="{data-for}">
// (kept in the DOM, visually hidden) so existing .value reads and onchange
// handlers elsewhere keep working unmodified.
//
// The menu is reparented to <body> and positioned with `position: fixed`
// coordinates computed from the trigger's own bounding rect the moment it
// first opens (same fix, same reason, as .date-picker-panel in
// date-picker.js): a dropdown inside a modal can't just float via
// position:absolute from its .select-dropdown wrapper, because .modal-body
// has overflow-y:auto and clips any absolutely-positioned descendant that
// falls past its visible scroll area -- exactly what made a short, 2-option
// Type picker in Add Location require scrolling the modal just to see its
// own options. Once moved to <body> it's found again via data-owner
// (matching .select-dropdown's own data-for, already a unique id) instead of
// DOM ancestry, since after the first open it's no longer a descendant of
// .select-dropdown at all.
//
// Markup contract:
//   <div class="select-dropdown" data-for="task_type" data-placeholder="-- Select --">
//     <button type="button" class="select-dropdown-trigger" onclick="toggleSelectDropdown(this)">
//       <i class="fas fa-diagram-project"></i>
//       <span class="select-dropdown-label placeholder">-- Select --</span>
//       <i class="fas fa-chevron-down select-dropdown-caret"></i>
//     </button>
//     <div class="select-dropdown-menu">
//       <div class="select-dropdown-option" data-value="x">X</div>
//       ...
//     </div>
//   </div>
//   <select id="task_type" class="select-dropdown-native" tabindex="-1" aria-hidden="true" onchange="...">...</select>

function findSelectDropdownMenu(dropdown) {
    const key = dropdown.dataset.for;
    let menu = document.querySelector('.select-dropdown-menu[data-owner="' + key + '"]');
    if (!menu) {
        menu = dropdown.querySelector('.select-dropdown-menu');
        if (menu) menu.dataset.owner = key;
    }
    return menu;
}

function positionSelectDropdownMenu(trigger, menu) {
    const rect = trigger.getBoundingClientRect();
    menu.style.top = (rect.bottom + 6) + 'px';
    menu.style.left = rect.left + 'px';
    menu.style.width = rect.width + 'px';
}

function closeAllSelectDropdowns() {
    document.querySelectorAll('.select-dropdown.open').forEach(d => d.classList.remove('open'));
    document.querySelectorAll('.select-dropdown-menu.open').forEach(m => m.classList.remove('open'));
}

function toggleSelectDropdown(trigger) {
    const dropdown = trigger.closest('.select-dropdown');
    const menu = findSelectDropdownMenu(dropdown);
    if (!menu) return;

    const isOpen = menu.classList.contains('open');
    closeAllSelectDropdowns();
    if (isOpen) return;

    document.body.appendChild(menu);
    positionSelectDropdownMenu(trigger, menu);
    menu.classList.add('open');
    dropdown.classList.add('open');
}

function selectDropdownOption(option) {
    const menu = option.closest('.select-dropdown-menu');
    const dropdown = menu ? document.querySelector('.select-dropdown[data-for="' + menu.dataset.owner + '"]') : null;
    if (!dropdown) return;

    const value = option.dataset.value || '';
    const label = dropdown.querySelector('.select-dropdown-label');

    dropdown.dataset.value = value;
    if (label) {
        label.textContent = option.textContent.trim();
        label.classList.remove('placeholder');
    }
    menu.querySelectorAll('.select-dropdown-option').forEach(o => o.classList.remove('selected'));
    option.classList.add('selected');
    menu.classList.remove('open');
    dropdown.classList.remove('open');

    const nativeSelect = document.getElementById(dropdown.dataset.for);
    if (nativeSelect) {
        nativeSelect.value = value;
        nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

document.addEventListener('click', function (e) {
    const selectOption = e.target.closest('.select-dropdown-option');
    if (selectOption) {
        selectDropdownOption(selectOption);
        return;
    }

    if (!e.target.closest('.select-dropdown') && !e.target.closest('.select-dropdown-menu')) {
        closeAllSelectDropdowns();
    }
});
