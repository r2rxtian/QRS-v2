// dashboard.js — paging the "Task Calendar" rail card between months, and
// switching the "Tasks Overview" bar chart's range, both without a full
// page reload (see api/dashboard/calendar_partial.php and
// api/dashboard/bar_chart_partial.php).

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
    } catch (err) {
        // Leave the chart showing its previous range on failure.
    } finally {
        container.style.opacity = '1';
    }
}
