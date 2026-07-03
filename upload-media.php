<?php
/**
 * AJAX endpoint: receives an image from the Quill editor, stores it under
 * /uploads/media and returns its URL as JSON. Admin-only, CSRF-protected.
 */
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json');

if (!is_admin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    http_response_code(419);
    echo json_encode(['error' => 'Invalid session token']);
    exit;
}

[$ok, $res] = save_image($_FILES['file'] ?? null, 'media', 8);
if (!$ok) {
    http_response_code(422);
    echo json_encode(['error' => $res]);
    exit;
}

echo json_encode(['url' => upload_url($res)]);
