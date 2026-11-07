<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = $_SESSION['register_success'] ?? '';
unset($_SESSION['register_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $identity = trim($_POST['identity'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identity === '' || $password === '') {
        $error = 'กรุณากรอก Username/Email และ Password';
    } elseif (strlen($identity) > 100 || strlen($password) > 255) {
        $error = 'ข้อมูลที่กรอกยาวเกินกำหนด';
    } else {
        try {
            $stmt = $conn->prepare(
                "SELECT user_id, username, email, password_hash
                 FROM Users
                 WHERE username = ? OR email = ?
                 LIMIT 1"
            );

            $stmt->bind_param("ss", $identity, $identity);
            $stmt->execute();
            $stmt->bind_result($userId, $username, $email, $passwordHash);
            $found = $stmt->fetch();
            $stmt->close();

            if ($found && password_verify($password, $passwordHash)) {
                // ป้องกัน Session Fixation
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$userId;
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;

                // สร้าง CSRF Token ใหม่หลัง Login
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: index.php');
                exit;
            } else {
                $error = 'Username/Email หรือ Password ไม่ถูกต้อง';
            }
        } catch (mysqli_sql_exception $e) {
            error_log($e->getMessage());
            $error = 'ไม่สามารถเข้าสู่ระบบได้ กรุณาลองใหม่';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ปลดล็อกประตูห้องสมุด | BookSwap</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Thai:wght@600;700&family=Sarabun:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="theme.css">
</head>
<body class="centered">

<div class="dust" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>

<div class="form-container">
    <div class="form-logo"><i class="fa-solid fa-key"></i></div>
    <h2>ปลดล็อกประตูห้องสมุดลับ</h2>
    <p class="subtitle">กรอกข้อมูลเพื่อกลับเข้าสู่ชั้นหนังสือลับของคุณ</p>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="input-group">
            <label for="identity">Username หรือ Email</label>
            <input type="text" id="identity" name="identity" placeholder="กรอก Username หรือ Email" value="<?= e($_POST['identity'] ?? '') ?>" required>
        </div>

        <div class="input-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน" required>
        </div>

        <button type="submit" class="btn-submit">ผลักประตูเข้าไป</button>
    </form>

    <div class="footer-link">
        เพิ่งเคยแวะมาใช่ไหม? <a href="register.php">ลงนามสมัครตรงนี้เลย</a>
    </div>
</div>

</body>
</html>