<?php
require __DIR__ . '/app/bootstrap.php';
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$type = $_POST['type'] ?? 'image';
$file = $_FILES['file'] ?? null;

if (!$file) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit;
}

if ($type === 'video') {
    [$ok, $res] = save_video($file, 'async', 60);
} else {
    [$ok, $res] = save_image($file, 'async', 10);
}

if ($ok) {
    echo json_encode([
        'success' => true,
        'path' => $res,
        'url' => upload_url($res)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $res
    ]);
}
