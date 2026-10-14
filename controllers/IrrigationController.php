<?php
/**
 * AgriSense - Irrigation history, settings and manual pump control
 * (requirements 8, 13 and 17).
 */

class IrrigationController extends Controller
{
    // -----------------------------------------------------------------
    // History
    // -----------------------------------------------------------------

    public function history(): void
    {
        Auth::requireLogin();

        $device = $this->requireDevice($this->selectedDevice());
        $range  = $this->rangeFilter('7days');

        $filters = [
            'device_id'    => (int) $device['id'],
            'start'        => $range['start'],
            'end'          => $range['end'],
            'trigger_type' => one_of(input('trigger_type'), ['', 'automatic', 'manual'], ''),
            'stop_reason'  => one_of(input('stop_reason'), array_merge([''], array_keys(IrrigationLog::STOP_REASONS)), ''),
            'search'       => input('search'),
        ];

        $result = IrrigationLog::search($filters, input_int('page', 1, 1), input_int('per_page', 25, 10, 200));

        render('irrigation/history', [
            'devices'  => Device::all(),
            'device'   => $device,
            'rows'     => $result['rows'],
            'meta'     => $result['meta'],
            'filters'  => $filters,
            'range'    => $range,
            'stats'    => IrrigationLog::stats((int) $device['id'], $range['start'], $range['end']),
            'activity' => IrrigationLog::activitySeries((int) $device['id'], $range['start'], $range['end']),
        ], ['title' => 'Irrigation history', 'active' => 'irrigation']);
    }

    public function exportHistory(): void
    {
        Auth::requireLogin();

        $device = $this->requireDevice($this->selectedDevice());
        $range  = $this->rangeFilter('7days');

        $rows = IrrigationLog::export([
            'device_id'    => (int) $device['id'],
            'start'        => $range['start'],
            'end'          => $range['end'],
            'trigger_type' => one_of(input('trigger_type'), ['', 'automatic', 'manual'], ''),
            'stop_reason'  => one_of(input('stop_reason'), array_merge([''], array_keys(IrrigationLog::STOP_REASONS)), ''),
            'search'       => input('search'),
        ]);

        SystemLog::record(Auth::id(), 'export', 'Exported ' . count($rows) . ' irrigation records to CSV.');

        send_csv(
            sprintf('agrisense-irrigation-%s-%s.csv', $device['device_code'], date('Ymd-His')),
            ['ID', 'Device', 'Date', 'Start time', 'End time', 'Duration (s)', 'Trigger',
             'Soil moisture at start (%)', 'Soil moisture at end (%)', 'Threshold (%)',
             'Pump status', 'Stop reason', 'Operator', 'Source'],
            array_map(static fn (array $r): array => [
                $r['id'],
                $r['device_name'],
                date('Y-m-d', strtotime($r['started_at'])),
                date('H:i:s', strtotime($r['started_at'])),
                $r['ended_at'] ? date('H:i:s', strtotime($r['ended_at'])) : '',
                $r['duration_seconds'] ?? '',
                $r['trigger_type'],
                $r['soil_moisture'] ?? '',
                $r['end_moisture'] ?? '',
                $r['threshold'] ?? '',
                $r['ended_at'] ? 'Completed' : 'Running',
                $r['stop_reason'] ? (IrrigationLog::STOP_REASONS[$r['stop_reason']] ?? $r['stop_reason']) : '',
                $r['operator'] ?? '',
                $r['source'],
            ], $rows)
        );
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function settings(): void
    {
        Auth::requireLogin();

        $device   = $this->requireDevice($this->selectedDevice());
        $settings = IrrigationSetting::ensureFor((int) $device['id'], Auth::id());

        render('irrigation/settings', [
            'devices'   => Device::all(),
            'device'    => $device,
            'settings'  => $settings,
            'errors'    => [],
            // Administrators change everything; a farmer with pump control
            // can change only the planting cycle.
            'canEdit'      => Auth::isAdmin(),
            'canEditCycle' => Auth::canControlPump(),
            'cycle'        => IrrigationSetting::cycleStatus($settings),
            'updatedBy'    => $settings['updated_by'] ? User::find((int) $settings['updated_by']) : null,
        ], ['title' => 'Irrigation settings', 'active' => 'settings']);
    }

    public function saveSettings(): void
    {
        // Admins save every field; a farmer with the pump-control permission
        // saves only the planting cycle. Anyone else is refused here.
        Auth::requirePumpControl();
        csrf_guard();

        $isAdmin = Auth::isAdmin();
        $device  = $this->requireDevice($this->selectedDevice());
        $current = IrrigationSetting::ensureFor((int) $device['id']);

        // --- planting cycle (everyone who may save) -------------------------
        $clearCycle = input('clear_cycle') === '1';
        $cycleInput = [
            'crop_name'   => $clearCycle ? '' : input('crop_name'),
            'cycle_start' => $clearCycle ? '' : input('cycle_start'),
            'cycle_days'  => $clearCycle ? '' : input('cycle_days'),
        ];
        $errors = IrrigationSetting::validateCycle($cycleInput);

        // --- everything else (administrators only) -------------------------
        $data = [];
        if ($isAdmin) {
            $data = [
                'moisture_threshold' => input_float('moisture_threshold'),
                'target_moisture'    => input_float('target_moisture'),
                'dry_value'          => input_float('dry_value'),
                'wet_value'          => input_float('wet_value'),
                'auto_irrigation'    => input('auto_irrigation') === '1',
                'max_pump_runtime'   => input_float('max_pump_runtime'),
                'reading_interval'   => input_float('reading_interval'),
                'min_cycle_interval' => input_float('min_cycle_interval'),
            ];
            $errors = array_merge(IrrigationSetting::validate($data), $errors);
        }

        if ($errors) {
            // Keep what the user typed so they can correct it.
            $typed = [
                'crop_name'   => $cycleInput['crop_name'],
                'cycle_start' => $cycleInput['cycle_start'],
                'cycle_days'  => $cycleInput['cycle_days'],
            ];
            if ($isAdmin) {
                $typed += [
                    'moisture_threshold' => $data['moisture_threshold'],
                    'target_moisture'    => $data['target_moisture'],
                    'dry_value'          => $data['dry_value'],
                    'wet_value'          => $data['wet_value'],
                    'auto_irrigation'    => $data['auto_irrigation'] ? 1 : 0,
                    'max_pump_runtime'   => $data['max_pump_runtime'],
                    'reading_interval'   => $data['reading_interval'],
                    'min_cycle_interval' => $data['min_cycle_interval'],
                ];
            }
            $shown = array_merge($current, $typed);

            render('irrigation/settings', [
                'devices'      => Device::all(),
                'device'       => $device,
                'settings'     => $shown,
                'errors'       => $errors,
                'canEdit'      => $isAdmin,
                'canEditCycle' => true,
                'cycle'        => IrrigationSetting::cycleStatus($current),
                'updatedBy'    => null,
            ], ['title' => 'Irrigation settings', 'active' => 'settings']);

            return;
        }

        $deviceId = (int) $device['id'];
        $crop     = trim($cycleInput['crop_name']);
        $crop     = $crop === '' ? null : $crop;
        $start    = $cycleInput['cycle_start'] === '' ? null : $cycleInput['cycle_start'];
        $days     = $cycleInput['cycle_days'] === '' ? null : (int) $cycleInput['cycle_days'];

        if ($isAdmin) {
            IrrigationSetting::update($deviceId, $data, Auth::id());
        }
        IrrigationSetting::updateCycle($deviceId, $crop, $start, $days, Auth::id());

        $cycle = IrrigationSetting::cycleStatus(
            ['crop_name' => $crop, 'cycle_start' => $start, 'cycle_days' => $days]
        );
        $cycleText = $cycle['configured']
            ? sprintf('planting cycle %s from %s for %d days',
                $crop ?? '(unnamed)', $start, $days)
            : 'planting cycle cleared';

        if ($isAdmin) {
            SystemLog::record(Auth::id(), 'settings_update', sprintf(
                'Updated irrigation settings for %s: threshold %.1f%%, target %.1f%%, max runtime %ds, mode %s, %s.',
                $device['device_code'],
                $data['moisture_threshold'],
                $data['target_moisture'],
                (int) $data['max_pump_runtime'],
                $data['auto_irrigation'] ? 'AUTO' : 'MANUAL',
                $cycleText
            ));
            Alert::raise(
                $deviceId,
                'settings_changed',
                sprintf('Irrigation settings updated: pump ON at %.1f%%, OFF at %.1f%%.',
                    $data['moisture_threshold'], $data['target_moisture']),
                'info'
            );
        } else {
            SystemLog::record(Auth::id(), 'settings_update',
                sprintf('Updated the planting cycle for %s: %s.', $device['device_code'], $cycleText));
            Alert::raise(
                $deviceId,
                'settings_changed',
                'Planting cycle updated: ' . $cycle['message'],
                'info'
            );
        }

        flash('success', $isAdmin
            ? 'Irrigation settings saved. The device will pick them up on its next check-in.'
            : 'Planting cycle saved. The device will pick it up on its next check-in.');
        redirect('irrigation/settings', ['device' => $deviceId]);
    }

    // -----------------------------------------------------------------
    // Manual pump control
    // -----------------------------------------------------------------

    /**
     * Queue a pump command. Answers JSON for the dashboard buttons and
     * falls back to a redirect for non-JavaScript clients.
     */
    public function pumpControl(): void
    {
        Auth::requirePumpControl();
        csrf_guard();

        $device  = $this->requireDevice($this->selectedDevice());
        $command = strtoupper(input('command'));

        $result = IrrigationEngine::requestPumpCommand($device, $command, Auth::id());

        if (wants_json()) {
            if (!$result['ok']) {
                Response::error($result['error'], 422);
            }
            Response::ok(
                ['command_id' => $result['command_id'], 'command' => $command],
                $result['message']
            );
        }

        if (!$result['ok']) {
            flash('danger', $result['error']);
        } else {
            flash('success', $result['message']);
        }

        redirect('monitor', ['device' => (int) $device['id']]);
    }

    // Command polling lives at GET /api/pump/command-status, which is what
    // assets/js/dashboard.js calls. There was a duplicate of it here with
    // no route pointing at it; it has been removed rather than wired up,
    // so there is one implementation to keep correct instead of two.
}
