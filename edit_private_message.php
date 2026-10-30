<?php
require_once 'config.php';
require_once 'media_links.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); exit;
}
$user_id = $_SESSION['user_id'];
$message_id = (int)($_POST['message_id'] ?? 0);
$new_text = trim($_POST['message'] ?? '');
$file_url = trim($_POST['file_url'] ?? '');
$file_description = trim($_POST['file_description'] ?? '');

if (!$message_id || ($new_text === '' && $file_url === '')) {
    echo json_encode(['success' => false, 'error' => 'متن یا لینک رسانه باید وارد شود.']);
    exit;
}
if (!isAllowedMediaLink($pdo, $file_url)) {
    echo json_encode(['success' => false, 'error' => 'لینک رسانه باید HTTPS و از دامنه مجاز باشد.']);
    exit;
}

// بررسی وجود پیام و مالکیت
$stmt = $pdo->prepare("SELECT sender_id FROM private_messages WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$message_id]);
$msg = $stmt->fetch();

if (!$msg) {
    echo json_encode(['success' => false, 'error' => 'پیام یافت نشد یا حذف شده است']);
    exit;
}

if ($msg['sender_id'] != $user_id) {
    echo json_encode(['success' => false, 'error' => 'شما فقط می‌توانید پیام‌های خود را ویرایش کنید']);
    exit;
}

$pdo->prepare("UPDATE private_messages SET message = ?, file_url = ?, file_description = ? WHERE id = ?")
    ->execute([$new_text, $file_url ?: null, $file_description ?: null, $message_id]);

echo json_encode(['success' => true]);