
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

            $stmt->bind_result(
                $userId,
                $username,
                $email,
                $passwordHash
            );

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
<title>เข้าสู่ระบบ | BookSwap</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, sans-serif;
    background: #f2f5f9;
    color: #2c3e50;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

.card {
    background: white;
    width: 100%;
    max-width: 420px;
    padding: 35px;
    border-radius: 16px;
    box-shadow: 0 8px 30px rgba(0,0,0,.08);
}

.logo {
    text-align: center;
    font-size: 34px;
    margin-bottom: 8px;
}

h1 {
    text-align: center;
    font-size: 25px;
    margin: 0 0 8px;
}

.subtitle {
    text-align: center;
    color: #7f8c8d;
    margin-bottom: 25px;
}

label {
    display: block;
    font-weight: 600;
    margin: 15px 0 7px;
}

input {
    width: 100%;
    padding: 13px;
    border: 1px solid #dce2e8;
    border-radius: 8px;
    font-size: 15px;
}

input:focus {
    outline: none;
    border-color: #3498db;
}

button {
    width: 100%;
    padding: 14px;
    border: 0;
    border-radius: 8px;
    background: #2980b9;
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    margin-top: 22px;
}

button:hover {
    background: #21618c;
}

.error {
    background: #fdeaea;
    color: #c0392b;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
}

.success {
    background: #e8f8ef;
    color: #218c52;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
}

.footer {
    text-align: center;
    margin-top: 22px;
}

a {
    color: #2980b9;
    text-decoration: none;
}
</style>
</head>

<body>
<div class="card">

    <div class="logo">📚</div>

    <h1>เข้าสู่ระบบ BookSwap</h1>

    <p class="subtitle">
        ยินดีต้อนรับกลับสู่ชุมชนแลกเปลี่ยนหนังสือ
    </p>

    <?php if ($success): ?>
        <div class="success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">

        <input type="hidden"
               name="csrf_token"
               value="<?= e(csrf_token()) ?>">

        <label>Username หรือ Email</label>

        <input
            type="text"
            name="identity"
            placeholder="กรอก Username หรือ Email"
            value="<?= e($_POST['identity'] ?? '') ?>"
            required
        >

        <label>Password</label>

        <input
            type="password"
            name="password"
            placeholder="กรอกรหัสผ่าน"
            required
        >

        <button type="submit">
            เข้าสู่ระบบ
        </button>

    </form>

    <div class="footer">
        ยังไม่มีบัญชี?
        <a href="register.php">สมัครสมาชิก</a>
    </div>

</div>
</body>
</html>
