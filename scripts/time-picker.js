// time-picker.js — Themed replacement for the browser-controlled
// <input type="time"> popup. The hidden input named by data-for remains the
// single source of truth and always contains either "HH:MM" (24-hour time)
// or an empty string, so existing task creation logic does not need to know
// about this presentation layer.

function findTimePickerParts(field) {
    const inputId = field?.dataset.for || '';
    return {
        input: inputId ? document.getElementById(inputId) : null,
        panel: inputId ? document.querySelector('.time-picker-panel[data-for="' + inputId + '"]') : null,
        trigger: field?.querySelector('.time-picker-trigger') || null,
    };
}

function twelveHourParts(value) {
    const match = /^(\d{2}):(\d{2})$/.exec(value || '');
    let hour24;
    let minute;

    if (match && Number(match[1]) < 24 && Number(match[2]) < 60) {
        hour24 = Number(match[1]);
        minute = Number(match[2]);
    } else {
        const now = new Date();
        hour24 = now.getHours();
        minute = now.getMinutes();
    }

    return {
        hour: String((hour24 % 12) || 12).padStart(2, '0'),
        minute: String(minute).padStart(2, '0'),
        period: hour24 >= 12 ? 'PM' : 'AM',
    };
}

function toTwentyFourHourValue(parts) {
    let hour = Number(parts.hour) % 12;
    if (parts.period === 'PM') hour += 12;
    return String(hour).padStart(2, '0') + ':' + parts.minute;
}

function formatTimePickerLabel(value) {
    if (!value) return '--:-- --';
    const parts = twelveHourParts(value);
    return parts.hour + ':' + parts.minute + ' ' + parts.period;
}

function syncTimePickerField(input) {
    if (!input) return;
    const label = input.closest('.time-field')?.querySelector('.time-picker-label');
    if (!label) return;
    label.textContent = formatTimePickerLabel(input.value);
    label.classList.toggle('placeholder', !input.value);
}

function setTimePickerValue(input, value, notify = false) {
    if (!input) return;
    input.value = /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value || '') ? value : '';
    syncTimePickerField(input);
    if (notify) input.dispatchEvent(new Event('change', { bubbles: true }));
}

function buildTimePickerOptions(panel) {
    if (panel.dataset.ready === 'true') return;
    const values = {
        hour: Array.from({ length: 12 }, (_, index) => String(index + 1).padStart(2, '0')),
        minute: Array.from({ length: 60 }, (_, index) => String(index).padStart(2, '0')),
        period: ['AM', 'PM'],
    };

    Object.entries(values).forEach(([part, options]) => {
        const list = panel.querySelector('.time-picker-list[data-part="' + part + '"]');
        if (!list) return;
        options.forEach(value => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'time-picker-option';
            option.dataset.value = value;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.tabIndex = -1;
            option.textContent = value;
            list.appendChild(option);
        });
    });
    panel.dataset.ready = 'true';
}

function selectTimePickerPart(panel, part, value, focusOption = false) {
    panel.dataset[part] = value;
    const list = panel.querySelector('.time-picker-list[data-part="' + part + '"]');
    if (!list) return;

    list.querySelectorAll('.time-picker-option').forEach(option => {
        const selected = option.dataset.value === value;
        option.classList.toggle('selected', selected);
        option.setAttribute('aria-selected', selected ? 'true' : 'false');
        option.tabIndex = selected ? 0 : -1;
        if (selected) {
            list.scrollTop = Math.max(0, option.offsetTop - ((list.clientHeight - option.offsetHeight) / 2));
            if (focusOption) option.focus({ preventScroll: true });
        }
    });

    const preview = panel.querySelector('.time-picker-preview');
    if (preview) preview.textContent = panel.dataset.hour + ':' + panel.dataset.minute + ' ' + panel.dataset.period;
}

function positionTimePickerPanel(trigger, panel) {
    const gap = 6;
    const edge = 8;
    const rect = trigger.getBoundingClientRect();
    const panelRect = panel.getBoundingClientRect();
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = document.documentElement.clientHeight;

    // Check if the trigger is situated inside a modal (e.g. Create Task modal)
    const modal = trigger.closest('.modal') || document.querySelector('#createTaskModal .modal');
    if (modal) {
        const modalRect = modal.getBoundingClientRect();
        const minLeft = Math.max(edge, modalRect.left + 10);
        const maxRight = Math.min(viewportWidth - edge, modalRect.right - 10);
        const minTop = Math.max(edge, modalRect.top + 10);
        const maxBottom = Math.min(viewportHeight - edge, modalRect.bottom - 12);

        // Keep horizontal position aligned with trigger, bounded within the modal
        const left = Math.max(minLeft, Math.min(rect.left, maxRight - panelRect.width));

        // Prefer opening directly below the trigger; if it would exceed the modal bottom,
        // clamp its bottom edge flush with the bottom of the modal so it never overlaps or protrudes.
        const below = rect.bottom + gap;
        let top = below;
        if (top + panelRect.height > maxBottom) {
            top = maxBottom - panelRect.height;
        }
        top = Math.max(minTop, top);

        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
        return;
    }

    const left = Math.max(edge, Math.min(rect.left, viewportWidth - panelRect.width - edge));
    const below = rect.bottom + gap;
    const above = rect.top - panelRect.height - gap;
    const top = below + panelRect.height <= viewportHeight - edge || above < edge ? below : above;
    panel.style.left = left + 'px';
    panel.style.top = Math.max(edge, top) + 'px';
}

function closeTimePickerPanel(panel, restoreFocus = false) {
    if (!panel) return;
    panel.classList.remove('open');
    panel.style.visibility = '';
    const input = document.getElementById(panel.dataset.for);
    const field = input?.closest('.time-field');
    const trigger = field?.querySelector('.time-picker-trigger');
    field?.classList.remove('open');
    trigger?.setAttribute('aria-expanded', 'false');
    if (restoreFocus) trigger?.focus();
}

function closeAllTimePickers(restoreFocus = false) {
    document.querySelectorAll('.time-picker-panel.open').forEach(panel => closeTimePickerPanel(panel, restoreFocus));
}

function toggleTimePicker(trigger) {
    const field = trigger.closest('.time-field');
    const { input, panel } = findTimePickerParts(field);
    if (!input || !panel) return;
    if (panel.classList.contains('open')) {
        closeTimePickerPanel(panel);
        return;
    }

    closeAllTimePickers();
    if (typeof closeAllSelectDropdowns === 'function') closeAllSelectDropdowns();
    if (typeof closeDatePickerPanel === 'function') {
        document.querySelectorAll('.date-picker-panel.open').forEach(closeDatePickerPanel);
    }

    buildTimePickerOptions(panel);
    const parts = twelveHourParts(input.value);
    panel.dataset.hour = parts.hour;
    panel.dataset.minute = parts.minute;
    panel.dataset.period = parts.period;
    document.body.appendChild(panel);
    panel.style.visibility = 'hidden';
    panel.classList.add('open');
    field.classList.add('open');
    trigger.setAttribute('aria-expanded', 'true');
    selectTimePickerPart(panel, 'hour', parts.hour);
    selectTimePickerPart(panel, 'minute', parts.minute);
    selectTimePickerPart(panel, 'period', parts.period);
    positionTimePickerPanel(trigger, panel);
    panel.style.visibility = '';
    requestAnimationFrame(() => panel.querySelector('.time-picker-list[data-part="hour"] .selected')?.focus({ preventScroll: true }));
}

function commitTimePicker(panel) {
    const input = document.getElementById(panel.dataset.for);
    if (!input) return;
    setTimePickerValue(input, toTwentyFourHourValue(panel.dataset), true);
    closeTimePickerPanel(panel, true);
}

function clearTimePicker(panel) {
    const input = document.getElementById(panel.dataset.for);
    setTimePickerValue(input, '', true);
    closeTimePickerPanel(panel, true);
}

function moveTimePickerOption(option, direction) {
    const options = Array.from(option.parentElement.querySelectorAll('.time-picker-option'));
    const currentIndex = options.indexOf(option);
    let nextIndex = currentIndex;
    if (direction === 'first') nextIndex = 0;
    else if (direction === 'last') nextIndex = options.length - 1;
    else nextIndex = Math.max(0, Math.min(options.length - 1, currentIndex + direction));
    const nextOption = options[nextIndex];
    if (nextOption) {
        nextOption.focus({ preventScroll: true });
        const list = nextOption.parentElement;
        list.scrollTop = Math.max(0, nextOption.offsetTop - ((list.clientHeight - nextOption.offsetHeight) / 2));
    }
}

document.addEventListener('click', function (event) {
    const trigger = event.target.closest('.time-picker-trigger');
    if (trigger) { toggleTimePicker(trigger); return; }

    const option = event.target.closest('.time-picker-option');
    if (option) {
        const panel = option.closest('.time-picker-panel');
        const part = option.closest('.time-picker-list').dataset.part;
        selectTimePickerPart(panel, part, option.dataset.value, true);
        return;
    }

    const action = event.target.closest('[data-time-picker-action]');
    if (action) {
        const panel = action.closest('.time-picker-panel');
        if (action.dataset.timePickerAction === 'done') commitTimePicker(panel);
        else if (action.dataset.timePickerAction === 'clear') clearTimePicker(panel);
        else closeTimePickerPanel(panel, true);
        return;
    }

    if (!event.target.closest('.time-picker-panel') && !event.target.closest('.time-field')) closeAllTimePickers();
});

document.addEventListener('keydown', function (event) {
    const trigger = event.target.closest('.time-picker-trigger');
    if (trigger && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
        event.preventDefault();
        if (trigger.getAttribute('aria-expanded') !== 'true') toggleTimePicker(trigger);
        return;
    }

    const option = event.target.closest('.time-picker-option');
    if (option) {
        if (event.key === 'ArrowDown') { event.preventDefault(); moveTimePickerOption(option, 1); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); moveTimePickerOption(option, -1); }
        else if (event.key === 'Home') { event.preventDefault(); moveTimePickerOption(option, 'first'); }
        else if (event.key === 'End') { event.preventDefault(); moveTimePickerOption(option, 'last'); }
        else if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            const panel = option.closest('.time-picker-panel');
            const lists = Array.from(panel.querySelectorAll('.time-picker-list'));
            const nextList = lists[lists.indexOf(option.closest('.time-picker-list')) + (event.key === 'ArrowRight' ? 1 : -1)];
            nextList?.querySelector('.selected')?.focus({ preventScroll: true });
        }
    }

    if (event.key === 'Escape') {
        const openPanel = document.querySelector('.time-picker-panel.open');
        if (openPanel) {
            event.preventDefault();
            closeTimePickerPanel(openPanel, true);
        }
    }
});

function repositionOpenTimePickers() {
    document.querySelectorAll('.time-picker-panel.open').forEach(panel => {
        const input = document.getElementById(panel.dataset.for);
        const trigger = input?.closest('.time-field')?.querySelector('.time-picker-trigger');
        if (trigger) positionTimePickerPanel(trigger, panel);
    });
}

window.addEventListener('resize', repositionOpenTimePickers);
document.addEventListener('scroll', repositionOpenTimePickers, true);

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.time-field input').forEach(input => {
        syncTimePickerField(input);
        input.addEventListener('change', () => syncTimePickerField(input));
    });
});
