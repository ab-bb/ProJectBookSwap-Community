<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rewards.php';

require_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $condition = $_POST['book_condition'] ?? '';

    $allowedConditions = ['ใหม่มาก', 'ปานกลาง', 'เก่า'];

    if ($title === '' || $author === '' || $category === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (!in_array($condition, $allowedConditions, true)) {
        $error = 'กรุณาเลือกสภาพหนังสือให้ถูกต้อง';
    } elseif (mb_strlen($title) > 255 ||
              mb_strlen($author) > 100 ||
              mb_strlen($category) > 50) {
        $error = 'ข้อมูลมีความยาวเกินกำหนด';
    } else {
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare(
                "SELECT book_id FROM Books_Master
                 WHERE title = ? AND author = ?
                 LIMIT 1"
            );

            $stmt->bind_param("ss", $title, $author);
            $stmt->execute();
            $stmt->bind_result($bookId);
            $found = $stmt->fetch();
            $stmt->close();

            if (!$found) {
                $stmt = $conn->prepare(
                    "INSERT INTO Books_Master
                    (title, author, category, book_value_points)
                    VALUES (?, ?, ?, 20)"
                );

                $stmt->bind_param("sss", $title, $author, $category);
                $stmt->execute();
                $bookId = $conn->insert_id;
                $stmt->close();
            }

            $stmt = $conn->prepare(
                "SELECT user_book_id FROM User_Books
                 WHERE user_id = ? AND book_id = ?
                 LIMIT 1"
            );

            $stmt->bind_param("ii", $_SESSION['user_id'], $bookId);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $error = 'คุณลงทะเบียนหนังสือเล่มนี้ไว้แล้ว';
                $stmt->close();
                $conn->rollback();
            } else {
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO User_Books
                    (user_id, book_id, book_condition, status)
                    VALUES (?, ?, ?, 'พร้อมแลก')"
                );

                $stmt->bind_param("iis", $_SESSION['user_id'], $bookId, $condition);
                $stmt->execute();
                $userBookId = $conn->insert_id;
                $stmt->close();

                // รางวัลฝากหนังสือ: จำกัดจำนวนครั้งต่อ 7 วัน เพื่อกันการสร้างหนังสือปลอมปั๊มแต้ม
                $uid = (int)$_SESSION['user_id'];

                $stmt = $conn->prepare("SELECT user_id FROM Users WHERE user_id = ? FOR UPDATE");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $stmt->store_result();
                $stmt->close();

                $stmt = $conn->prepare(
                    "SELECT COUNT(*) FROM Point_Transactions
                     WHERE user_id = ? AND transaction_type = 'ลงทะเบียนหนังสือ'
                       AND created_at >= (NOW() - INTERVAL 7 DAY)"
                );
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $stmt->bind_result($rewardedThisWeek);
                $stmt->fetch();
                $stmt->close();

                if ($rewardedThisWeek < REWARD_ADD_BOOK_WEEKLY_MAX) {
                    $reward = REWARD_ADD_BOOK;

                    $stmt = $conn->prepare("UPDATE Users SET points = points + ? WHERE user_id = ?");
                    $stmt->bind_param("ii", $reward, $uid);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "INSERT INTO Point_Transactions (user_id, amount, transaction_type, reference_id)
                         VALUES (?, ?, 'ลงทะเบียนหนังสือ', ?)"
                    );
                    $stmt->bind_param("iii", $uid, $reward, $userBookId);
                    $stmt->execute();
                    $stmt->close();

                    $_SESSION['flash'] = ['type' => 'ok', 'msg' => "ฝากหนังสือสำเร็จ ได้รับ {$reward} แต้ม"];
                }

                $conn->commit();

                header('Location: index.php?added=1');
                exit;
            }

        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log($e->getMessage());
            $error = 'ไม่สามารถลงทะเบียนหนังสือได้ กรุณาลองใหม่';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ฝากหนังสือ | BookSwap</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Thai:wght@600;700&family=Sarabun:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="theme.css">
</head>
<body class="centered">

<div class="dust" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>

<div class="form-container wide">
    <div class="form-logo"><i class="fa-solid fa-book-medical"></i></div>
    <h2>ฝากหนังสือเข้าชั้นลับ</h2>
    <p class="subtitle">กรอกข้อมูลหนังสือที่คุณอยากส่งต่อให้คนอื่น<br>ฝากเล่มใหม่รับ <?= e(REWARD_ADD_BOOK) ?> แต้ม (รับได้สูงสุด <?= e(REWARD_ADD_BOOK_WEEKLY_MAX) ?> ครั้งต่อ 7 วัน)</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="add_book.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="input-group">
            <label for="title">ชื่อหนังสือ</label>
            <input type="text" id="title" name="title" maxlength="255" placeholder="กรอกชื่อหนังสือ" value="<?= e($_POST['title'] ?? '') ?>" required>
        </div>

        <div class="input-group">
            <label for="author">ชื่อผู้แต่ง</label>
            <input type="text" id="author" name="author" maxlength="100" placeholder="กรอกชื่อผู้แต่ง" value="<?= e($_POST['author'] ?? '') ?>" required>
        </div>

        <div class="input-group">
            <label for="category">หมวดหมู่</label>
            <input type="text" id="category" name="category" maxlength="50" placeholder="เช่น นิยาย, การ์ตูน, วิชาการ" value="<?= e($_POST['category'] ?? '') ?>" required>
        </div>

        <div class="input-group">
            <label for="book_condition">สภาพหนังสือ</label>
            <?php $selCond = $_POST['book_condition'] ?? ''; ?>
            <select id="book_condition" name="book_condition" required>
                <option value="">-- เลือกสภาพหนังสือ --</option>
                <?php foreach (['ใหม่มาก', 'ปานกลาง', 'เก่า'] as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= $selCond === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn-submit"><i class="fa-solid fa-plus"></i> ฝากหนังสือ</button>
    </form>

    <div class="footer-link">
        <a href="index.php"><i class="fa-solid fa-arrow-left"></i> กลับชั้นหนังสือ</a>
    </div>
</div>

</body>
</html>