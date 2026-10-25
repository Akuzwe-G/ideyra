import { Chart, LineController, BarController, DoughnutController, LineElement, PointElement, BarElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js';

Chart.register(LineController, BarController, DoughnutController, LineElement, PointElement, BarElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend);

const root = document.querySelector('[data-analytics]');
const workspace = root.closest('.workspace');
const palette = ['#3759D7', '#087F8C', '#8B5FC7', '#BB6B12', '#B33A51', '#527482'];
const instances = new Map();

const background = {
    id: 'exportBackground',
    beforeDraw(chart) {
        const { ctx, width, height } = chart;
        ctx.save();
        ctx.globalCompositeOperation = 'destination-over';
        ctx.fillStyle = workspace.dataset.dashboardTheme === 'dark' ? '#192741' : '#FFFFFF';
        ctx.fillRect(0, 0, width, height);
        ctx.restore();
    },
};

function render() {
    const dark = document.documentElement.dataset.theme === 'dark';
    root.dataset.theme = dark ? 'dark' : 'light';
    workspace.dataset.dashboardTheme = root.dataset.theme;
    const text = dark ? '#DFE8FA' : '#132447';
    const grid = dark ? '#34435D' : '#DFE5F0';
    const colors = dark ? ['#8CA7FF', '#57D3DC', '#C6A4FA', '#F5BE72', '#F291A3', '#A6C2D2'] : palette;
    root.querySelectorAll('[data-chart]').forEach(card => {
        instances.get(card)?.destroy();
        instances.delete(card);
        const config = JSON.parse(card.dataset.chart);
        const canvas = card.querySelector('canvas');
        if (!canvas) return;
        card.querySelector('.analytics-canvas').hidden = false;
        const labels = config.type === 'bar' ? config.labels.slice(0, 15) : config.labels;
        const values = config.values.slice(0, labels.length);
        const doughnut = config.type === 'doughnut';
        const bar = config.type === 'bar';
        const total = values.reduce((sum, value) => sum + (value ?? 0), 0);
        const drilldown = index => {
            if (!config.links?.[index]) return;
            const target = new URL(config.links[index], location.origin);
            if (target.origin === location.origin) location.assign(target.href);
        };
        const chart = new Chart(canvas, {
            type: config.type,
            data: { labels, datasets: [{ label: config.unit, data: values, backgroundColor: doughnut ? colors : colors[0], borderColor: doughnut ? (dark ? '#192741' : '#FFFFFF') : colors[0], borderWidth: doughnut ? 3 : 2, borderRadius: bar ? 4 : 0, pointRadius: 2, pointHoverRadius: 5, tension: 0.15 }] },
            plugins: [background],
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                onClick: (_event, elements) => { if (elements.length) drilldown(elements[0].index); },
                indexAxis: bar ? 'y' : 'x',
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    legend: { display: !doughnut, position: 'bottom', labels: { color: text, boxWidth: 12, font: { family: 'Montserrat' } } },
                    tooltip: { callbacks: { label: context => `${config.unit}: ${context.raw ?? 'Protected / unavailable'}${doughnut && total > 0 ? ` (${(context.raw / total * 100).toFixed(1)}%)` : ''}` } },
                },
                ...(doughnut ? { cutout: '68%' } : { scales: {
                    x: { title: { display: true, text: bar ? config.unit : 'Period starting', color: text }, grid: { color: grid }, ticks: { color: text, ...(bar ? { precision: 0 } : { maxTicksLimit: 6, maxRotation: 0 }) }, beginAtZero: bar },
                    y: { title: { display: true, text: bar ? 'Category' : config.unit, color: text }, grid: { color: grid }, ticks: { color: text, ...(bar ? { autoSkip: false, callback(value) { const label = this.getLabelForValue(value); return label.length > 24 ? label.slice(0, 23) + '…' : label; } } : { precision: 0 }) }, beginAtZero: !bar },
                } }),
            },
        });
        instances.set(card, chart);
        card.dataset.chartReady = 'true';
        const legend = card.querySelector('.analytics-legend');
        legend.replaceChildren();
        if (doughnut) labels.forEach((label, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = `${label}: ${values[index] ?? 'Protected / unavailable'}${total > 0 ? ` (${(values[index] / total * 100).toFixed(1)}%)` : ''}`;
            button.style.setProperty('--legend-color', colors[index % colors.length]);
            button.setAttribute('aria-pressed', 'true');
            button.addEventListener('click', () => {
                chart.toggleDataVisibility(index);
                button.setAttribute('aria-pressed', String(chart.getDataVisibility(index)));
                chart.update('none');
            });
            legend.append(button);
        });
        let active = -1;
        canvas.onkeydown = event => {
            if (event.key === 'Enter' && active >= 0) { drilldown(active); return; }
            if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Escape'].includes(event.key)) return;
            event.preventDefault();
            active = event.key === 'Escape' ? -1 : (active + (['ArrowLeft', 'ArrowUp'].includes(event.key) ? -1 : 1) + labels.length) % labels.length;
            const elements = active < 0 ? [] : [{ datasetIndex: 0, index: active }];
            chart.setActiveElements(elements);
            const point = active < 0 ? { x: 0, y: 0 } : chart.getDatasetMeta(0).data[active].getCenterPoint();
            chart.tooltip.setActiveElements(elements, point);
            chart.update('none');
            root.querySelector('[data-analytics-status]').textContent = active < 0 ? 'Chart selection cleared.' : `${config.title}: ${labels[active]}, ${values[active]} ${config.unit}.`;
        };
        const png = card.querySelector('[data-chart-png]');
        png.hidden = false;
        png.onclick = () => {
            const link = document.createElement('a');
            link.download = `ideyra-${config.key}.png`;
            link.href = chart.toBase64Image();
            link.click();
        };
    });
    root.querySelector('[data-analytics-status]').textContent = 'Analytics ready. Use chart arrow keys, legend buttons or the data tables to explore.';
}

document.addEventListener('ideyra:themechange', render);
root.querySelector('form')?.addEventListener('submit', () => {
    root.setAttribute('aria-busy', 'true');
    const button = root.querySelector('[type=submit]');
    button.textContent = 'Updating analytics…';
    button.disabled = true;
    root.querySelector('[data-analytics-status]').textContent = 'Loading the selected date range…';
});
const dispose = () => { instances.forEach(chart => chart.destroy()); instances.clear(); };
window.addEventListener('pagehide', dispose);
document.addEventListener('livewire:navigating', dispose);
window.addEventListener('pageshow', event => { if (event.persisted) { root.removeAttribute('aria-busy'); root.querySelector('[type=submit]').disabled = false; root.querySelector('[type=submit]').textContent = 'Apply filters'; render(); } });
render();
