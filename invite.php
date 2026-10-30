<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$user_id = $_SESSION['user_id'];
$hash = $_GET['hash'] ?? '';

if (!$hash) {
    die('لینک نامعتبر.');
}

// یافتن لینک دعوت معتبر
$stmt = $pdo->prepare("SELECT * FROM group_invite_links WHERE hash = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())");
$stmt->execute([$hash]);
$link = $stmt->fetch();

if (!$link) {
    die('لینک دعوت منقضی یا غیرفعال شده است.');
}

// بررسی محدودیت حداکثر استفاده
if ($link['max_uses'] > 0 && $link['current_uses'] >= $link['max_uses']) {
    die('ظرفیت استفاده از این لینک به پایان رسیده است.');
}

$group_id = $link['group_id'];

// بررسی بن
$ban = $pdo->prepare("SELECT 1 FROM group_bans WHERE group_id = ? AND user_id = ?");
$ban->execute([$group_id, $user_id]);
if ($ban->fetchColumn()) {
    die('شما از این گروه بن شده‌اید و نمی‌توانید عضو شوید.');
}

// بررسی عضویت قبلی
$member = $pdo->prepare("SELECT 1 FROM group_channel_members WHERE group_id = ? AND user_id = ?");
$member->execute([$group_id, $user_id]);
if ($member->fetchColumn()) {
    header('Location: index.php?page=group&id=' . $group_id);
    exit;
}

// افزودن عضو
$pdo->prepare("INSERT INTO group_channel_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$group_id, $user_id]);

// افزایش شمارنده استفاده
$pdo->prepare("UPDATE group_invite_links SET current_uses = current_uses + 1 WHERE id = ?")->execute([$link['id']]);

header('Location: index.php?page=group&id=' . $group_id);
exit;