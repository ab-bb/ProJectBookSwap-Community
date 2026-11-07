<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rewards.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_swaps.php');
    exit;
}
verify_csrf();

class SwapActionException extends RuntimeException {}

$me     = (int)$_SESSION['user_id'];
$swapId = (int)($_POST['swap_id'] ?? 0);
$action = $_POST['action'] ?? '';

// เพิ่ม/คืนแต้ม พร้อมบันทึกประวัติ
function give_points(mysqli $conn, int $userId, int $amount, string $type, int $ref): void
{
    if ($amount <= 0) {
        return;
    }
    $s = $conn->prepare("UPDATE Users SET points = points + ? WHERE user_id = ?");
    $s->bind_param("ii", $amount, $userId);
    $s->execute();
    $s->close();

    $s = $conn->prepare(
        "INSERT INTO Point_Transactions (user_id, amount, transaction_type, reference_id)
         VALUES (?, ?, ?, ?)"
    );
    $s->bind_param("iisi", $userId, $amount, $type, $ref);
    $s->execute();
    $s->close();
}

function set_swap_status(mysqli $conn, int $swapId, string $status): void
{
    $s = $conn->prepare("UPDATE Swaps SET swap_status = ? WHERE swap_id = ?");
    $s->bind_param("si", $status, $swapId);
    $s->execute();
    $s->close();
}

function set_book_status(mysqli $conn, int $userBookId, string $status): void
{
    $s = $conn->prepare("UPDATE User_Books SET status = ? WHERE user_book_id = ?");
    $s->bind_param("si", $status, $userBookId);
    $s->execute();
    $s->close();
}

try {
    if ($swapId <= 0 || !in_array($action, ['accept', 'reject', 'ship', 'confirm', 'dispute'], true)) {
        throw new SwapActionException('คำสั่งไม่ถูกต้อง');
    }

    $conn->begin_transaction();

    // ล็อกแถวคำขอ กันกดซ้ำหรือสองฝ่ายกดชนกัน
    $stmt = $conn->prepare(
        "SELECT requester_id, receiver_id, requested_book_id, swap_status
         FROM Swaps WHERE swap_id = ? FOR UPDATE"
    );
    $stmt->bind_param("i", $swapId);
    $stmt->execute();
    $stmt->bind_result($requesterId, $receiverId, $userBookId, $status);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        throw new SwapActionException('ไม่พบรายการแลกนี้');
    }

    // จำนวนมัดจำที่ล็อกไว้ตอนขอแลก
    $stmt = $conn->prepare(
        "SELECT -amount FROM Point_Transactions
         WHERE reference_id = ? AND transaction_type = 'ล็อกมัดจำ'
         ORDER BY transaction_id ASC LIMIT 1"
    );
    $stmt->bind_param("i", $swapId);
    $stmt->execute();
    $stmt->bind_result($deposit);
    $depFound = $stmt->fetch();
    $stmt->close();

    if (!$depFound) {
        throw new RuntimeException("ไม่พบประวัติล็อกมัดจำของ swap_id {$swapId}");
    }
    $deposit = (int)$deposit;

    $isOwner     = ((int)$receiverId === $me);
    $isRequester = ((int)$requesterId === $me);
    $stale       = 'สถานะรายการเปลี่ยนไปแล้ว กรุณารีเฟรชหน้าแล้วลองใหม่';
    $noAuth      = 'คุณไม่มีสิทธิ์ทำรายการนี้';
    $msg         = '';

    switch ($action) {
        case 'accept':
            if (!$isOwner) throw new SwapActionException($noAuth);
            if ($status !== 'รอดำเนินการ') throw new SwapActionException($stale);
            set_swap_status($conn, $swapId, 'ยอมรับแล้ว');
            $msg = 'ยอมรับคำขอแล้ว กรุณาจัดส่งหนังสือและกรอกเลขพัสดุ';
            break;

        case 'reject':
            if (!$isOwner) throw new SwapActionException($noAuth);
            if ($status !== 'รอดำเนินการ') throw new SwapActionException($stale);
            give_points($conn, (int)$requesterId, $deposit, 'คืนมัดจำ', $swapId);
            set_swap_status($conn, $swapId, 'ปฏิเสธ');
            set_book_status($conn, (int)$userBookId, 'พร้อมแลก');
            $msg = 'ปฏิเสธคำขอแล้ว หนังสือกลับขึ้นชั้นอีกครั้ง และคืนมัดจำให้ผู้ขอแล้ว';
            break;

        case 'ship':
            if (!$isOwner) throw new SwapActionException($noAuth);
            if ($status !== 'ยอมรับแล้ว') throw new SwapActionException($stale);
            $tracking = trim($_POST['tracking_number'] ?? '');
            if (!preg_match('/^[A-Za-z0-9\-]{5,50}$/', $tracking)) {
                throw new SwapActionException('เลขพัสดุต้องเป็นตัวอักษรอังกฤษ ตัวเลข หรือ - ยาว 5-50 ตัว');
            }
            $s = $conn->prepare("UPDATE Swaps SET swap_status = 'ส่งของแล้ว', tracking_number = ? WHERE swap_id = ?");
            $s->bind_param("si", $tracking, $swapId);
            $s->execute();
            $s->close();
            $msg = 'บันทึกการจัดส่งแล้ว รอผู้รับกดยืนยัน';
            break;

        case 'confirm':
            if (!$isRequester) throw new SwapActionException($noAuth);
            if ($status !== 'ส่งของแล้ว') throw new SwapActionException($stale);
            give_points($conn, (int)$requesterId, $deposit, 'คืนมัดจำ', $swapId);
            give_points($conn, (int)$requesterId, REWARD_SWAP_SUCCESS, 'โบนัสแลกสำเร็จ', $swapId);
            give_points($conn, (int)$receiverId, REWARD_SWAP_SUCCESS, 'โบนัสแลกสำเร็จ', $swapId);
            set_swap_status($conn, $swapId, 'สำเร็จ');
            set_book_status($conn, (int)$userBookId, 'แลกสำเร็จแล้ว');
            $msg = "แลกสำเร็จ! คืนมัดจำ {$deposit} แต้ม และได้โบนัส " . REWARD_SWAP_SUCCESS . ' แต้ม';
            break;

        case 'dispute':
            if (!$isRequester) throw new SwapActionException($noAuth);
            if ($status !== 'ส่งของแล้ว') throw new SwapActionException($stale);
            set_swap_status($conn, $swapId, 'มีข้อพิพาท');
            $msg = 'แจ้งข้อพิพาทแล้ว มัดจำจะถูกล็อกไว้จนกว่าผู้ดูแลระบบจะตรวจสอบ';
            break;
    }

    $conn->commit();
    $_SESSION['flash'] = ['type' => 'ok', 'msg' => $msg];

} catch (SwapActionException $e) {
    $conn->rollback();
    $_SESSION['flash'] = ['type' => 'err', 'msg' => $e->getMessage()];
} catch (Throwable $e) {
    $conn->rollback();
    error_log($e->getMessage());
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'ทำรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'];
}

header('Location: my_swaps.php');
exit;
