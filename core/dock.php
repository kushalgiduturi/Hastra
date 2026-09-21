<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'dock.php') { http_response_code(404); exit(); }
// core/dock.php
// Shared floating "magnification dock" quick-nav, used alongside each
// portal's top nav bar. Renders a small pill of icon links that grow when
// the mouse gets close (see core/dock.css + core/dock.js for the effect).
//
// Usage from a portal's _nav.php:
//   $items = [
//     ['key' => 'dashboard', 'label' => 'Sysadmin Portal', 'href' => get_base_url().'portals/sysadmin/sysadmin_portal', 'current' => $nav_current === 'dashboard'],
//     ...
//   ];
//   render_dock($items);

function dock_icon($key) {
    $icons = [
        'dashboard'  => '<svg viewBox="0 0 24 24"><path d="M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3l9-8z"/></svg>',
        'roles'      => '<svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
        'logs'       => '<svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>',
        'security'   => '<svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>',
        'migrate'    => '<svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46A7.93 7.93 0 0020 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74A7.93 7.93 0 004 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>',
        'projects'   => '<svg viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/></svg>',
        'docs'       => '<svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg>',
        'team'       => '<svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
        'my_tasks'   => '<svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm-2 14l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>',
        'team_lead'  => '<svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4zm7-6.4l1.4 1.4-3 3-1.8-1.8 1.4-1.4 0.4 0.4 1.6-1.6z"/></svg>',
        'testing'    => '<svg viewBox="0 0 24 24"><path d="M20 8h-2.81c-.45-.78-1.07-1.45-1.82-1.96L17 4.41 15.59 3l-2.17 2.17C12.96 5.06 12.49 5 12 5c-.49 0-.96.06-1.41.17L8.41 3 7 4.41l1.62 1.63C7.88 6.55 7.26 7.22 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20V8z"/></svg>',
        'deployment' => '<svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>',
        'requirements' => '<svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm-2 14l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>',
        'attendance' => '<svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM7 11h5v5H7z"/></svg>',
        'leave' => '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>',
        'webhook' => '<svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 00-9 9H1l3.89 3.89.07.14L9 12H6a7 7 0 1113.5 2.5l1.66 1.66A9 9 0 0013 3zm-1 6v5l4.28 2.54.72-1.21-3.5-2.08V9H12z"/></svg>',
        'profile' => '<svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>',
        'help' => '<svg viewBox="0 0 24 24"><path d="M11 18h2v-2h-2v2zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4z"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>',
    ];
    $default = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/></svg>';
    return $icons[$key] ?? $default;
}

// Renders the floating dock. $items is a list of
// ['key' => string, 'label' => string, 'href' => string, 'current' => bool]
function render_dock(array $items) {
    if (empty($items)) return;
    echo '<div class="astra-dock-wrap"><nav class="astra-dock" aria-label="Quick navigation">';
    foreach ($items as $item) {
        $current = !empty($item['current']);
        $cls = 'astra-dock-item' . ($current ? ' is-current' : '');
        echo '<a href="' . htmlspecialchars($item['href']) . '" class="' . $cls . '"'
           . ($current ? ' aria-current="page"' : '') . '>'
           . dock_icon($item['key'])
           . '<span class="astra-dock-tooltip">' . htmlspecialchars($item['label']) . '</span>'
           . '</a>';
    }
    echo '</nav></div>';
    echo '<link rel="stylesheet" href="' . get_base_url() . 'core/dock.css?v=' . ASSET_VERSION . '">';
    echo '<script src="' . get_base_url() . 'core/dock.js?v=' . ASSET_VERSION . '" defer></script>';
}

// $item['tag'] === 'button' renders a <button type="button"> instead of an
// <a href>, for entries like the tour-restart "?" control that trigger JS
// (tour.js wires any .tour-restart-btn regardless of tag) rather than
// navigating — put its class(es) in $item['extra_class'].
function sidebar_link_html(array $item) {
    $current = !empty($item['current']);
    $cls = 'sidebar-link' . ($current ? ' current' : '') . (!empty($item['extra_class']) ? ' ' . $item['extra_class'] : '');
    $inner = dock_icon($item['key']) . '<span>' . htmlspecialchars($item['label']) . '</span>';
    if (($item['tag'] ?? 'a') === 'button') {
        return '<button type="button" class="' . $cls . '">' . $inner . '</button>';
    }
    return '<a href="' . htmlspecialchars($item['href']) . '" class="' . $cls . '"'
         . ($current ? ' aria-current="page"' : '') . '>' . $inner . '</a>';
}

// Renders the slide-out sidebar drawer + its backdrop + the shared CSS/JS.
// $sections   = list of ['label' => string, 'links' => [item, ...]] — item
//               shape matches render_dock()'s ['key','label','href','current'].
//               A section with no links is skipped automatically.
// $account_links = the bottom-pinned "Account & Preferences" items (same
//               item shape); rendered even if $sections is empty.
function render_sidebar(array $sections, array $account_links) {
    global $conn;
    if ($conn) render_profile_barrier($conn);

    echo '<div id="sidebar-overlay" class="sidebar-backdrop"></div>';
    echo '<aside id="astra-sidebar" class="sidebar-drawer" aria-label="Navigation">';
    echo '<div class="sidebar-head">'
       . '<span class="nav-title">Menu</span>'
       . '<button type="button" id="sidebar-close" class="sidebar-close" aria-label="Close menu">'
       . '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>'
       . '</button></div>';
    echo '<nav class="sidebar-body">';
    foreach ($sections as $section) {
        if (empty($section['links'])) continue;
        echo '<div class="sidebar-section"><div class="sidebar-section-label">' . htmlspecialchars($section['label']) . '</div>';
        echo '<div class="sidebar-links">';
        foreach ($section['links'] as $item) echo sidebar_link_html($item);
        echo '</div></div>';
    }
    echo '</nav>';
    echo '<div class="sidebar-foot"><div class="sidebar-links">';
    foreach ($account_links as $item) echo sidebar_link_html($item);
    echo '</div></div>';
    echo '</aside>';
    echo '<link rel="stylesheet" href="' . get_base_url() . 'assets/css/sidebar-nav.css?v=' . ASSET_VERSION . '">';
    echo '<script src="' . get_base_url() . 'assets/js/sidebar-nav.js?v=' . ASSET_VERSION . '" defer></script>';
}
