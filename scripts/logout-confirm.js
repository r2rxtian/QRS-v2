// logout-confirm.js — intercepts every logout link app-wide and confirms
// via a modal before navigating, so a stray click can't sign someone out
// mid-task. Builds its own modal markup so it works regardless of which
// shell a page uses (components/appshell_end.php vs dashboard.php's own
// inline shell).
//
// Uses a single delegated listener on document instead of binding to each
// logout link at DOMContentLoaded time -- that way it still works even if
// this script runs before the link exists yet, or the link gets replaced/
// re-rendered later, with no dependency on script/DOM ready ordering.
(function () {
    function ensureModal() {
        var wrap = document.getElementById('logoutConfirmModal');
        if (wrap) return wrap;

        wrap = document.createElement('div');
        wrap.id = 'logoutConfirmModal';
        wrap.className = 'modal-overlay';
        wrap.innerHTML =
            '<div class="modal">' +
                '<div class="modal-header">' +
                    '<h3 class="modal-title">Log Out?</h3>' +
                '</div>' +
                '<div class="modal-body">Are you sure you want to log out?</div>' +
                '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-secondary" id="logoutCancelBtn">Cancel</button>' +
                    '<button type="button" class="btn btn-danger-solid" id="logoutConfirmBtn">Log Out</button>' +
                '</div>' +
            '</div>';
        (document.body || document.documentElement).appendChild(wrap);

        wrap.querySelector('#logoutCancelBtn').addEventListener('click', function () {
            wrap.classList.remove('active');
        });
        wrap.addEventListener('click', function (e) {
            if (e.target === wrap) wrap.classList.remove('active');
        });
        wrap.querySelector('#logoutConfirmBtn').addEventListener('click', function () {
            window.location.href = wrap.dataset.href;
        });

        return wrap;
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest && e.target.closest('a[href*="logout.php"]');
        if (!link) return;

        e.preventDefault();
        var modal = ensureModal();
        modal.dataset.href = link.getAttribute('href');
        modal.classList.add('active');
    });
})();
