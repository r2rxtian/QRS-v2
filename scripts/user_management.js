// user_management.js — User Management page (Add User, role/status changes)

function showModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function showMessage(message, type = 'info') {
    if (type === 'success' && typeof showToast === 'function') {
        showToast(message, 'success');
        return;
    }
    const titleEl = document.getElementById('msgTitle');
    const bodyEl = document.getElementById('msgBody');
    titleEl.textContent = type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Notification';
    bodyEl.textContent = message;
    showModal('messageModal');
}

function onAddUserBiometricsInput(input) {
    input.closest('.input-clearable').classList.toggle('has-value', input.value.length > 0);
}

function clearAddUserBiometrics() {
    const input = document.getElementById('add_user_biometrics_input');
    input.value = '';
    input.closest('.input-clearable').classList.remove('has-value');
    input.focus();
    hideAddUserFoundState();
}

function hideAddUserFoundState() {
    document.getElementById('addUserResult').style.display = 'none';
    document.getElementById('addUserDivider').style.display = 'none';
    document.getElementById('addUserRoleGroup').style.display = 'none';
    document.getElementById('addUserInfoBanner').style.display = 'none';
    document.getElementById('addUserWarning').style.display = 'none';
    document.getElementById('addUserSubmitBtn').disabled = true;
    document.getElementById('addUserSubmitBtn').style.display = '';
}

async function lookupUserBiometrics() {
    const input = document.getElementById('add_user_biometrics_input');
    const btn = document.getElementById('lookupUserBtn');
    const biometricsId = input.value.trim();
    const submitBtn = document.getElementById('addUserSubmitBtn');

    if (!biometricsId) {
        showMessage('Please enter a biometrics number.', 'error');
        return;
    }

    hideAddUserFoundState();
    btn.disabled = true;
    try {
        const response = await fetch('../api/users/lookup.php?biometrics_id=' + encodeURIComponent(biometricsId));
        const data = await response.json();

        if (!data.success) {
            showMessage(data.message, 'error');
            return;
        }

        const person = data.data;
        const photoEl = document.getElementById('addUserPhoto');
        photoEl.onerror = function () { this.style.visibility = 'hidden'; };
        photoEl.style.visibility = 'visible';
        photoEl.src = person.photo_url || '';
        document.getElementById('addUserName').textContent = person.full_name;
        document.getElementById('addUserPosition').textContent = person.position || '—';
        document.getElementById('addUserDepartment').textContent = person.department || '—';

        // Already-registered is a dead end, not a step toward adding anyone --
        // the Role picker, the "no password to set" tip, and the Add User
        // button are all only relevant when there's actually something left
        // to submit, so skip straight to a minimal "here's who this is,
        // they're already set up" view instead of showing controls that
        // can't be used.
        const warningEl = document.getElementById('addUserWarning');
        const alreadyExists = person.already_exists;
        if (alreadyExists) {
            warningEl.textContent = 'This person already has a QRS account.';
            warningEl.style.display = 'block';
            submitBtn.disabled = true;
        } else if (!person.has_login) {
            warningEl.textContent = "This person has no active company login yet -- they can be added now, but won't be able to log in until that's provisioned.";
            warningEl.style.display = 'block';
            submitBtn.disabled = false;
        } else {
            warningEl.style.display = 'none';
            submitBtn.disabled = false;
        }

        document.getElementById('addUserResult').style.display = 'flex';
        document.getElementById('addUserDivider').style.display = alreadyExists ? 'none' : 'block';
        document.getElementById('addUserRoleGroup').style.display = alreadyExists ? 'none' : 'flex';
        document.getElementById('addUserInfoBanner').style.display = alreadyExists ? 'none' : 'flex';
        submitBtn.style.display = alreadyExists ? 'none' : '';
    } catch (err) {
        showMessage('Could not reach the server. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

async function submitAddUser() {
    const biometricsId = document.getElementById('add_user_biometrics_input').value.trim();
    const role = document.getElementById('add_user_role_input').value;
    const btn = document.getElementById('addUserSubmitBtn');

    if (!biometricsId) {
        showMessage('Please look up a biometrics number first.', 'error');
        return;
    }

    const formData = new FormData();
    formData.set('csrf_token', QRS_CSRF_TOKEN);
    formData.set('biometrics_id', biometricsId);
    formData.set('role', role);

    btn.disabled = true;
    try {
        const response = await fetch('../api/users/create.php', { method: 'POST', body: formData });
        const data = await response.json();

        closeAddUserModal();
        if (data.success) {
            showToast(data.message, 'success');
            await window.QRSRealtime?.refresh();
        } else {
            showMessage(data.message, data.type || 'error');
        }
    } catch (err) {
        closeAddUserModal();
        showMessage('Could not reach the server. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

function closeAddUserModal() {
    closeModal('addUserModal');
    const input = document.getElementById('add_user_biometrics_input');
    input.value = '';
    input.closest('.input-clearable').classList.remove('has-value');
    hideAddUserFoundState();

    // Reset the role dropdown back to its default (User) for the next open.
    const dropdown = document.querySelector('#addUserModal .select-dropdown');
    if (dropdown) {
        dropdown.dataset.value = 'User';
        dropdown.querySelector('.select-dropdown-label').textContent = 'User';
        dropdown.querySelectorAll('.select-dropdown-option').forEach(o => o.classList.toggle('selected', o.dataset.value === 'User'));
    }
    const nativeSelect = document.getElementById('add_user_role_input');
    if (nativeSelect) nativeSelect.value = 'User';
}

async function changeUserRole(button) {
    const row = button.closest('tr');
    const userId = row.dataset.userId;
    const newRole = row.dataset.role === 'Admin' ? 'User' : 'Admin';

    closeAllKebabs();
    button.disabled = true;
    try {
        const formData = new FormData();
        formData.set('csrf_token', QRS_CSRF_TOKEN);
        formData.set('user_id', userId);
        formData.set('role', newRole);

        const response = await fetch('../api/users/update_role.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            row.dataset.role = newRole;
            const badge = row.querySelector('[data-role-badge]');
            if (badge) {
                badge.textContent = newRole;
                badge.classList.toggle('role-badge-admin', newRole === 'Admin');
                badge.classList.toggle('role-badge-user', newRole !== 'Admin');
            }
            const actionLabel = row.querySelector('[data-role-action-label]');
            if (actionLabel) actionLabel.textContent = 'Make ' + (newRole === 'Admin' ? 'User' : 'Admin');
            if (typeof applyTableFilters === 'function') applyTableFilters('usersTable');
            showToast(data.message, 'success');
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast('Could not reach the server. Please try again.', 'error');
    } finally {
        button.disabled = false;
    }
}

async function changeUserStatus(button) {
    const row = button.closest('tr');
    const userId = row.dataset.userId;
    const willBeActive = row.dataset.status !== 'Active';

    closeAllKebabs();
    button.disabled = true;
    try {
        const formData = new FormData();
        formData.set('csrf_token', QRS_CSRF_TOKEN);
        formData.set('user_id', userId);
        formData.set('is_active', willBeActive ? '1' : '0');

        const response = await fetch('../api/users/update_status.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            row.dataset.status = willBeActive ? 'Active' : 'Inactive';
            const badge = row.querySelector('[data-status-badge]');
            if (badge) {
                badge.classList.toggle('status-available', willBeActive);
                badge.classList.toggle('status-inactive', !willBeActive);
                badge.innerHTML = '<i class="fas ' + (willBeActive ? 'fa-circle-check' : 'fa-circle-minus') + '"></i> ' + (willBeActive ? 'Active' : 'Inactive');
            }
            button.classList.toggle('kebab-danger', willBeActive);
            const actionLabel = row.querySelector('[data-status-action-label]');
            if (actionLabel) actionLabel.textContent = willBeActive ? 'Deactivate' : 'Activate';
            if (typeof applyTableFilters === 'function') applyTableFilters('usersTable');
            showToast(data.message, 'success');
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast('Could not reach the server. Please try again.', 'error');
    } finally {
        button.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('usersTable') && typeof paginateTable === 'function') {
        const usersPager = paginateTable({ tableId: 'usersTable', paginationId: 'usersPagination', rowsPerPage: 10 });
        window.onUsersEntriesChange = function(value) {
            usersPager.setRowsPerPage(value === 'all' ? 'all' : parseInt(value, 10));
        };
    }
});
