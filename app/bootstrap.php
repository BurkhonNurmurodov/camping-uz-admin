<?php
// Admin-specific bootstrap

// Define the URL where uploaded files are served from the main site.
// On production, this should be the absolute URL to your main site's uploads folder.
if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', 'https://silknaviora.com/uploads');
}
define('IS_ADMIN_APP', true);

// Load the main application bootstrap
require __DIR__ . '/../../camping-uz/app/bootstrap.php';
