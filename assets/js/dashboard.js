/* =====================================================================
   AgriSense - Live dashboard

   Polls GET /api/dashboard and repaints the summary cards, the pump state
   and the charts in place, so the farmer never has to refresh the browser
   (requirement 11). Also drives the manual pump controls.
   ===================================================================== */
(function () {
    'use strict';

    const root = document.getElementById('agDashboard');
    if (!root) return;

    const config = JSON.parse(root.getAttribute('data-config') || '{}');
    const deviceId = config.deviceId;

    // Poll a little faster than the device reports, with a floor so a
    // 5 second reading interval cannot hammer the server.
    const pollMs = Math.max(5000, Math.min((config.readingInterval || 30) * 1000, 60000));

    const $ = (id) => document.getElementById(id);
    const state = { charts: {}, failures: 0, timer: null, settings: config.settings || null };

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------

    /**
     * Build an element with text content. Used instead of innerHTML
     * throughout the polling path: everything painted here came from the
     * API, and the moment one of those values is a device name rather than
     * a number, innerHTML would be a stored-XSS sink.
     */
    function el(tag, text) {
        const node = document.createElement(tag);
        node.textContent = text;
        return node;
    }

    /** Replace an element's children in one go. */
    function setChildren(parent, nodes) {
        parent.replaceChildren.apply(parent, nodes);
    }

    /** Write a reading into a metric card, flagging carried-over values. */
    function paintReading(valueEl, noteEl, reading, decimals, suffix) {
        if (!valueEl) return;

        if (reading.value === null) {
            valueEl.textContent = '--';
        } else {
            setChildren(valueEl, [
                document.createTextNode(Number(reading.value).toFixed(decimals)),
                el('small', suffix)
            ]);
        }

        valueEl.classList.toggle('ag-stale', !!reading.stale);

        if (noteEl) {
            if (reading.value === null) {
                noteEl.textContent = 'No reading recorded yet';
            } else if (reading.stale) {
                noteEl.textContent = 'Last good reading - the sensor is not responding';
            } else {
                noteEl.textContent = 'Updated ' + relative(reading.recorded_at);
            }
        }
    }

    function relative(timestamp) {
        if (!timestamp) return 'never';
        const then = new Date(String(timestamp).replace(' ', 'T')).getTime();
        if (Number.isNaN(then)) return 'unknown';

        const diff = Math.max(0, Math.round((Date.now() - then) / 1000));
        if (diff < 10) return 'just now';
        if (diff < 60) return diff + ' seconds ago';
        if (diff < 3600) {
            const m = Math.floor(diff / 60);
            return m + (m === 1 ? ' minute ago' : ' minutes ago');
        }
        if (diff < 86400) {
            const h = Math.floor(diff / 3600);
            return h + (h === 1 ? ' hour ago' : ' hours ago');
        }
        const d = Math.floor(diff / 86400);
        return d + (d === 1 ? ' day ago' : ' days ago');
    }

    function duration(seconds) {
        if (seconds === null || seconds === undefined) return '--';
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
        return `${Math.floor(seconds / 3600)}h ${String(Math.floor((seconds % 3600) / 60)).padStart(2, '0')}m`;
    }

    function paint(snapshot) {
        state.settings = snapshot.settings;

        paintReading($('liveMoisture'), $('liveMoistureNote'), snapshot.readings.soil_moisture, 1, '%');
        paintReading($('liveTemp'), $('liveTempNote'), snapshot.readings.temperature, 1, '°C');
        paintReading($('liveHumidity'), $('liveHumidityNote'), snapshot.readings.humidity, 1, '%');

        // Canopy temperature. Two pages show it differently and both use this
        // file: the dashboard folds it into a single line inside the air
        // temperature card, while the monitor gives it a tile of its own with
        // the gap underneath. Whichever elements exist get painted.
        const stressed = Boolean(snapshot.readings.leaf_stress);
        const leafReading = snapshot.readings.leaf_temperature;
        const delta = snapshot.readings.leaf_delta;
        const deltaText = (delta === null || delta === undefined)
            ? ''
            : (delta >= 0 ? '+' : '') + delta.toFixed(1);

        paintReading($('liveLeafValue'), null, leafReading, 1, '°C');

        const leaf = $('liveLeaf');
        if (leaf) {
            leaf.textContent = 'Canopy ' + window.AgriSense.num(leafReading && leafReading.value, 1, '°C')
                + (deltaText ? ' (' + deltaText + ')' : '');
        }

        const leafDelta = $('liveLeafDelta');
        if (leafDelta) {
            leafDelta.textContent = deltaText
                ? deltaText + '°C vs air' + (stressed ? ' - possible water stress' : '')
                : '';
        }

        [leaf, leafDelta].forEach((element) => {
            if (!element) return;
            element.classList.toggle('text-danger', stressed);
            element.classList.toggle('fw-semibold', stressed);
        });

        // Individual probe readings, when two are fitted. Each gets its own
        // chip so a dead probe (value === null) can be flagged red on its
        // own, without dragging the still-working probe's chip down with it.
        if (snapshot.device.probe_count >= 2) {
            const p1 = snapshot.readings.soil_moisture_1;
            const p2 = snapshot.readings.soil_moisture_2;

            const p1El = $('liveProbe1');
            if (p1El) {
                p1El.textContent = window.AgriSense.num(p1 && p1.value, 1, '%');
                p1El.classList.toggle('text-danger', Boolean(p1 && p1.value === null));
            }

            const p2El = $('liveProbe2');
            if (p2El) {
                p2El.textContent = window.AgriSense.num(p2 && p2.value, 1, '%');
                p2El.classList.toggle('text-danger', Boolean(p2 && p2.value === null));
            }
        }

        // Moisture bar, coloured against the configured thresholds.
        const bar = $('liveMoistureBar');
        if (bar) {
            const value = snapshot.readings.soil_moisture.value;
            bar.style.width = (value === null ? 0 : Math.max(0, Math.min(100, value))) + '%';
            bar.style.background = value === null
                ? '#b9c4bd'
                : (value <= snapshot.settings.moisture_threshold ? '#c62828'
                    : (value >= snapshot.settings.target_moisture ? '#2e7d32' : '#0288d1'));
        }

        // Pump
        const pumpOn = snapshot.pump.status === 'ON';
        const pumpEl = $('livePump');
        if (pumpEl) {
            pumpEl.textContent = snapshot.pump.status;
            pumpEl.classList.toggle('text-success', pumpOn);
        }
        const pumpPill = $('livePumpPill');
        if (pumpPill) {
            pumpPill.className = 'ag-pill ' + (pumpOn ? 'ag-pill-on' : 'ag-pill-off');
            const pumpDot = document.createElement('span');
            pumpDot.className = 'ag-pill-dot';
            setChildren(pumpPill, [pumpDot, document.createTextNode(pumpOn ? 'Running' : 'Idle')]);
            pumpPill.parentElement?.classList.toggle('ag-pulse', pumpOn);
        }

        const cycleNote = $('livePumpNote');
        if (cycleNote) {
            cycleNote.textContent = snapshot.pump.running_cycle
                ? `${snapshot.pump.running_cycle.trigger_type} cycle, running ${duration(snapshot.pump.running_cycle.elapsed)}`
                : `${snapshot.today.cycles} cycle(s) today, ${snapshot.today.total_h} total`;
        }

        // Mode
        const modeEl = $('liveMode');
        if (modeEl) {
            const auto = snapshot.pump.mode === 'AUTO';
            modeEl.className = 'ag-pill ' + (auto ? 'ag-pill-auto' : 'ag-pill-manual');
            modeEl.textContent = auto ? 'Automatic' : 'Manual';
        }

        // Device connectivity
        const deviceEl = $('liveDevice');
        if (deviceEl) {
            deviceEl.textContent = snapshot.device.online ? 'ONLINE' : 'OFFLINE';
            deviceEl.classList.toggle('text-danger', !snapshot.device.online);
        }
        const devicePill = $('liveDevicePill');
        if (devicePill) {
            devicePill.className = 'ag-pill ' + (snapshot.device.online ? 'ag-pill-online' : 'ag-pill-offline');
            const deviceDot = document.createElement('span');
            deviceDot.className = 'ag-pill-dot';
            setChildren(devicePill, [
                deviceDot,
                document.createTextNode(snapshot.device.online ? 'Connected' : 'No signal')
            ]);
        }
        const deviceCard = $('liveDeviceCard');
        deviceCard?.classList.toggle('is-critical', !snapshot.device.online);

        const lastSeen = $('liveLastSeen');
        if (lastSeen) lastSeen.textContent = relative(snapshot.device.last_seen);

        const updated = $('liveUpdated');
        if (updated) updated.textContent = relative(snapshot.readings.recorded_at);

        // Alerts badge
        if (window.AgriSense.setUnreadCount) {
            window.AgriSense.setUnreadCount(snapshot.alerts.unread);
        }

        // Pump buttons reflect what is currently possible.
        syncPumpButtons(snapshot);

        const indicator = $('agLiveIndicator');
        indicator?.classList.remove('is-stale');
        const indicatorText = $('agLiveText');
        if (indicatorText) indicatorText.textContent = 'Live';
    }

    /** Enable or disable each control according to the current state. */
    function syncPumpButtons(snapshot) {
        const auto = snapshot.pump.mode === 'AUTO';
        const on = snapshot.pump.status === 'ON';
        const online = snapshot.device.online;

        const set = (id, disabled, reason) => {
            const btn = $(id);
            if (!btn) return;
            btn.disabled = disabled;
            btn.title = disabled ? reason : '';
        };

        set('btnPumpOn', !online || auto || on,
            !online ? 'The device is offline' : (auto ? 'Switch to Manual mode first' : 'The pump is already running'));
        set('btnPumpOff', !online, 'The device is offline');
        set('btnModeAuto', !online || auto, !online ? 'The device is offline' : 'Already in automatic mode');
        set('btnModeManual', !online || !auto, !online ? 'The device is offline' : 'Already in manual mode');

        const pending = $('agPendingCommand');
        pending?.classList.toggle('d-none', !snapshot.pump.pending_command);
    }

    // -----------------------------------------------------------------
    // Polling
    // -----------------------------------------------------------------

    async function refresh() {
        try {
            const snapshot = await window.AgriSense.api(`api/dashboard?device_id=${deviceId}`);
            state.failures = 0;
            paint(snapshot);
        } catch (error) {
            state.failures += 1;
            // Two consecutive failures means the browser has lost the
            // server, which is worth showing rather than hiding.
            if (state.failures >= 2) {
                $('agLiveIndicator')?.classList.add('is-stale');
                const text = $('agLiveText');
                if (text) {
                    text.textContent = error.status === 401
                        ? 'Session expired - reload'
                        : 'Reconnecting...';
                }
            }
        }
    }

    function startPolling() {
        stopPolling();
        state.timer = setInterval(refresh, pollMs);
    }

    function stopPolling() {
        if (state.timer) clearInterval(state.timer);
        state.timer = null;
    }

    // Pause while the tab is hidden; catch up as soon as it is shown again.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else {
            refresh();
            startPolling();
        }
    });

    // -----------------------------------------------------------------
    // Manual pump control
    // -----------------------------------------------------------------

    const confirmModalEl = $('agPumpConfirm');
    const confirmModal = confirmModalEl ? new bootstrap.Modal(confirmModalEl) : null;
    let pendingCommand = null;

    document.querySelectorAll('[data-pump-command]').forEach((button) => {
        button.addEventListener('click', () => {
            const command = button.getAttribute('data-pump-command');

            // Starting a real pump gets an explicit confirmation
            // (requirement 8); the other commands do not need one.
            if (command === 'PUMP_ON' && confirmModal) {
                pendingCommand = command;
                confirmModal.show();
                return;
            }
            sendCommand(command);
        });
    });

    $('agPumpConfirmYes')?.addEventListener('click', () => {
        confirmModal?.hide();
        if (pendingCommand) sendCommand(pendingCommand);
        pendingCommand = null;
    });

    async function sendCommand(command) {
        document.querySelectorAll('[data-pump-command]').forEach((b) => { b.disabled = true; });

        try {
            const result = await window.AgriSense.api('api/pump/control', {
                method: 'POST',
                body: { device_id: deviceId, command }
            });
            window.AgriSense.toast(result.message, 'success');
            watchCommand(result.command_id);
        } catch (error) {
            window.AgriSense.toast(error.message, 'danger');
        } finally {
            refresh();
        }
    }

    /**
     * Follow a queued command until the device confirms it, so the operator
     * sees "confirmed by device" rather than just "sent".
     */
    function watchCommand(commandId) {
        const status = $('agCommandStatus');
        if (status) {
            status.classList.remove('d-none', 'alert-success', 'alert-danger');
            status.classList.add('alert-info');
            status.textContent = 'Command queued. Waiting for the device to pick it up...';
        }

        let tries = 0;
        const poll = setInterval(async () => {
            tries += 1;
            try {
                const result = await window.AgriSense.api(`api/pump/command-status?id=${commandId}`);
                const command = result.command;

                if (command.status === 'acknowledged' || command.status === 'failed' || command.status === 'expired') {
                    clearInterval(poll);
                    if (status) {
                        status.classList.remove('alert-info');
                        const ok = command.status === 'acknowledged';
                        status.classList.add(ok ? 'alert-success' : 'alert-danger');
                        status.textContent = ok
                            ? 'The device confirmed the command.'
                            : (command.result_message || 'The device did not carry out the command.');
                    }
                    refresh();
                } else if (command.status === 'delivered' && status) {
                    status.textContent = 'The device has collected the command and is acting on it...';
                }
            } catch (error) {
                clearInterval(poll);
            }

            if (tries > 30) {
                clearInterval(poll);
                if (status) {
                    status.classList.remove('alert-info');
                    status.classList.add('alert-danger');
                    status.textContent = 'No confirmation from the device. Check that it is powered and online.';
                }
            }
        }, 2000);
    }

    // -----------------------------------------------------------------
    // Charts
    // -----------------------------------------------------------------

    const initial = JSON.parse($('agChartData')?.textContent || '{"series":[],"activity":[]}');

    if ($('chartMoisture')) {
        state.charts.moisture = window.AgriSense.charts.moisture(
            $('chartMoisture'), initial.series, config.settings
        );
    }
    if ($('chartTemperature')) {
        // The canopy line is drawn only when the series actually carries one.
        // Adding an all-null dataset would put a dead entry in the legend on
        // every board without an MLX90614.
        const hasLeaf = initial.series.some((row) => row.leaf_temperature !== null
            && row.leaf_temperature !== undefined);
        state.charts.temperature = window.AgriSense.charts.temperature(
            $('chartTemperature'), initial.series, { leaf: hasLeaf }
        );
    }
    if ($('chartHumidity')) {
        state.charts.humidity = window.AgriSense.charts.humidity($('chartHumidity'), initial.series);
    }
    if ($('chartActivity')) {
        state.charts.activity = window.AgriSense.charts.activity($('chartActivity'), initial.activity);
    }

    // Range selector above the charts.
    document.querySelectorAll('[data-chart-range]').forEach((button) => {
        button.addEventListener('click', async () => {
            const range = button.getAttribute('data-chart-range');

            document.querySelectorAll('[data-chart-range]').forEach((b) => {
                b.classList.toggle('active', b === button);
            });

            try {
                const result = await window.AgriSense.api(
                    `api/chart-data?device_id=${deviceId}&range=${encodeURIComponent(range)}`
                );
                const c = window.AgriSense.charts;
                if (state.charts.moisture) c.replaceSeries(state.charts.moisture, result.series, 'soil_moisture', state.settings);
                if (state.charts.temperature) c.replaceSeries(state.charts.temperature, result.series, 'temperature');
                if (state.charts.humidity) c.replaceSeries(state.charts.humidity, result.series, 'humidity');
                if (state.charts.activity) c.replaceActivity(state.charts.activity, result.activity);
            } catch (error) {
                window.AgriSense.toast(error.message, 'danger');
            }
        });
    });

    // -----------------------------------------------------------------
    startPolling();
    // One immediate refresh so the page is never a second out of date.
    refresh();
})();
