// toast.js — small auto-dismissing notification. Used after actions (like
// delete) that shouldn't require an extra manual "Close" click on top of
// the confirmation the user already gave.
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = 'qrs-toast ' + (type === 'error' ? 'qrs-toast-error' : 'qrs-toast-success');
    toast.textContent = message;
    document.body.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 250);
    }, 2500);
}
