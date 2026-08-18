// select-dropdown.js — Custom form-select dropdown (see .select-dropdown in
// app.css). A native <select>'s open popup is drawn by the OS/browser and
// can't be restyled by CSS at all, so this is a plain div listbox standing
// in for one. Mirrors its selection into the real <select id="{data-for}">
// (kept in the DOM, visually hidden) so existing .value reads and onchange
// handlers elsewhere keep working unmodified.
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

function toggleSelectDropdown(trigger) {
    const dropdown = trigger.closest('.select-dropdown');
    const isOpen = dropdown.classList.contains('open');
    document.querySelectorAll('.select-dropdown.open').forEach(d => { if (d !== dropdown) d.classList.remove('open'); });
    dropdown.classList.toggle('open', !isOpen);
}

function selectDropdownOption(option) {
    const dropdown = option.closest('.select-dropdown');
    const value = option.dataset.value || '';
    const label = dropdown.querySelector('.select-dropdown-label');

    dropdown.dataset.value = value;
    label.textContent = option.textContent.trim();
    label.classList.remove('placeholder');
    dropdown.querySelectorAll('.select-dropdown-option').forEach(o => o.classList.remove('selected'));
    option.classList.add('selected');
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

    if (!e.target.closest('.select-dropdown')) {
        document.querySelectorAll('.select-dropdown.open').forEach(d => d.classList.remove('open'));
    }
});
