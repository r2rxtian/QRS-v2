// dashboard.js — paging the "Task Calendar" rail card between months,
// switching the "Tasks Overview" bar chart's range, both without a full
// page reload (see api/dashboard/calendar_partial.php and
// api/dashboard/bar_chart_partial.php); and animating the page's stat
// numbers/percentages, the Location Status donut, and the Task Completion
// Insights dome on load via GSAP.

const PREFERS_REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Counts a single number/percentage span up from 0 to its server-rendered
// value (data-count-to, optionally suffixed via data-suffix, e.g. "%").
function animateCountUp(el, delay) {
    const endValue = parseFloat(el.dataset.countTo);
    if (Number.isNaN(endValue)) return;
    const suffix = el.dataset.suffix || '';

    if (PREFERS_REDUCED_MOTION || typeof gsap === 'undefined') {
        el.textContent = endValue + suffix;
        return;
    }

    const proxy = { value: 0 };
    gsap.to(proxy, {
        value: endValue,
        duration: 1,
        delay: delay || 0,
        ease: 'power2.out',
        onUpdate: function () {
            el.textContent = Math.round(proxy.value) + suffix;
        },
    });
}

// Pops the "Task Completion Insights" dome from small to full size on load,
// anchored to the same bottom-center point it's already positioned at
// (bottom:0; left:50%) so it reads as rising/inflating from the card's base
// rather than scaling in from mid-air. xPercent:-50 redoes the stylesheet's
// own translateX(-50%) centering through GSAP instead -- once GSAP writes
// anything to the inline transform, it owns that property outright, so the
// centering has to be part of the same tween or it's lost the moment this
// runs.
function animateDome() {
    const dome = document.querySelector('.dome');
    if (!dome || PREFERS_REDUCED_MOTION || typeof gsap === 'undefined') return;

    gsap.fromTo(dome, {
        scale: 0.35,
        opacity: 0,
        xPercent: -50,
        transformOrigin: 'bottom center',
    }, {
        scale: 1,
        opacity: 1,
        xPercent: -50,
        duration: 0.9,
        ease: 'back.out(1.6)',
    });
}

// Grows each "Tasks Overview" bar up from 0 to its real height, staggered
// left-to-right. Reads the height already set on each .bar (by PHP on
// first load, or by loadBarChart() below on a range switch) rather than
// needing a separate data attribute.
function animateBarChart(container) {
    const bars = container.querySelectorAll('.bar');
    if (PREFERS_REDUCED_MOTION || typeof gsap === 'undefined') return;

    bars.forEach(function (bar, i) {
        const targetHeight = bar.style.height;
        gsap.fromTo(bar, { height: '0%' }, {
            height: targetHeight,
            duration: 0.6,
            delay: i * 0.05,
            ease: 'power2.out',
        });
    });
}

async function navigateCalendar(direction) {
    const grid = document.getElementById('calendarGrid');
    const label = document.getElementById('calendarMonthLabel');
    if (!grid || !label) return;

    const [year, month] = grid.dataset.month.split('-').map(Number);
    let newYear = year;
    let newMonth = month + direction;
    if (newMonth < 1) {
        newMonth = 12;
        newYear--;
    } else if (newMonth > 12) {
        newMonth = 1;
        newYear++;
    }
    const monthKey = newYear + '-' + String(newMonth).padStart(2, '0');

    grid.style.opacity = '0.5';

    try {
        const response = await fetch('../api/dashboard/calendar_partial.php?month=' + encodeURIComponent(monthKey));
        const data = await response.json();
        if (data.success) {
            label.textContent = data.data.month_label;
            grid.innerHTML = data.data.grid_html;
            grid.dataset.month = data.data.month_key;
        }
    } catch (err) {
        // Leave the calendar showing its previous month on failure.
    } finally {
        grid.style.opacity = '1';
    }
}

async function loadBarChart(range) {
    const container = document.getElementById('barChartContainer');
    if (!container) return;

    container.style.opacity = '0.5';

    try {
        const response = await fetch('../api/dashboard/bar_chart_partial.php?range=' + encodeURIComponent(range));
        const data = await response.json();
        if (!data.success) return;

        container.innerHTML = '';
        data.data.bars.forEach(function (bar) {
            const col = document.createElement('div');
            col.className = 'bar-col';

            const barEl = document.createElement('div');
            barEl.className = 'bar';
            barEl.style.height = bar.height + '%';
            barEl.title = bar.count + ' completed';

            const labelEl = document.createElement('span');
            labelEl.textContent = bar.label;

            col.appendChild(barEl);
            col.appendChild(labelEl);
            container.appendChild(col);
        });
        animateBarChart(container);
    } catch (err) {
        // Leave the chart showing its previous range on failure.
    } finally {
        container.style.opacity = '1';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.count-up').forEach(function (el, i) {
        animateCountUp(el, i * 0.05);
    });
    animateDome();
    const barChartContainer = document.getElementById('barChartContainer');
    if (barChartContainer) animateBarChart(barChartContainer);
});
