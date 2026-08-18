// location-search.js — search, "Select All", and a live selected-count for
// the .checkbox-list location pickers (Create Task's "Assign Locations",
// and the Task Detail modal's "Assign More Locations") which get unwieldy
// once there are dozens of locations.
//
// All delegated on document (not bound at DOMContentLoaded) so this still
// works for the Task Detail modal's copy of this markup, even though it's
// injected later via fetch() rather than present at page load.

function updateChecklistCount(listId) {
    const list = document.getElementById(listId);
    if (!list) return;
    const count = list.querySelectorAll('input[type="checkbox"]:checked').length;
    document.querySelectorAll('[data-count-for="' + listId + '"]').forEach(function (el) {
        el.textContent = count;
    });
}

document.addEventListener('input', function (e) {
    const input = e.target.closest('.location-search-input');
    if (!input) return;

    const target = document.getElementById(input.dataset.target);
    if (!target) return;

    const query = input.value.trim().toLowerCase();
    target.querySelectorAll('.checkbox-list-item').forEach(function (item) {
        const label = (item.dataset.label || item.textContent).toLowerCase();
        item.style.display = query !== '' && !label.includes(query) ? 'none' : '';
    });
});

document.addEventListener('change', function (e) {
    const selectAll = e.target.closest('.checklist-select-all-input');
    if (selectAll) {
        const target = document.getElementById(selectAll.dataset.target);
        if (target) {
            target.querySelectorAll('.checkbox-list-item').forEach(function (item) {
                if (item.style.display !== 'none') {
                    item.querySelector('input[type="checkbox"]').checked = selectAll.checked;
                }
            });
            updateChecklistCount(selectAll.dataset.target);
        }
        return;
    }

    const checkbox = e.target.closest('.checkbox-list input[type="checkbox"]');
    if (checkbox) {
        const list = checkbox.closest('.checkbox-list');
        if (list) {
            updateChecklistCount(list.id);
            const selectAllInput = document.querySelector('.checklist-select-all-input[data-target="' + list.id + '"]');
            if (selectAllInput && !checkbox.checked) {
                selectAllInput.checked = false;
            }
        }
    }
});
