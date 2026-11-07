<?php
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    out(['error' => 'กรุณาเข้าสู่ระบบอีกครั้ง'], 401);
}

const CHAT_MAX_LEN      = 500;  // ตัวอักษรต่อข้อความ
const CHAT_MAX_PER_SWAP = 300;  // ข้อความรวมต่อรายการแลก (กันสแปมฐานข้อมูล)

$me     = (int)$_SESSION['user_id'];
$swapId = (int)($_GET['swap_id'] ?? $_POST['swap_id'] ?? 0);

try {
    // เฉพาะสองฝ่ายของรายการนี้เท่านั้นที่เข้าถึงแชทได้
    $stmt = $conn->prepare("SELECT requester_id, receiver_id, swap_status FROM Swaps WHERE swap_id = ?");
    $stmt->bind_param("i", $swapId);
    $stmt->execute();
    $stmt->bind_result($requesterId, $receiverId, $status);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || ($me !== (int)$requesterId && $me !== (int)$receiverId)) {
        out(['error' => 'ไม่มีสิทธิ์เข้าถึงแชทนี้'], 403);
    }

    $readonly = in_array($status, ['สำเร็จ', 'ปฏิเสธ'], true);

    // ---------- ส่งข้อความ ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        if ($readonly) {
            out(['error' => 'รายการนี้ปิดแล้ว ไม่สามารถส่งข้อความได้'], 403);
        }

        $body = trim($_POST['body'] ?? '');
        $len  = mb_strlen($body);
        if ($len < 1 || $len > CHAT_MAX_LEN) {
            out(['error' => 'ข้อความต้องยาว 1-' . CHAT_MAX_LEN . ' ตัวอักษร'], 422);
        }

        // จำกัดความถี่: ไม่เกิน 1 ข้อความต่อ 2 วินาที
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM Swap_Messages
             WHERE sender_id = ? AND created_at >= (NOW() - INTERVAL 2 SECOND)"
        );
        $stmt->bind_param("i", $me);
        $stmt->execute();
        $stmt->bind_result($recent);
        $stmt->fetch();
        $stmt->close();
        if ($recent > 0) {
            out(['error' => 'ส่งเร็วเกินไป กรุณารอสักครู่'], 429);
        }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM Swap_Messages WHERE swap_id = ?");
        $stmt->bind_param("i", $swapId);
        $stmt->execute();
        $stmt->bind_result($total);
        $stmt->fetch();
        $stmt->close();
        if ($total >= CHAT_MAX_PER_SWAP) {
            out(['error' => 'แชทนี้มีข้อความครบจำนวนสูงสุดแล้ว'], 422);
        }

        $stmt = $conn->prepare("INSERT INTO Swap_Messages (swap_id, sender_id, body) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $swapId, $me, $body);
        $stmt->execute();
        $stmt->close();

        out(['ok' => true]);
    }

    // ---------- ดึงข้อความ ----------
    $after = max(0, (int)($_GET['after'] ?? 0));

    if ($after === 0) {
        // โหลดครั้งแรก: 200 ข้อความล่าสุด
        $stmt = $conn->prepare(
            "SELECT m.message_id, m.sender_id, m.body, m.created_at, u.username
             FROM Swap_Messages m JOIN Users u ON u.user_id = m.sender_id
             WHERE m.swap_id = ?
             ORDER BY m.message_id DESC LIMIT 200"
        );
        $stmt->bind_param("i", $swapId);
        $stmt->execute();
        $rows = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    } else {
        $stmt = $conn->prepare(
            "SELECT m.message_id, m.sender_id, m.body, m.created_at, u.username
             FROM Swap_Messages m JOIN Users u ON u.user_id = m.sender_id
             WHERE m.swap_id = ? AND m.message_id > ?
             ORDER BY m.message_id ASC LIMIT 100"
        );
        $stmt->bind_param("ii", $swapId, $after);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();

    $messages = [];
    foreach ($rows as $r) {
        $messages[] = [
            'id'     => (int)$r['message_id'],
            'mine'   => ((int)$r['sender_id'] === $me),
            'sender' => $r['username'],
            'body'   => $r['body'],
            'time'   => date('d/m H:i', strtotime($r['created_at'])),
        ];
    }

    out(['messages' => $messages, 'readonly' => $readonly]);

} catch (Throwable $e) {
    error_log($e->getMessage());
    out(['error' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'], 500);
}
