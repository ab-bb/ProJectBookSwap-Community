<?php
require_once __DIR__ . '/includes/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_swaps.php');
    exit;
}
verify_csrf();

class ReviewException extends RuntimeException {}

$me     = (int)$_SESSION['user_id'];
$swapId = (int)($_POST['swap_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

try {
    if ($rating < 1 || $rating > 5) {
        throw new ReviewException('กรุณาเลือกคะแนน 1-5 ดาว');
    }
    if (mb_strlen($comment) > 500) {
        throw new ReviewException('ความเห็นยาวเกิน 500 ตัวอักษร');
    }

    $stmt = $conn->prepare("SELECT requester_id, receiver_id, swap_status FROM Swaps WHERE swap_id = ?");
    $stmt->bind_param("i", $swapId);
    $stmt->execute();
    $stmt->bind_result($requesterId, $receiverId, $status);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || ($me !== (int)$requesterId && $me !== (int)$receiverId)) {
        throw new ReviewException('คุณไม่มีสิทธิ์รีวิวรายการนี้');
    }
    if ($status !== 'สำเร็จ') {
        throw new ReviewException('รีวิวได้เฉพาะรายการที่แลกสำเร็จแล้ว');
    }

    $reviewedId = ($me === (int)$requesterId) ? (int)$receiverId : (int)$requesterId;
    $commentVal = ($comment === '') ? null : $comment;

    $stmt = $conn->prepare(
        "INSERT INTO User_Reviews (swap_id, reviewer_id, reviewed_user_id, rating, comment)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iiiis", $swapId, $me, $reviewedId, $rating, $commentVal);
    $stmt->execute();
    $stmt->close();

    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'ส่งรีวิวเรียบร้อย ขอบคุณที่ช่วยให้ชุมชนไว้ใจกันมากขึ้น'];

} catch (ReviewException $e) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => $e->getMessage()];
} catch (mysqli_sql_exception $e) {
    if ((int)$e->getCode() === 1062) {
        $_SESSION['flash'] = ['type' => 'err', 'msg' => 'คุณรีวิวรายการนี้ไปแล้ว'];
    } else {
        error_log($e->getMessage());
        $_SESSION['flash'] = ['type' => 'err', 'msg' => 'ส่งรีวิวไม่สำเร็จ กรุณาลองใหม่'];
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'ส่งรีวิวไม่สำเร็จ กรุณาลองใหม่'];
}

header('Location: my_swaps.php');
exit;
