<?php
/**
 * AgriSense - Inline SVG icon set.
 *
 * Kept inline rather than as an icon font so the interface still renders
 * correctly with no internet connection.
 */

if (!function_exists('icon')) {
    /**
     * @param string $name  Icon key
     * @param int    $size  Pixel size
     * @param string $class Extra CSS classes
     */
    function icon(string $name, int $size = 18, string $class = ''): string
    {
        $paths = [
            'dashboard'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'monitor'    => '<path d="M3 12h4l3 7 4-14 3 7h4"/>',
            'sensor'     => '<path d="M12 3v10"/><circle cx="12" cy="17" r="4"/><path d="M9 6h6M9 9h6"/>',
            'water'      => '<path d="M12 3c4 5 6.5 8.3 6.5 11.5a6.5 6.5 0 0 1-13 0C5.5 11.3 8 8 12 3z"/>',
            'bell'       => '<path d="M18 8a6 6 0 1 0-12 0c0 6-2 7-2 7h16s-2-1-2-7"/><path d="M10.5 20a2 2 0 0 0 3 0"/>',
            'device'     => '<rect x="4" y="4" width="16" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
            'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
            'report'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
            'users'      => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M17 11a3 3 0 1 0-1.5-5.6"/><path d="M18 20c0-2.4-.9-4-2.4-4.9"/>',
            'logs'       => '<path d="M4 5h16M4 10h16M4 15h10M4 20h10"/>',
            'logout'     => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h11"/>',
            'user'       => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20c0-3.6 3.4-6 7.5-6s7.5 2.4 7.5 6"/>',
            'menu'       => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'thermo'     => '<path d="M14 14.8V5a2 2 0 1 0-4 0v9.8a4.5 4.5 0 1 0 4 0z"/>',
            'humidity'   => '<path d="M12 3.5c3.6 4.6 5.8 7.6 5.8 10.4a5.8 5.8 0 1 1-11.6 0C6.2 11.1 8.4 8.1 12 3.5z"/><path d="M9.5 14.5a2.5 2.5 0 0 0 2.5 2.5"/>',
            'plug'       => '<path d="M9 3v5M15 3v5"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v4"/>',
            'wifi'       => '<path d="M2.5 9a15 15 0 0 1 19 0"/><path d="M6 12.5a10 10 0 0 1 12 0"/><path d="M9.5 16a5 5 0 0 1 5 0"/><circle cx="12" cy="19.5" r="1"/>',
            'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'download'   => '<path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><path d="M4 19h16"/>',
            'print'      => '<path d="M7 8V3h10v5"/><rect x="4" y="8" width="16" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
            'search'     => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
            'plus'       => '<path d="M12 5v14M5 12h14"/>',
            'edit'       => '<path d="M4 20h4L20 8l-4-4L4 16z"/>',
            'trash'      => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
            'check'      => '<path d="M5 12.5l4.5 4.5L19 7"/>',
            'x'          => '<path d="M6 6l12 12M18 6L6 18"/>',
            'warning'    => '<path d="M12 3l9.5 17H2.5z"/><path d="M12 9v5"/><circle cx="12" cy="17" r=".6" fill="currentColor"/>',
            'info'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><circle cx="12" cy="7.7" r=".7" fill="currentColor"/>',
            'refresh'    => '<path d="M20 11a8 8 0 0 0-13.7-5.3L3 9"/><path d="M4 13a8 8 0 0 0 13.7 5.3L21 15"/><path d="M3 4v5h5M21 20v-5h-5"/>',
            'key'        => '<circle cx="8" cy="14" r="4"/><path d="M11 12l9-9"/><path d="M17 6l2 2M15 8l2 2"/>',
            'power'      => '<path d="M12 3v9"/><path d="M6.5 6.5a8 8 0 1 0 11 0"/>',
        ];

        $body = $paths[$name] ?? $paths['info'];

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            htmlspecialchars($class, ENT_QUOTES),
            $size,
            $size,
            $body
        );
    }
}
