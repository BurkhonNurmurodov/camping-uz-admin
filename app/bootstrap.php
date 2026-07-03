<?php
// Admin-specific bootstrap

// Define the URL where uploaded files are served from the main site.
// On production, this should be the absolute URL to your main site's uploads folder.
// For local testing, we assume the main site runs on port 8000.
define('UPLOAD_URL', getenv('UPLOAD_URL') ?: 'http://localhost:8000/uploads');
define('IS_ADMIN_APP', true);

// Load the main application bootstrap
// Force the admin panel to use the main site's upload URL so images don't break
define('UPLOAD_URL', 'https://silknaviora.com/uploads');

require __DIR__ . '/../../camping-uz/app/bootstrap.php';
