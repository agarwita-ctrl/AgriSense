/* =====================================================================
   AgriSense - Chart.js helpers

   All charts use the category scale with pre-formatted labels, so no date
   adapter is needed and the whole dashboard works with no internet access.
   Threshold and target lines are drawn as flat datasets rather than with a
   plugin, for the same reason.
   ===================================================================== */
(function () {
    'use strict';

    const palette = {
        moisture:  '#0288d1',
        temp:      '#f9a825',
        // Canopy sits on the same axis as air temperature, so it needs to
        // read as a different measurement at a glance, not a lighter shade
        // of the same one.
        leaf:      '#d84315',
        humidity:  '#26a69a',
        auto:      '#2e7d32',
        manual:    '#8d6e4f',
        threshold: '#c62828',
        target:    '#2e7d32',
        grid:      'rgba(31, 42, 36, .08)',
        ink:       '#6b7c72'
    };

    /** Soft vertical gradient under a line, falling back to a flat tint. */
    function fill(ctx, hex) {
        const area = ctx.chartArea;
        if (!area) return hexToRgba(hex, 0.12);
        const gradient = ctx.ctx.createLinearGradient(0, area.top, 0, area.bottom);
        gradient.addColorStop(0, hexToRgba(hex, 0.24));
        gradient.addColorStop(1, hexToRgba(hex, 0.01));
        return gradient;
    }

    function hexToRgba(hex, alpha) {
        const n = parseInt(hex.slice(1), 16);
        return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
    }

    /** "05 Sep 14:30" - compact enough for a phone-width axis. */
    function formatBucket(value, spanDays) {
        const d = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return String(value);

        if (spanDays > 3) {
            return d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' })
                + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    function spanInDays(rows) {
        if (rows.length < 2) return 1;
        const first = new Date(String(rows[0].bucket).replace(' ', 'T')).getTime();
        const last = new Date(String(rows[rows.length - 1].bucket).replace(' ', 'T')).getTime();
        return Math.max(1, (last - first) / 86400000);
    }

    const baseOptions = (unit) => ({
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: true,
                position: 'top',
                align: 'end',
                labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11 } }
            },
            tooltip: {
                callbacks: {
                    label: (item) => {
                        const value = item.parsed.y;
                        return ` ${item.dataset.label}: ${value === null ? 'no reading' : value + unit}`;
                    }
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { color: palette.ink, maxRotation: 0, autoSkip: true, maxTicksLimit: 8, font: { size: 10 } }
            },
            y: {
                grid: { color: palette.grid },
                ticks: { color: palette.ink, font: { size: 10 }, callback: (v) => v + unit },
                beginAtZero: false
            }
        },
        elements: { point: { radius: 0, hitRadius: 12, hoverRadius: 4 } }
    });

    const charts = {
        palette,

        /**
         * Soil moisture over time, with the ON and OFF thresholds drawn in.
         *
         * @param {HTMLCanvasElement} canvas
         * @param {Array} rows      series rows from the API
         * @param {Object} settings {moisture_threshold, target_moisture}
         */
        moisture(canvas, rows, settings) {
            const span = spanInDays(rows);
            const labels = rows.map((r) => formatBucket(r.bucket, span));
            const datasets = [{
                label: 'Soil moisture',
                data: rows.map((r) => (r.soil_moisture === null ? null : Number(r.soil_moisture))),
                borderColor: palette.moisture,
                backgroundColor: (ctx) => fill(ctx.chart, palette.moisture),
                borderWidth: 2,
                fill: true,
                tension: 0.32,
                spanGaps: false
            }];

            if (settings) {
                datasets.push({
                    label: `Pump ON at ${Number(settings.moisture_threshold).toFixed(0)}%`,
                    data: labels.map(() => Number(settings.moisture_threshold)),
                    borderColor: palette.threshold,
                    borderWidth: 1.5,
                    borderDash: [6, 4],
                    pointRadius: 0,
                    fill: false
                }, {
                    label: `Pump OFF at ${Number(settings.target_moisture).toFixed(0)}%`,
                    data: labels.map(() => Number(settings.target_moisture)),
                    borderColor: palette.target,
                    borderWidth: 1.5,
                    borderDash: [6, 4],
                    pointRadius: 0,
                    fill: false
                });
            }

            const options = baseOptions('%');
            options.scales.y.min = 0;
            options.scales.y.max = 100;

            return new Chart(canvas, { type: 'line', data: { labels, datasets }, options });
        },

        /**
         * Temperature trend: air, and the canopy above it when an MLX90614
         * is fitted.
         *
         * Both lines share one axis on purpose. The distance between them is
         * the whole point - a canopy pulling away from the air line is a crop
         * that has stopped cooling itself - and separate axes would scale
         * that gap away.
         */
        temperature(canvas, rows, options) {
            const span = spanInDays(rows);
            const withLeaf = Boolean(options && options.leaf);

            const datasets = [{
                label: withLeaf ? 'Air' : 'Temperature',
                agKey: 'temperature',
                data: rows.map((r) => (r.temperature === null ? null : Number(r.temperature))),
                borderColor: palette.temp,
                backgroundColor: (ctx) => fill(ctx.chart, palette.temp),
                borderWidth: 2,
                fill: true,
                tension: 0.32,
                spanGaps: false
            }];

            if (withLeaf) {
                datasets.push({
                    label: 'Canopy',
                    agKey: 'leaf_temperature',
                    data: rows.map((r) => (r.leaf_temperature === null ? null : Number(r.leaf_temperature))),
                    borderColor: palette.leaf,
                    backgroundColor: palette.leaf,
                    borderWidth: 2,
                    borderDash: [5, 3],
                    fill: false,
                    tension: 0.32,
                    spanGaps: false
                });
            }

            return new Chart(canvas, {
                type: 'line',
                data: { labels: rows.map((r) => formatBucket(r.bucket, span)), datasets },
                options: baseOptions(' °C')
            });
        },

        /** Humidity trend. */
        humidity(canvas, rows) {
            const span = spanInDays(rows);
            const options = baseOptions('%');
            options.scales.y.min = 0;
            options.scales.y.max = 100;

            return new Chart(canvas, {
                type: 'line',
                data: {
                    labels: rows.map((r) => formatBucket(r.bucket, span)),
                    datasets: [{
                        label: 'Humidity',
                        data: rows.map((r) => (r.humidity === null ? null : Number(r.humidity))),
                        borderColor: palette.humidity,
                        backgroundColor: (ctx) => fill(ctx.chart, palette.humidity),
                        borderWidth: 2,
                        fill: true,
                        tension: 0.32,
                        spanGaps: false
                    }]
                },
                options
            });
        },

        /**
         * Irrigation activity: stacked cycle counts per day plus the total
         * pump runtime on a second axis.
         */
        activity(canvas, rows) {
            const labels = rows.map((r) => {
                const d = new Date(String(r.day) + 'T00:00:00');
                return Number.isNaN(d.getTime())
                    ? String(r.day)
                    : d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' });
            });

            return new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Automatic cycles',
                            data: rows.map((r) => Number(r.automatic_cycles || 0)),
                            backgroundColor: palette.auto,
                            borderRadius: 4,
                            stack: 'cycles',
                            yAxisID: 'y'
                        },
                        {
                            label: 'Manual cycles',
                            data: rows.map((r) => Number(r.manual_cycles || 0)),
                            backgroundColor: palette.manual,
                            borderRadius: 4,
                            stack: 'cycles',
                            yAxisID: 'y'
                        },
                        {
                            label: 'Pump runtime (minutes)',
                            data: rows.map((r) => Number(r.minutes || 0)),
                            type: 'line',
                            borderColor: palette.moisture,
                            backgroundColor: palette.moisture,
                            borderWidth: 2,
                            tension: 0.3,
                            pointRadius: 3,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top', align: 'end',
                            labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11 } }
                        }
                    },
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { color: palette.ink, font: { size: 10 } } },
                        y: {
                            stacked: true, beginAtZero: true, position: 'left',
                            title: { display: true, text: 'Cycles', color: palette.ink, font: { size: 10 } },
                            ticks: { precision: 0, color: palette.ink, font: { size: 10 } },
                            grid: { color: palette.grid }
                        },
                        y1: {
                            beginAtZero: true, position: 'right',
                            title: { display: true, text: 'Minutes', color: palette.ink, font: { size: 10 } },
                            ticks: { color: palette.ink, font: { size: 10 } },
                            grid: { drawOnChartArea: false }
                        }
                    }
                }
            });
        },

        /** Swap in a fresh series without rebuilding the chart. */
        replaceSeries(chart, rows, key, settings) {
            const span = spanInDays(rows);
            chart.data.labels = rows.map((r) => formatBucket(r.bucket, span));
            chart.data.datasets[0].data = rows.map((r) => (r[key] === null ? null : Number(r[key])));

            // A chart that plots more than one measurement tags each dataset
            // with the series key it reads (see temperature()), so changing
            // the range refreshes every line rather than only the first.
            chart.data.datasets.forEach((ds) => {
                if (!ds.agKey) return;
                ds.data = rows.map((r) => (r[ds.agKey] === null || r[ds.agKey] === undefined
                    ? null
                    : Number(r[ds.agKey])));
            });

            if (settings && chart.data.datasets.length >= 3) {
                chart.data.datasets[1].data = chart.data.labels.map(() => Number(settings.moisture_threshold));
                chart.data.datasets[2].data = chart.data.labels.map(() => Number(settings.target_moisture));
            }
            chart.update('none');
        },

        replaceActivity(chart, rows) {
            chart.data.labels = rows.map((r) => {
                const d = new Date(String(r.day) + 'T00:00:00');
                return Number.isNaN(d.getTime())
                    ? String(r.day)
                    : d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' });
            });
            chart.data.datasets[0].data = rows.map((r) => Number(r.automatic_cycles || 0));
            chart.data.datasets[1].data = rows.map((r) => Number(r.manual_cycles || 0));
            chart.data.datasets[2].data = rows.map((r) => Number(r.minutes || 0));
            chart.update('none');
        }
    };

    if (window.Chart) {
        Chart.defaults.font.family =
            'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
        Chart.defaults.color = palette.ink;
    }

    window.AgriSense = window.AgriSense || {};
    window.AgriSense.charts = charts;
})();
