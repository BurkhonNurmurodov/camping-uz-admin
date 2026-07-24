<?php
// router.php for PHP built-in server (dev only)
if (php_sapi_name() !== 'cli-server') {
    die('this is only for the php built-in server');
}

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// Serve static files as-is
if (is_file(__DIR__ . $path)) {
    return false;
}

// Emulate .htaccess rewrites
if (preg_match('#^/email/?$#', $path)) {
    require __DIR__ . '/email.php';
    return true;
}
if (preg_match('#^/tour-edit/([0-9]+)$#', $path, $matches)) {
    $_GET['id'] = $matches[1];
    require __DIR__ . '/tour-edit.php';
    return true;
}
if (preg_match('#^/guide-edit/([0-9]+)$#', $path, $matches)) {
    $_GET['id'] = $matches[1];
    require __DIR__ . '/guide-edit.php';
    return true;
}
if (preg_match('#^/testimonial-edit/([0-9]+)$#', $path, $matches)) {
    $_GET['id'] = $matches[1];
    require __DIR__ . '/testimonial-edit.php';
    return true;
}

// Extensionless .php routing
if (is_file(__DIR__ . $path . '.php')) {
    require __DIR__ . $path . '.php';
    return true;
}

// Default to index.php if directory requested
if (is_dir(__DIR__ . $path) && is_file(__DIR__ . $path . '/index.php')) {
    require __DIR__ . $path . '/index.php';
    return true;
}

// 404
return false;
