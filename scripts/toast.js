// toast.js — auto-dismissing notification. Used after actions (like
// create task, delete task, add user, complete scan) that shouldn't require
// an extra manual "Close" click on a blocking modal.
function showToast(message, type = 'success') {
    if (!message) return;

    let container = document.getElementById('qrs-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'qrs-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    const isError = type === 'error';
    const isSuccess = type === 'success';
    toast.className = 'qrs-toast ' + (isError ? 'qrs-toast-error' : isSuccess ? 'qrs-toast-success' : 'qrs-toast-info');

    const icon = document.createElement('i');
    icon.className = 'fas ' + (isError ? 'fa-circle-exclamation' : isSuccess ? 'fa-circle-check' : 'fa-circle-info');

    const textSpan = document.createElement('span');
    textSpan.className = 'qrs-toast-text';
    textSpan.textContent = message;

    toast.appendChild(icon);
    toast.appendChild(textSpan);

    // Allow clicking toast to immediately dismiss it
    toast.addEventListener('click', () => dismissToast(toast));

    container.appendChild(toast);

    requestAnimationFrame(() => {
        toast.classList.add('show');
    });

    toast._timeoutId = setTimeout(() => {
        dismissToast(toast);
    }, 3200);
}

function dismissToast(toast) {
    if (!toast || toast._dismissing) return;
    toast._dismissing = true;
    if (toast._timeoutId) clearTimeout(toast._timeoutId);
    toast.classList.remove('show');
    setTimeout(() => {
        toast.remove();
        const container = document.getElementById('qrs-toast-container');
        if (container && container.children.length === 0) {
            container.remove();
        }
    }, 250);
}

window.showToast = showToast;

