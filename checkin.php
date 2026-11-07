<?php
require_once __DIR__ . '/includes/auth.php';

require_login();
date_default_timezone_set('Asia/Bangkok');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verify_csrf();

require_once __DIR__ . '/includes/rewards.php';

$userId    = (int)$_SESSION['user_id'];
$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

try {
    $conn->begin_transaction();

    // ล็อกแถวผู้ใช้ กันกดซ้ำพร้อมกันหลายแท็บ
    $stmt = $conn->prepare(
        "SELECT last_checkin, checkin_streak FROM Users WHERE user_id = ? FOR UPDATE"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($last, $streak);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        throw new RuntimeException('user not found');
    }

    if ($last === $today) {
        $conn->rollback();
        $_SESSION['flash'] = ['type' => 'err', 'msg' => 'วันนี้คุณประทับตราไปแล้ว กลับมาใหม่พรุ่งนี้นะ'];
        header('Location: index.php');
        exit;
    }

    $streak = ($last === $yesterday) ? ((int)$streak + 1) : 1;

    $reward = REWARD_CHECKIN_DAILY;
    $bonus  = ($streak % 7 === 0);
    if ($bonus) {
        $reward += REWARD_CHECKIN_STREAK_BONUS;
    }

    $upd = $conn->prepare(
        "UPDATE Users
         SET points = points + ?, last_checkin = ?, checkin_streak = ?
         WHERE user_id = ?"
    );
    $upd->bind_param("isii", $reward, $today, $streak, $userId);
    $upd->execute();
    $upd->close();

    $log = $conn->prepare(
        "INSERT INTO Point_Transactions (user_id, amount, transaction_type)
         VALUES (?, ?, 'เช็คอินรายวัน')"
    );
    $log->bind_param("ii", $userId, $reward);
    $log->execute();
    $log->close();

    $conn->commit();

    $msg = "ประทับตราสำเร็จ! ได้รับ {$reward} แต้ม (ต่อเนื่อง {$streak} วัน)";
    if ($bonus) {
        $msg .= ' รวมโบนัสครบ 7 วันแล้ว';
    }
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => $msg];

} catch (Throwable $e) {
    $conn->rollback();
    error_log($e->getMessage());
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'ประทับตราไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'];
}

header('Location: index.php');
exit;
