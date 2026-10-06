<?php
/**
 * AgriSense - Shared controller helpers.
 */

abstract class Controller
{
    /**
     * The device the page is showing. Comes from ?device=<id>, remembered in
     * the session, and falls back to the most recently active device.
     *
     * @return array|null Null only when no device has been registered yet.
     */
    protected function selectedDevice(): ?array
    {
        $requested = input_int('device', 0, 0);

        if ($requested > 0) {
            $device = Device::find($requested);
            if ($device) {
                $_SESSION['device_id'] = (int) $device['id'];

                return $device;
            }
            flash('warning', 'That device no longer exists. Showing the default device instead.');
        }

        if (!empty($_SESSION['device_id'])) {
            $device = Device::find((int) $_SESSION['device_id']);
            if ($device) {
                return $device;
            }
            unset($_SESSION['device_id']);
        }

        $device = Device::defaultDevice();
        if ($device) {
            $_SESSION['device_id'] = (int) $device['id'];
        }

        return $device;
    }

    /**
     * Read the date range filter shared by the history, chart and report
     * pages.
     *
     * @return array{range:string,from:string,to:string,start:string,end:string}
     */
    protected function rangeFilter(string $default = '7days'): array
    {
        $range = one_of(input('range', $default), ['today', 'yesterday', '7days', '30days', 'custom'], $default);
        $from  = valid_date(input('from')) ?? date('Y-m-d', strtotime('-6 days'));
        $to    = valid_date(input('to')) ?? date('Y-m-d');

        [$start, $end] = date_range($range, $from, $to);

        return [
            'range' => $range,
            'from'  => $from,
            'to'    => $to,
            'start' => $start,
            'end'   => $end,
        ];
    }

    /** Send the user back to a device-less state with a helpful message. */
    protected function requireDevice(?array $device): array
    {
        if ($device) {
            return $device;
        }

        if (Auth::isAdmin()) {
            flash('info', 'Register your first ESP32 device to start monitoring.');
            redirect('devices/create');
        }

        flash('warning', 'No irrigation device has been registered yet. Please contact your administrator.');
        redirect('dashboard');
    }
}
