// tasks.js — Modal, pagination, and delete controls for tasks.php

function showModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function showMessage(message, type = 'info') {
    const titleEl = document.getElementById('msgTitle');
    const bodyEl = document.getElementById('msgBody');
    titleEl.textContent = type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Notification';
    bodyEl.textContent = message;
    showModal('messageModal');
}

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = checkbox.checked);
    updateTaskSelectedCount();
}

function updateTaskSelectedCount() {
    const el = document.getElementById('taskSelectedCount');
    if (!el) return;
    const count = document.querySelectorAll('.row-check:checked').length;
    el.textContent = count > 0 ? `${count} ${count === 1 ? 'entry' : 'entries'} selected` : '';
}

document.addEventListener('change', function (e) {
    if (e.target.classList.contains('row-check')) updateTaskSelectedCount();
});

function deleteSelected() {
    const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.value);
    if (!ids.length) {
        showMessage('No tasks selected.', 'error');
        return;
    }

    document.getElementById('confirmBody').textContent = `Are you sure you want to delete ${ids.length} selected task(s)? This cannot be undone.`;

    const yesBtn = document.getElementById('confirmBtn');
    const newYesBtn = yesBtn.cloneNode(true);
    yesBtn.parentNode.replaceChild(newYesBtn, yesBtn);

    newYesBtn.addEventListener('click', async function () {
        newYesBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.set('csrf_token', QRS_CSRF_TOKEN);
            ids.forEach(id => formData.append('task_ids[]', id));

            const response = await fetch('../api/tasks/bulk_delete.php', { method: 'POST', body: formData });
            const data = await response.json();

            closeModal('confirmModal');
            showToast(data.message, data.type || (data.success ? 'success' : 'error'));

            if (data.success) {
                setTimeout(() => window.location.reload(), 1000);
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

// A task is fixed to one Task Type at creation, so the location picker (in
// the modal's right-hand panel) is split into one checklist per type; only
// the chosen type's checklist is shown (and only it is read from at submit
// time). The left panel's "N location(s) selected" summary follows whichever
// type is currently active by retargeting its data-count-for -- the actual
// counting is done by updateChecklistCount() (scripts/location-search.js),
// which already updates every element sharing that target on any checkbox
// change, so no separate polling/syncing is needed here beyond that retarget.
function onTaskTypeChange() {
    const selected = document.getElementById('task_type').value;
    document.querySelectorAll('.task-type-locations').forEach(function (box) {
        box.style.display = box.id === 'taskTypeLocations_' + selected ? '' : 'none';
    });

    const emptyState = document.getElementById('taskLocationsEmptyState');
    if (emptyState) {
        emptyState.style.display = selected ? 'none' : '';
    }

    const summaryCount = document.getElementById('taskLocationsSummaryCount');
    if (summaryCount) {
        const listId = 'task_locations_select_' + selected;
        summaryCount.dataset.countFor = listId;
        if (typeof updateChecklistCount === 'function') updateChecklistCount(listId);
    }
}

// Task Type is picked via two big cards (not a dropdown) -- clicking one
// just mirrors its value into the real, hidden <select id="task_type"> and
// dispatches a change event, same pattern .select-dropdown uses elsewhere,
// so onTaskTypeChange() (wired to that select's onchange) doesn't need to
// know or care how the value got set.
function selectTaskTypeCard(button) {
    document.querySelectorAll('.task-type-card').forEach(c => c.classList.remove('selected'));
    button.classList.add('selected');

    const select = document.getElementById('task_type');
    select.value = button.dataset.value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

function openCreateTaskModal() {
    const typeSelect = document.getElementById('task_type');
    if (typeSelect) {
        typeSelect.value = '';
        document.querySelectorAll('.task-type-card').forEach(c => c.classList.remove('selected'));
        onTaskTypeChange();
    }
    showModal('createTaskModal');
}

async function submitCreateTask() {
    const nameInput = document.getElementById('task_name');
    const typeSelect = document.getElementById('task_type');
    const dateInput = document.getElementById('task_date');
    const btn = document.getElementById('createTaskSubmitBtn');

    const taskName = nameInput.value.trim();
    const taskType = typeSelect ? typeSelect.value : '';
    const taskDate = dateInput ? dateInput.value : '';
    // Only exists once a Task Type is chosen (see onTaskTypeChange()) --
    // with none chosen yet, there's nothing to read locations from, which
    // is exactly the "Locations" error below.
    const locationsSelect = taskType ? document.getElementById('task_locations_select_' + taskType) : null;
    const selectedLocationIds = locationsSelect
        ? Array.from(locationsSelect.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value)
        : [];

    // Collect every missing/invalid field before showing anything -- a user
    // who fixes one field and resubmits should never be met with a second,
    // previously-hidden error; they see the complete list up front instead.
    const errors = [];
    if (!taskName) errors.push('Task Name');
    if (!taskType) errors.push('Task Type');
    if (!taskDate) errors.push('Schedule Date');
    if (!selectedLocationIds.length) errors.push('Locations (select at least one)');

    if (errors.length > 0) {
        // #msgBody is white-space:pre-line (see styles/app.css), so the \n
        // below render as real line breaks, not a wall of collapsed text.
        showMessage('Please complete the following required fields:\n\n' + errors.map(e => '• ' + e).join('\n'), 'error');
        return;
    }

    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('task_name', taskName);
    formData.set('task_type', taskType);
    formData.set('task_date', taskDate);
    selectedLocationIds.forEach(id => formData.append('location_ids[]', id));

    btn.disabled = true;
    try {
        const response = await fetch('../api/tasks/create.php', { method: 'POST', body: formData });
        const data = await response.json();

        closeModal('createTaskModal');
        showMessage(data.message, data.type || (data.success ? 'success' : 'error'));

        if (data.success) {
            nameInput.value = '';
            setTimeout(() => window.location.reload(), 1000);
        }
    } catch (err) {
        closeModal('createTaskModal');
        showMessage('Could not reach the server. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

function confirmDelete(button) {
    closeAllKebabs();
    const row = button.closest('tr');
    const taskId = row.dataset.taskId;
    const taskName = row.querySelector('.location-name').textContent;
    document.getElementById('confirmBody').textContent = `Are you sure you want to delete "${taskName}"?`;

    const yesBtn = document.getElementById('confirmBtn');
    const newYesBtn = yesBtn.cloneNode(true);
    yesBtn.parentNode.replaceChild(newYesBtn, yesBtn);

    newYesBtn.addEventListener('click', async function () {
        newYesBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.set('csrf_token', QRS_CSRF_TOKEN);
            formData.set('task_id', taskId);

            const response = await fetch('../api/tasks/delete.php', { method: 'POST', body: formData });
            const data = await response.json();

            closeModal('confirmModal');
            showToast(data.message, data.type || (data.success ? 'success' : 'error'));

            if (data.success) {
                // Reload (not just row.remove()) so the stat tiles above the
                // table -- Total Tasks, On-going, Completed, Sub-Tasks
                // Completed -- stay in sync instead of showing pre-delete
                // counts until the user manually refreshes.
                setTimeout(() => window.location.reload(), 1000);
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

// Schedule Date field: the real <input type="date"> is never shown (see
// .date-field / date-picker.js) -- toggleDatePicker() renders a custom
// calendar grid instead of the native popup. Keeping the label text in
// sync with the input's value on every change is what makes this safe
// even though the input itself is invisible.
function formatDateLabel(isoDate) {
    if (!isoDate) return 'Select a date';
    const [y, m, d] = isoDate.split('-');
    return `${m}/${d}/${y}`;
}

document.addEventListener('DOMContentLoaded', function() {
    const tasksPager = paginateTable({ tableId: 'tasksTable', paginationId: 'tasksPagination', rowsPerPage: 10 });
    makeSortable('tasksTable', tasksPager);

    window.onTasksEntriesChange = function(value) {
        tasksPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
    };

    const dateInput = document.getElementById('task_date');
    const dateLabel = document.getElementById('task_date_label');
    if (dateInput && dateLabel) {
        dateInput.addEventListener('change', () => { dateLabel.textContent = formatDateLabel(dateInput.value); });
    }
});
