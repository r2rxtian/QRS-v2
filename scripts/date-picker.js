// date-picker.js — Custom calendar popup for .date-field (see app.css).
// The native <input type="date">'s own picker popup is drawn by the
// OS/browser and can't be restyled at all (same problem as a native
// <select>'s open list), so this renders a day grid from scratch. The real,
// invisible <input> stays the single source of truth for the field's
// value -- selecting a day here just sets .value and dispatches a change
// event, same as .select-dropdown does for its hidden <select>.
//
// The panel is reparented to <body> and positioned with `position: fixed`
// coordinates computed from the trigger's own bounding rect the moment it
// opens. It can't just stay nested in .date-field and float via
// position:absolute like .select-dropdown-menu does: Create Task is a
// .modal-md, and .modal-md .modal-body has overflow-y:auto (needed so long
// modal content scrolls instead of pushing the whole page) -- that clips
// any absolutely-positioned descendant taller than the visible scroll area,
// which this calendar is. Moving it out to <body> sidesteps that clipping
// entirely rather than fighting it.
//
// Markup contract:
//   <div class="date-field">
//     <button type="button" class="select-dropdown-trigger" onclick="toggleDatePicker(this)">...</button>
//     <div class="date-picker-panel" data-for="task_date">
//       <div class="date-picker-header">
//         <button type="button" class="date-picker-nav" onclick="navigateDatePicker(this, -1)">...</button>
//         <span class="date-picker-month-label"></span>
//         <button type="button" class="date-picker-nav" onclick="navigateDatePicker(this, 1)">...</button>
//       </div>
//       <div class="date-picker-weekdays"><span>Su</span>...</div>
//       <div class="date-picker-grid"></div>
//       <div class="date-picker-footer">
//         <button type="button" class="date-picker-today-btn" onclick="goToToday(this)">Today</button>
//       </div>
//     </div>
//     <input type="date" id="task_date" tabindex="-1" aria-hidden="true" min="..." value="...">
//   </div>
// data-for on the panel must match the hidden input's id -- that's the link
// used to find the panel/field/input from each other once the panel has
// been moved out to <body> and is no longer a DOM descendant of .date-field.

const DATE_PICKER_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function isoToday() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function findPanelForField(field) {
    const input = field.querySelector('input[type="date"]');
    return input ? document.querySelector('.date-picker-panel[data-for="' + input.id + '"]') : null;
}

function positionDatePickerPanel(trigger, panel) {
    const rect = trigger.getBoundingClientRect();
    panel.style.top = (rect.bottom + 6) + 'px';
    panel.style.left = rect.left + 'px';
}

function closeDatePickerPanel(panel) {
    panel.classList.remove('open');
    const input = document.getElementById(panel.dataset.for);
    const field = input ? input.closest('.date-field') : null;
    if (field) field.classList.remove('open');
}

function toggleDatePicker(trigger) {
    const field = trigger.closest('.date-field');
    const panel = findPanelForField(field);
    if (!panel) return;

    if (panel.classList.contains('open')) {
        closeDatePickerPanel(panel);
        return;
    }

    document.querySelectorAll('.date-picker-panel.open').forEach(closeDatePickerPanel);

    document.body.appendChild(panel);
    positionDatePickerPanel(trigger, panel);
    panel.classList.add('open');
    field.classList.add('open');

    const input = document.getElementById(panel.dataset.for);
    const [y, m] = (input.value || isoToday()).split('-').map(Number);
    renderCalendar(panel, input, y, m - 1);
}

function renderCalendar(panel, input, year, month) {
    panel.dataset.year = year;
    panel.dataset.month = month;

    const selectedIso = input.value || '';
    const minIso = input.min || '';
    const todayIso = isoToday();

    panel.querySelector('.date-picker-month-label').textContent = DATE_PICKER_MONTHS[month] + ' ' + year;

    const grid = panel.querySelector('.date-picker-grid');
    grid.innerHTML = '';

    const startOffset = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();

    for (let i = 0; i < 42; i++) {
        const dayNum = i - startOffset + 1;
        let cellYear = year;
        let cellMonth = month;
        let cellDay;
        let outside = false;

        if (dayNum < 1) {
            cellDay = daysInPrevMonth + dayNum;
            cellMonth -= 1;
            outside = true;
        } else if (dayNum > daysInMonth) {
            cellDay = dayNum - daysInMonth;
            cellMonth += 1;
            outside = true;
        } else {
            cellDay = dayNum;
        }
        if (cellMonth < 0) { cellMonth = 11; cellYear -= 1; }
        if (cellMonth > 11) { cellMonth = 0; cellYear += 1; }

        const iso = cellYear + '-' + String(cellMonth + 1).padStart(2, '0') + '-' + String(cellDay).padStart(2, '0');

        const cell = document.createElement('div');
        cell.className = 'date-picker-day';
        cell.textContent = String(cellDay);
        if (outside) cell.classList.add('outside');
        if (iso === todayIso) cell.classList.add('today');
        if (iso === selectedIso) cell.classList.add('selected');

        if (minIso && iso < minIso) {
            cell.classList.add('disabled');
        } else {
            cell.addEventListener('click', () => selectDate(panel, input, iso));
        }
        grid.appendChild(cell);
    }
}

function selectDate(panel, input, iso) {
    input.value = iso;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    closeDatePickerPanel(panel);
}

function navigateDatePicker(button, dir) {
    const panel = button.closest('.date-picker-panel');
    const input = document.getElementById(panel.dataset.for);
    let year = Number(panel.dataset.year);
    let month = Number(panel.dataset.month) + dir;
    if (month < 0) { month = 11; year -= 1; }
    if (month > 11) { month = 0; year += 1; }
    renderCalendar(panel, input, year, month);
}

function goToToday(button) {
    const panel = button.closest('.date-picker-panel');
    const input = document.getElementById(panel.dataset.for);
    selectDate(panel, input, isoToday());
}

document.addEventListener('click', function (e) {
    if (e.target.closest('.date-picker-panel') || e.target.closest('.date-field')) return;
    document.querySelectorAll('.date-picker-panel.open').forEach(closeDatePickerPanel);
});
