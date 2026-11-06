<?php
require_once 'db.php';

// สมมติตัวตนว่าคุณล็อกอินอยู่ในระบบด้วยบัญชี User ID: 1 (ชื่อ Somchai จากข้อมูลตัวอย่าง)
$current_user_id = 1; 

if (isset($_GET['id'])) {
    $user_book_id = $_GET['id'];

    try {
        // ใช้ Database Transaction เพื่อป้องกันปัญหาแต้มบั๊ก (ถ้าทำงานไม่ครบถ้วนทุกบรรทัดคำสั่ง ระบบจะสั่งยกเลิกทันที)
        $conn->beginTransaction(); 

        // 1. ตรวจสอบข้อมูลหนังสือและมูลค่าแต้ม
        $stmt = $conn->prepare("
            SELECT ub.user_id AS owner_id, b.book_value_points, ub.status 
            FROM User_Books ub
            JOIN Books_Master b ON ub.book_id = b.book_id
            WHERE ub.user_book_id = ?
        ");
        $stmt->execute([$user_book_id]);
        $book_info = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$book_info) {
            throw new Exception("ไม่พบข้อมูลหนังสือเล่มนี้ในระบบ");
        }
        if ($book_info['status'] != 'พร้อมแลก') {
            throw new Exception("หนังสือเล่มนี้ถูกผู้อื่นส่งคำขอแลกไปก่อนหน้านี้แล้ว");
        }
        if ($book_info['owner_id'] == $current_user_id) {
            throw new Exception("คุณไม่สามารถส่งคำขอแลกหนังสือของตัวเองได้");
        }

        // 2. ตรวจสอบกระเป๋าแต้มของผู้ใช้งานปัจจุบันว่าพอหักมัดจำหรือไม่
        $stmtUser = $conn->prepare("SELECT points FROM Users WHERE user_id = ?");
        $stmtUser->execute([$current_user_id]);
        $my_points = $stmtUser->fetchColumn();

        if ($my_points < $book_info['book_value_points']) {
            throw new Exception("แต้มของคุณไม่เพียงพอสำหรับการหักมัดจำ (ต้องการ " . $book_info['book_value_points'] . " แต้ม)");
        }

        // 3. เริ่มดำเนินการหักแต้มมัดจำออกจากกระเป๋าของผู้ขอแลก
        $new_points = $my_points - $book_info['book_value_points'];
        $updateUser = $conn->prepare("UPDATE Users SET points = ? WHERE user_id = ?");
        $updateUser->execute([$new_points, $current_user_id]);

        // 4. บันทึกข้อมูลตั้งต้นคำขอลงในตารางหลักสำหรับการจับคู่แลกเปลี่ยน (Swaps)
        $insertSwap = $conn->prepare("
            INSERT INTO Swaps (requester_id, receiver_id, requested_book_id, swap_status) 
            VALUES (?, ?, ?, 'รอดำเนินการ')
        ");
        $insertSwap->execute([$current_user_id, $book_info['owner_id'], $user_book_id]);
        $swap_id = $conn->lastInsertId();

        // 5. บันทึกประวัติสเตทเม้นท์แต้มขาออกลงตาราง Point_Transactions เพื่อความปลอดภัยย้อนหลัง
        $insertLog = $conn->prepare("
            INSERT INTO Point_Transactions (user_id, amount, transaction_type, reference_id) 
            VALUES (?, ?, 'ล็อกมัดจำ', ?)
        ");
        $insertLog->execute([$current_user_id, -$book_info['book_value_points'], $swap_id]);

        // 6. ล็อกสถานะหนังสือในชั้นเพื่อป้องกันการกดซ้ำซ้อน
        $updateBook = $conn->prepare("UPDATE User_Books SET status = 'อยู่ระหว่างดีล' WHERE user_book_id = ?");
        $updateBook->execute([$user_book_id]);

        // หากทำงานถูกต้องครบทั้ง 6 ขั้นตอนโดยไม่มีข้อผิดพลาด ให้ทำการบันทึกข้อมูลเข้าสู่ฐานข้อมูลจริง
        $conn->commit(); 
        echo "<script>alert('ส่งคำขอแลกสำเร็จ! ระบบทำการล็อกแต้มมัดจำเรียบร้อยแล้ว'); window.location='index.php';</script>";

    } catch (Exception $e) {
        // หากเกิดปัญหาระหว่างขั้นตอนใดก็ตาม ให้ Rollback ย้อนคืนคำสั่ง SQL ทั้งหมดกลับไปจุดเริ่มต้นเพื่อป้องกันดาต้าพัง
        $conn->rollBack(); 
        echo "<script>alert('เกิดข้อผิดพลาด: " . $e->getMessage() . "'); window.location='index.php';</script>";
    }
} else {
    header("Location: index.php");
    exit();
}
?>
