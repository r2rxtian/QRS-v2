// dashboard.js — paging the "Task Calendar" rail card between months,
// switching the "Tasks Overview" bar chart's range, both without a full
// page reload (see api/dashboard/calendar_partial.php and
// api/dashboard/bar_chart_partial.php); and animating the page's stat
// numbers/percentages and the Location Status donut on load via GSAP.

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

// Sweeps the Location Status ring's conic-gradient from 0% up to its real
// Completed/On-going/Missed/Not Started split, matching the count-up
// happening in its center label and legend at the same time.
function animateLocationDonut(el) {
    if (!el || el.dataset.hasData !== '1') return;

    const completed = parseFloat(el.dataset.completed) || 0;
    const ongoing = parseFloat(el.dataset.ongoing) || 0;
    const missed = parseFloat(el.dataset.missed) || 0;

    const paintDonut = function (c, o, m) {
        const ongoingEnd = c + o;
        const missedEnd = ongoingEnd + m;
        el.style.background = 'conic-gradient(var(--success) 0% ' + c + '%, var(--sky) ' + c + '% ' + ongoingEnd
            + '%, var(--danger) ' + ongoingEnd + '% ' + missedEnd + '%, var(--dusty-purple) ' + missedEnd + '% 100%)';
    };

    if (PREFERS_REDUCED_MOTION || typeof gsap === 'undefined') {
        paintDonut(completed, ongoing, missed);
        return;
    }

    const proxy = { c: 0, o: 0, m: 0 };
    gsap.to(proxy, {
        c: completed,
        o: ongoing,
        m: missed,
        duration: 1.2,
        ease: 'power2.out',
        onUpdate: function () {
            paintDonut(proxy.c, proxy.o, proxy.m);
        },
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
    animateLocationDonut(document.getElementById('locationDonut'));
    const barChartContainer = document.getElementById('barChartContainer');
    if (barChartContainer) animateBarChart(barChartContainer);
});
