<?php
require_once __DIR__ . '/config/auth.php';
requireLogin();

header('Content-Type: application/json');

$pin = trim($_POST['pin'] ?? '');

if (!$pin || !preg_match('/^\d{4}$/', $pin)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid PIN format.']);
    exit;
}

$pinFile = __DIR__ . '/config/subtract_pin.json';
if (!file_exists($pinFile)) {
    echo json_encode(['ok' => false, 'msg' => 'No PIN configured.']);
    exit;
}

$data = json_decode(file_get_contents($pinFile), true);
$hash = $data['pin_hash'] ?? '';

if ($hash && password_verify($pin, $hash)) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'msg' => 'Incorrect PIN.']);
}
