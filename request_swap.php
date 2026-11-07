<?php
require_once __DIR__ . '/includes/auth.php';

require_login();

// เปลี่ยนสถานะข้อมูล ต้องเป็น POST + CSRF เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verify_csrf();

$meId       = (int)$_SESSION['user_id'];
$userBookId = (int)($_POST['user_book_id'] ?? 0);
$redirect   = 'index.php';

// ข้อความที่แสดงให้ผู้ใช้เห็นได้ ใช้ exception ชนิดนี้เท่านั้น
class SwapException extends RuntimeException {}

try {
    if ($userBookId <= 0) {
        throw new SwapException('ไม่พบหนังสือเล่มนี้ในระบบ');
    }

    $conn->begin_transaction();

    // 1) ล็อกแถวหนังสือ กันสองคนกดหยิบเล่มเดียวกันพร้อมกัน
    $stmt = $conn->prepare(
        "SELECT ub.user_id, ub.status, b.book_value_points
         FROM User_Books ub
         JOIN Books_Master b ON ub.book_id = b.book_id
         WHERE ub.user_book_id = ?
         FOR UPDATE"
    );
    $stmt->bind_param("i", $userBookId);
    $stmt->execute();
    $stmt->bind_result($ownerId, $status, $deposit);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        throw new SwapException('ไม่พบหนังสือเล่มนี้ในระบบ');
    }
    if ($status !== 'พร้อมแลก') {
        throw new SwapException('หนังสือเล่มนี้มีคนขอแลกไปก่อนแล้ว');
    }
    if ((int)$ownerId === $meId) {
        throw new SwapException('คุณไม่สามารถขอแลกหนังสือของตัวเองได้');
    }

    // 2) ล็อกแถวผู้ใช้ แล้วเช็คแต้มพอหักมัดจำหรือไม่
    $stmt = $conn->prepare("SELECT points FROM Users WHERE user_id = ? FOR UPDATE");
    $stmt->bind_param("i", $meId);
    $stmt->execute();
    $stmt->bind_result($myPoints);
    $stmt->fetch();
    $stmt->close();

    if ($myPoints < $deposit) {
        throw new SwapException("แต้มของคุณไม่พอสำหรับมัดจำ (ต้องการ {$deposit} แต้ม คุณมี {$myPoints} แต้ม)");
    }

    // 3) หักแต้มมัดจำ
    $stmt = $conn->prepare("UPDATE Users SET points = points - ? WHERE user_id = ?");
    $stmt->bind_param("ii", $deposit, $meId);
    $stmt->execute();
    $stmt->close();

    // 4) สร้างคำขอแลก
    $stmt = $conn->prepare(
        "INSERT INTO Swaps (requester_id, receiver_id, requested_book_id, swap_status)
         VALUES (?, ?, ?, 'รอดำเนินการ')"
    );
    $stmt->bind_param("iii", $meId, $ownerId, $userBookId);
    $stmt->execute();
    $swapId = $conn->insert_id;
    $stmt->close();

    // 5) บันทึกประวัติแต้ม
    $negDeposit = -$deposit;
    $stmt = $conn->prepare(
        "INSERT INTO Point_Transactions (user_id, amount, transaction_type, reference_id)
         VALUES (?, ?, 'ล็อกมัดจำ', ?)"
    );
    $stmt->bind_param("iii", $meId, $negDeposit, $swapId);
    $stmt->execute();
    $stmt->close();

    // 6) ล็อกสถานะหนังสือ
    $stmt = $conn->prepare("UPDATE User_Books SET status = 'อยู่ระหว่างดีล' WHERE user_book_id = ?");
    $stmt->bind_param("i", $userBookId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    $_SESSION['flash'] = ['type' => 'ok', 'msg' => "ส่งคำขอแลกแล้ว ล็อกมัดจำ {$deposit} แต้ม รอเจ้าของหนังสือตอบรับ"];
    $redirect = 'my_swaps.php';

} catch (SwapException $e) {
    $conn->rollback();
    $_SESSION['flash'] = ['type' => 'err', 'msg' => $e->getMessage()];
} catch (Throwable $e) {
    $conn->rollback();
    error_log($e->getMessage());
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'ส่งคำขอไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'];
}

header('Location: ' . $redirect);
exit;