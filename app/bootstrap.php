<?php
// Admin-specific bootstrap

// Load the admin project's Composer autoloader before any app classes are initialized.
require_once __DIR__ . '/../vendor/autoload.php';

// Define the URL where uploaded files are served from the main site.
// On production, this should be the absolute URL to your main site's uploads folder.
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', 'https://silknaviora.com/uploads');
}
define('IS_ADMIN_APP', true);

// Load the main application bootstrap
require __DIR__ . '/../../camping-uz/app/bootstrap.php';

// Admin UI component library. Loaded here rather than from partials/head.php
// so pages can compose header actions (buttons, badges) while assembling
// their $page array — i.e. before the layout is rendered.
require_once __DIR__ . '/../partials/ui.php';
require_once __DIR__ . '/../partials/nav.php';
