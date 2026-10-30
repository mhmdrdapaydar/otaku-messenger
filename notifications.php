<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];

if (isset($_GET['mark_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user_id]);
    echo 'ok'; exit;
}

$notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$notifs->execute([$user_id]);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8"><title>اعلان‌ها</title></head>
<body style="font-family:Tahoma; background:#f4f4f5; padding:10px;">
    <button onclick="markRead()">علامت‌گذاری همه به‌عنوان خوانده‌شده</button>
    <ul>
    <?php foreach ($notifs as $n): ?>
        <li style="<?= $n['is_read'] ? 'opacity:0.5' : '' ?>">
            <?= htmlspecialchars($n['message']) ?> - <?= $n['created_at'] ?>
        </li>
    <?php endforeach; ?>
    </ul>
<script>
function markRead() {
    fetch('notifications.php?mark_read=1').then(() => location.reload());
}
</script>
</body>
</html>