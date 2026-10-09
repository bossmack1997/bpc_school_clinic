<?php
/**
 * includes/icons.php
 * ------------------------------------------------------------------
 * Reusable inline SVG icon component.
 *
 * Icons are rendered as inline <svg> markup so the interface never
 * depends on an icon webfont, CDN, or network request. Every glyph is
 * monochrome, inherits `currentColor`, and uses a consistent 24x24
 * grid with medium strokes.
 *
 * Usage:
 *   bpc_icon('dashboard')                      decorative icon
 *   bpc_icon('users', 'nav-ico')               extra CSS class
 *   bpc_icon('bell', 'topbar-btn__icon', ['label' => 'Notifications'])
 *   bpc_icon('trash', '', ['variant' => 'filled', 'size' => 18])
 * ------------------------------------------------------------------
 */

if (!function_exists('bpc_icon_paths')) {
    function bpc_icon_paths(): array
    {
        static $paths = null;
        if ($paths !== null) {
            return $paths;
        }

        $paths = [
            // Navigation / structure
            'dashboard'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
            'grid'          => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
            'layers'        => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
            'home'          => '<path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 21v-8h6v8"/>',
            'list'          => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3.5 6h.01"/><path d="M3.5 12h.01"/><path d="M3.5 18h.01"/>',
            'inbox'         => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>',

            // People
            'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user'          => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'user-check'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
            'user-x'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 9 4 4"/><path d="m21 9-4 4"/>',
            'graduation'    => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12.5V17c0 1.7 2.7 3 6 3s6-1.3 6-3v-4.5"/>',
            'briefcase'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'stethoscope'   => '<path d="M5 3v5a5 5 0 0 0 10 0V3"/><path d="M5 3H3.5"/><path d="M15 3h1.5"/><path d="M10 13v2a5 5 0 0 0 10 0v-1"/><circle cx="20" cy="11" r="2"/>',
            'heart-pulse'   => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78Z"/><path d="M3.6 12.6h3l1.4-2.3 2 4.4 1.4-2.8 1 1.1h5.9"/>',
            'heart'         => '<path d="M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 1 0-7.1 7.1l8.8 8.8 8.8-8.8a5 5 0 0 0 0-7.1Z"/>',
            'hospital'      => '<path d="M3 21h18"/><path d="M5 21V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v14"/><path d="M12 8v6"/><path d="M9 11h6"/>',

            // Clinic / records
            'clipboard'     => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
            'clipboard-list'=> '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h4"/>',
            'clipboard-check'=> '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 13 2 2 4-4"/>',
            'file-text'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>',
            'file-medical'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M12 11v6"/><path d="M9 14h6"/>',
            'save'          => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
            'upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
            'qr'            => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3z"/><path d="M20 14h.01"/><path d="M14 20h.01"/><path d="M20 20h.01"/>',
            'scan'          => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M3 12h18"/>',
            'pill'          => '<path d="M10.5 20.5a5 5 0 0 1-7-7l7-7a5 5 0 0 1 7 7Z"/><path d="m8.5 8.5 7 7"/>',
            'thermometer'   => '<path d="M14 14.8V4a2 2 0 0 0-4 0v10.8a4 4 0 1 0 4 0Z"/>',

            // Safety / status
            'shield-alert'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
            'shield-check'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
            'shield-lock'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><rect x="9.5" y="10.5" width="5" height="5" rx="1"/><path d="M10.7 10.5V9.3a1.3 1.3 0 0 1 2.6 0v1.2"/>',
            'alert-triangle'=> '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'alert-circle'  => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
            'check-circle'  => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
            'x-circle'      => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6"/><path d="m15 9-6 6"/>',
            'info'          => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
            'check'         => '<path d="m4 12.5 5 5L20 6.5"/>',
            'x'             => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'clock'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'history'       => '<path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/>',

            // Analytics
            'chart'         => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m7 14 3.5-4 3 2.5L20 7"/>',
            'bar-chart'     => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
            'trending-up'   => '<path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/>',
            'trending-down' => '<path d="M22 17 13.5 8.5 8.5 13.5 2 7"/><path d="M16 17h6v-6"/>',
            'pie-chart'     => '<path d="M21.2 15.9A10 10 0 1 1 8 2.8"/><path d="M22 12A10 10 0 0 0 12 2v10Z"/>',
            'activity'      => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'sliders'       => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M1 14h6"/><path d="M9 8h6"/><path d="M17 16h6"/>',

            // Inventory
            'package'       => '<path d="M16.5 9.4 7.5 4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/>',
            'box'           => '<path d="M21 8v8a2 2 0 0 1-1 1.73l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.73l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',

            // Communication
            'megaphone'     => '<path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'chat'          => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/>',
            'send'          => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/>',
            'bell'          => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
            'mail'          => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',

            // System
            'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
            'logout'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
            'lock'          => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'key'           => '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2"/><path d="m17 6 2 2"/><path d="m14.5 8.5 2 2"/>',
            'database'      => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'server'        => '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01"/><path d="M7 17.5h.01"/>',

            // Interface
            'search'        => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
            'filter'        => '<path d="M3 4h18l-7 8v7l-4 2v-9Z"/>',
            'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
            'plus'          => '<path d="M12 5v14"/><path d="M5 12h14"/>',
            'plus-circle'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/>',
            'minus-circle'  => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
            'edit'          => '<path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><path d="M18.4 2.6a2 2 0 0 1 2.8 2.8L12 14.6l-4 1 1-4Z"/>',
            'trash'         => '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
            'eye'           => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'refresh'       => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
            'external'      => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/>',
            'menu'          => '<path d="M3 12h18"/><path d="M3 6h18"/><path d="M3 18h18"/>',
            'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
            'chevron-left'  => '<path d="m15 18-6-6 6-6"/>',
            'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
            'arrow-right'   => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
            'arrow-up'      => '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
            'arrow-down'    => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
            'calendar'      => '<rect x="3" y="4.5" width="18" height="17" rx="2"/><path d="M8 2v5"/><path d="M16 2v5"/><path d="M3 10h18"/>',
            'calendar-x'    => '<rect x="3" y="4.5" width="18" height="17" rx="2"/><path d="M8 2v5"/><path d="M16 2v5"/><path d="M3 10h18"/><path d="m10 14 4 4"/><path d="m14 14-4 4"/>',
            'sun'           => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.9 4.9 1.4 1.4"/><path d="m17.7 17.7 1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.3 17.7-1.4 1.4"/><path d="m19.1 4.9-1.4 1.4"/>',
            'moon'          => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
            'more'          => '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
            'loader'        => '<path d="M12 2v4"/><path d="m16.2 7.8 2.9-2.9"/><path d="M18 12h4"/><path d="m16.2 16.2 2.9 2.9"/><path d="M12 18v4"/><path d="m4.9 19.1 2.9-2.9"/><path d="M2 12h4"/><path d="m4.9 4.9 2.9 2.9"/>',
            'phone'         => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.1a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
            'map-pin'       => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
            'building'      => '<path d="M4 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16"/><path d="M15 9h3a2 2 0 0 1 2 2v10"/><path d="M9 7h2"/><path d="M9 11h2"/><path d="M9 15h2"/><path d="M2 21h20"/>',
        ];

        return $paths;
    }
}

if (!function_exists('bpc_icon')) {
    /**
     * Render a monochrome inline SVG icon.
     *
     * @param string $name Icon key from the icon set.
     * @param string $class Extra CSS class for the <svg> element.
     * @param array  $opts size (int), label (string, makes it accessible),
     *                      variant ('outline'|'filled'|'duotone').
     * @return string Safe SVG markup (empty string for unknown icons).
     */
    function bpc_icon(string $name, string $class = '', array $opts = []): string
    {
        static $cache = [];
        $key = $name . '|' . $class . '|' . ($opts['size'] ?? '') . '|' . ($opts['variant'] ?? '') . '|' . ($opts['label'] ?? '');
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $paths = bpc_icon_paths();
        $name = strtolower(trim($name));
        if (!isset($paths[$name])) {
            return $cache[$key] = '';
        }

        $size    = isset($opts['size']) ? (int)$opts['size'] : 0;
        $label   = isset($opts['label']) ? trim((string)$opts['label']) : '';
        $variant = $opts['variant'] ?? 'outline';

        $classes = ['bpc-icon'];
        if ($variant === 'filled') {
            $classes[] = 'bpc-icon--filled';
        } elseif ($variant === 'duotone') {
            $classes[] = 'bpc-icon--duotone';
        }
        $class = trim($class);
        if ($class !== '') {
            $classes[] = $class;
        }

        $style = $size > 0 ? ' style="width:' . $size . 'px;height:' . $size . 'px"' : '';
        $a11y  = $label !== ''
            ? ' role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
            : ' aria-hidden="true" focusable="false"';

        $svg = '<svg class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"'
            . ' viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
            . $style . $a11y . ' data-icon="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">'
            . $paths[$name]
            . '</svg>';

        return $cache[$key] = $svg;
    }
}

if (!function_exists('bpc_icon_exists')) {
    function bpc_icon_exists(string $name): bool
    {
        return isset(bpc_icon_paths()[strtolower(trim($name))]);
    }
}