<?php
/**
 * One-time installer. Visit /admin/setup.php once after configuring the DB.
 * - Creates all tables from db/schema.sql
 * - Seeds the default admin (admin / password), default settings and the About page.
 * Safe to re-run: it only seeds rows that are missing.
 *
 * Delete or block this file after setup on production.
 */
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

// Once the site is installed, only a signed-in admin may re-run the installer.
try {
    $installed = (int) db_val('SELECT COUNT(*) FROM admins') > 0;
} catch (Throwable $e) {
    $installed = false; // tables not created yet → fresh install allowed
}
if ($installed && !is_admin()) {
    http_response_code(403);
    exit("Already installed. Sign in to /admin and delete this file (admin/setup.php) on production.\n");
}

$steps = [];

// 1) schema
try {
    $sql = file_get_contents(APP_ROOT . '/db/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Cannot read db/schema.sql');
    }
    // Run the whole script (PDO can execute multiple statements in one exec()).
    db()->exec($sql);
    $steps[] = '[ok] schema applied';
} catch (Throwable $e) {
    $steps[] = '[FAIL] schema: ' . $e->getMessage();
    echo implode("\n", $steps), "\n";
    exit;
}

// 2) default admin
$adminCount = (int) db_val('SELECT COUNT(*) FROM admins');
if ($adminCount === 0) {
    db_run(
        'INSERT INTO admins (username, password_hash, display_name) VALUES (?, ?, ?)',
        [DEFAULT_ADMIN_USER, password_hash(DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT), 'Administrator']
    );
    $steps[] = '[ok] default admin created (' . DEFAULT_ADMIN_USER . ' / ' . DEFAULT_ADMIN_PASS . ') — change it in Settings';
} else {
    $steps[] = '[skip] admin already exists';
}

// 3) default settings
$defaults = [
    'agency_name_en' => 'Silk Naviora',
    'agency_name_ru' => 'Silk Naviora',
    'moto_en'        => 'Real journeys across Central Asia',
    'moto_ru'        => 'Настоящие путешествия по Центральной Азии',
    'hero_type'      => 'image',       // image | video
    'hero_image'     => '',
    'hero_video'     => '',
    'default_lang'   => DEFAULT_LANG,
    'telegram_bot_token' => '',
    'telegram_chat_id'   => '',
    'google_maps_api_key'=> '',
    'social_instagram'   => '',
    'social_telegram'    => '',
    'social_facebook'    => '',
    'social_whatsapp'    => '',
    'mail_imap_host'     => 'mail.silknaviora.uz',
    'mail_username'      => 'info@silknaviora.uz',
    'mail_password'      => '',
    'mail_smtp_host'     => 'mail.silknaviora.uz',
    'mail_smtp_port'     => '465',
];
$added = 0;
foreach ($defaults as $k => $v) {
    $exists = db_val('SELECT 1 FROM settings WHERE `key`=?', [$k]);
    if (!$exists) {
        db_run('INSERT INTO settings (`key`,`value`) VALUES (?,?)', [$k, $v]);
        $added++;
    }
}
$steps[] = "[ok] settings seeded ({$added} new)";

// 4) About page placeholder
$hasAbout = db_val("SELECT 1 FROM pages WHERE `key`='about'");
if (!$hasAbout) {
    db_run(
        "INSERT INTO pages (`key`, title_en, title_ru, body_en_html, body_ru_html) VALUES ('about', ?, ?, ?, ?)",
        ['About us', 'О нас', null, null]
    );
    $steps[] = '[ok] about page created';
} else {
    $steps[] = '[skip] about page exists';
}

$steps[] = '';
$steps[] = 'Done. Open /admin/login.php';
echo implode("\n", $steps), "\n";
