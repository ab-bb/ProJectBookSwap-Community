<?php
require_once __DIR__ . '/includes/auth.php';

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

            // ค้นหาหนังสือที่มีอยู่แล้ว
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

            // ถ้าไม่มี ให้เพิ่มหนังสือใหม่
            if (!$found) {
                $stmt = $conn->prepare(
                    "INSERT INTO Books_Master
                    (title, author, category, book_value_points)
                    VALUES (?, ?, ?, 20)"
                );

                $stmt->bind_param(
                    "sss",
                    $title,
                    $author,
                    $category
                );

                $stmt->execute();
                $bookId = $conn->insert_id;
                $stmt->close();
            }

            // ตรวจสอบว่าผู้ใช้ลงทะเบียนเล่มนี้แล้วหรือยัง
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

                $stmt->bind_param(
                    "iis",
                    $_SESSION['user_id'],
                    $bookId,
                    $condition
                );

                $stmt->execute();
                $stmt->close();

                $conn->commit();

                header('Location: index.php');
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ลงทะเบียนหนังสือ | BookSwap</title>

<style>
* { box-sizing: border-box; }

body {
    font-family: 'Segoe UI', sans-serif;
    background: #f2f5f9;
    margin: 0;
    padding: 30px 15px;
    color: #2c3e50;
}

.container {
    max-width: 550px;
    margin: auto;
    background: white;
    padding: 30px;
    border-radius: 14px;
    box-shadow: 0 5px 20px rgba(0,0,0,.07);
}

h1 { margin-top: 0; }

label {
    display: block;
    margin-top: 18px;
    font-weight: bold;
}

input, select {
    width: 100%;
    padding: 12px;
    margin-top: 7px;
    border: 1px solid #ddd;
    border-radius: 7px;
    font-size: 15px;
}

button {
    width: 100%;
    margin-top: 25px;
    padding: 14px;
    border: none;
    border-radius: 7px;
    background: #27ae60;
    color: white;
    font-size: 16px;
    cursor: pointer;
}

.error {
    background: #fdeaea;
    color: #c0392b;
    padding: 12px;
    border-radius: 7px;
}

.back {
    display: inline-block;
    margin-top: 18px;
    color: #2980b9;
    text-decoration: none;
}
</style>
</head>

<body>
<div class="container">

<h1>📚 ลงทะเบียนหนังสือ</h1>
<p>กรอกข้อมูลหนังสือที่คุณต้องการนำมาแลกเปลี่ยน</p>

<?php if ($error): ?>
    <div class="error"><?= e($error) ?></div>
<?php endif; ?>

<form method="POST" action="add_book.php">

<input type="hidden"
       name="csrf_token"
       value="<?= e(csrf_token()) ?>">

<label>ชื่อหนังสือ</label>
<input type="text"
       name="title"
       maxlength="255"
       placeholder="กรอกชื่อหนังสือ"
       value="<?= e($_POST['title'] ?? '') ?>"
       required>

<label>ชื่อผู้แต่ง</label>
<input type="text"
       name="author"
       maxlength="100"
       placeholder="กรอกชื่อผู้แต่ง"
       value="<?= e($_POST['author'] ?? '') ?>"
       required>

<label>หมวดหมู่</label>
<input type="text"
       name="category"
       maxlength="50"
       placeholder="เช่น นิยาย, การ์ตูน, วิชาการ"
       value="<?= e($_POST['category'] ?? '') ?>"
       required>

<label>สภาพหนังสือ</label>
<select name="book_condition" required>
    <option value="">-- เลือกสภาพหนังสือ --</option>
    <option value="ใหม่มาก">ใหม่มาก</option>
    <option value="ปานกลาง">ปานกลาง</option>
    <option value="เก่า">เก่า</option>
</select>

<button type="submit">
    + ลงทะเบียนหนังสือ
</button>

</form>

<a href="index.php" class="back">← กลับหน้าหลัก</a>

</div>
</body>
</html>
