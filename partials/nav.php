<?php
/**
 * Navigation model — the information architecture in one place.
 *
 * The old sidebar was 14 flat entries in which a Privacy Policy page carried
 * the same weight as Tours. This groups by the job being done and puts the
 * two daily jobs (triaging the inbox, publishing the catalogue) at the top.
 *
 * Pages and Settings each collapse to a single destination with sibling tabs,
 * so the sidebar shrinks to 11 entries without losing a single screen. Every
 * original URL still resolves.
 */

/**
 * @param array $counts ['registrations' => int, 'private' => int, 'messages' => int]
 * @return array<int, array{label:string, items:array}>
 */
function admin_nav(array $counts = []): array
{
    return [
        [
            'label' => 'Overview',
            'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'ri-dashboard-line', 'href' => url('index')],
            ],
        ],
        [
            // Job one: respond to people.
            'label' => 'Inbox',
            'items' => [
                ['key' => 'registrations',    'label' => 'Registrations',    'icon' => 'ri-group-line',        'href' => url('registrations'),    'count' => $counts['registrations'] ?? 0],
                ['key' => 'private-requests', 'label' => 'Private requests', 'icon' => 'ri-vip-diamond-line',  'href' => url('private-requests'), 'count' => $counts['private'] ?? 0],
                ['key' => 'messages',         'label' => 'Messages',         'icon' => 'ri-question-answer-line', 'href' => url('messages'),      'count' => $counts['messages'] ?? 0],
                ['key' => 'mail',             'label' => 'Mail',             'icon' => 'ri-mail-line',         'href' => url('email')],
            ],
        ],
        [
            // Job two: publish what you sell.
            'label' => 'Catalogue',
            'items' => [
                ['key' => 'tours',        'label' => 'Tours',        'icon' => 'ri-route-line',        'href' => url('tours')],
                ['key' => 'guides',       'label' => 'Guides',       'icon' => 'ri-user-star-line',    'href' => url('guides')],
                ['key' => 'categories',   'label' => 'Categories',   'icon' => 'ri-price-tag-3-line',  'href' => url('categories')],
                ['key' => 'testimonials', 'label' => 'Testimonials', 'icon' => 'ri-chat-quote-line',   'href' => url('testimonials')],
            ],
        ],
        [
            // Rarely touched, so deliberately demoted to one entry.
            'label' => 'Site',
            'items' => [
                ['key' => 'pages',    'label' => 'Pages',    'icon' => 'ri-file-text-line',   'href' => url('about')],
                ['key' => 'settings', 'label' => 'Settings', 'icon' => 'ri-settings-3-line',  'href' => url('settings')],
            ],
        ],
    ];
}

/** Tabs shown on the three editable site pages. */
function admin_pages_tabs(string $active): array
{
    return [
        ['href' => url('about'),   'label' => 'About',         'icon' => 'ri-information-line',    'active' => $active === 'about'],
        ['href' => url('privacy'), 'label' => 'Privacy Policy', 'icon' => 'ri-shield-keyhole-line', 'active' => $active === 'privacy'],
        ['href' => url('terms'),   'label' => 'Booking Terms',  'icon' => 'ri-article-line',        'active' => $active === 'terms'],
    ];
}

/** Tabs shown across the three settings screens. */
function admin_settings_tabs(string $active): array
{
    return [
        ['href' => url('settings'),     'label' => 'General',      'icon' => 'ri-store-2-line',      'active' => $active === 'settings'],
        ['href' => url('integrations'), 'label' => 'Integrations', 'icon' => 'ri-plug-2-line',       'active' => $active === 'integrations'],
        ['href' => url('account'),      'label' => 'Account',      'icon' => 'ri-shield-user-line',  'active' => $active === 'account'],
    ];
}
