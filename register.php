<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (!preg_match('/^[\p{L}\p{N}_]{3,50}$/u', $username)) {
        $error = 'Username ต้องมี 3-50 ตัวอักษร และใช้ตัวอักษร ตัวเลข หรือ _ เท่านั้น';
    } elseif (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'รูปแบบ Email ไม่ถูกต้อง หรือมีความยาวเกินกำหนด';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $error = 'Password ต้องมีความยาว 8-72 ไบต์';
    } elseif ($password !== $confirm) {
        $error = 'Password และ Confirm Password ไม่ตรงกัน';
    } else {
        try {
            $check = $conn->prepare("SELECT user_id FROM Users WHERE username = ? OR email = ? LIMIT 1");
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error = 'Username หรือ Email นี้ถูกใช้งานแล้ว';
            }
            $check->close();

            if ($error === '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("INSERT INTO Users (username, email, password_hash, points) VALUES (?, ?, ?, 20)");
                    $stmt->bind_param("sss", $username, $email, $passwordHash);
                    $stmt->execute();
                    $newUserId = $conn->insert_id;
                    $stmt->close();

                    $log = $conn->prepare("INSERT INTO Point_Transactions (user_id, amount, transaction_type) VALUES (?, 20, 'สมัครสมาชิก')");
                    $log->bind_param("i", $newUserId);
                    $log->execute();
                    $log->close();

                    $conn->commit();

                    $_SESSION['register_success'] = 'สมัครสมาชิกสำเร็จ! คุณได้รับ 20 แต้ม';
                    header('Location: login.php');
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            }
        } catch (mysqli_sql_exception $e) {
            error_log($e->getMessage());
            if ((int)$e->getCode() === 1062) {
                $error = 'Username หรือ Email นี้ถูกใช้งานแล้ว';
            } else {
                $error = 'เกิดข้อผิดพลาดในการสมัครสมาชิก กรุณาลองใหม่';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>สมัครสมาชิก | BookSwap</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Thai:wght@600;700&family=Sarabun:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="theme.css">
</head>
<body class="centered">

<div class="dust" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>

<div class="form-container">
    <div class="form-logo"><i class="fa-solid fa-feather-pointed"></i></div>
    <h2>ลงนามเข้าห้องสมุดลับ</h2>
    <p class="subtitle">ร่วมเป็นส่วนหนึ่งของชุมชนแลกเปลี่ยนหนังสือ</p>

    <div class="points-box"><i class="fa-solid fa-coins"></i> สมัครวันนี้ รับฟรี 20 แต้ม<br>แต้มใช้เป็นมัดจำเมื่อคุณหยิบหนังสือของคนอื่น</div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="register.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="input-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" placeholder="ตั้งชื่อผู้ใช้งาน" value="<?= e($_POST['username'] ?? '') ?>" maxlength="50" required>
        </div>

        <div class="input-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="example@email.com" value="<?= e($_POST['email'] ?? '') ?>" maxlength="100" required>
        </div>

        <div class="input-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="อย่างน้อย 8 ตัวอักษร" minlength="8" required>
        </div>

        <div class="input-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" placeholder="กรอกรหัสผ่านอีกครั้ง" minlength="8" required>
        </div>

        <button type="submit" class="btn-submit">สมัครสมาชิก</button>
    </form>

    <div class="footer-link">
        มีบัญชีแล้ว? <a href="login.php">เข้าสู่ระบบ</a>
    </div>
</div>

</body>
</html>