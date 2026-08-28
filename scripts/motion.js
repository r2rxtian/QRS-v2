// motion.js — GSAP page-transition layer, ported from DocHubPR's own
// scripts/motion.js. Every navigation in this app is a full page load (no
// client-side router), so "page transition" here means the same thing it
// does there: a reveal that plays once, right as the new page's markup
// finishes parsing, rather than a route-change animation.
//
// Degrades to a no-op (plain, instant page loads, exactly like before this
// file existed) when GSAP failed to load or the user has requested reduced
// motion -- nothing else on the page needs to branch for that; the HTML is
// already fully visible by default, this only ever hides-then-reveals it
// when animation is actually going to happen.
(() => {
    const gsap = window.gsap;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const enabled = !!gsap && !reduceMotion;

    if (!enabled) return;

    gsap.defaults({ ease: 'power2.out', duration: 0.4 });
    document.documentElement.classList.add('js-motion');

    // The page transition itself: .main-content fades/slides in on every
    // navigation. .profile-sidebar/.icon-rail are its siblings, not its
    // descendants (see components/appshell_start.php), so they're never
    // touched here -- they render at full opacity immediately and stay
    // static across the transition, same as the plain-CSS version of this
    // this replaces (see styles/app.css's own history on that).
    const main = document.querySelector('.main-content');
    if (main) {
        gsap.set(main, { opacity: 0, y: 10 });
        gsap.to(main, { opacity: 1, y: 0, duration: 0.35, clearProps: 'all' });
    }

    // Staggered reveal of whatever repeating content the current page
    // actually has -- same technique DocHub uses for its own folder tiles/
    // grid/table rows, pointed at QRS's equivalents instead. Pre-hidden
    // synchronously (before first paint) via gsap.set, then animated in, so
    // nothing flashes at full opacity before its tween starts. A page
    // missing one of these (e.g. no .stat-tiles) just contributes nothing
    // to `matched` -- no per-page wiring needed.
    const groups = [
        '.stat-tiles > .stat-tile',
        'table tbody tr',
    ];
    const matched = groups
        .map((selector) => document.querySelectorAll(selector))
        .filter((list) => list.length);

    matched.forEach((list) => gsap.set(list, { opacity: 0, y: 10 }));
    matched.forEach((list) => gsap.to(list, {
        opacity: 1, y: 0, duration: 0.35, stagger: 0.03, overwrite: true, clearProps: 'all',
    }));
})();
