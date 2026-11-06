
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

    if (
        $username === '' ||
        $email === '' ||
        $password === '' ||
        $confirm === ''
    ) {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';

    } elseif (!preg_match('/^[\p{L}\p{N}_]{3,50}$/u', $username)) {
        $error = 'Username ต้องมี 3-50 ตัวอักษร และใช้ตัวอักษร ตัวเลข หรือ _ เท่านั้น';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'รูปแบบ Email ไม่ถูกต้อง';

    } elseif (strlen($password) < 8) {
        $error = 'Password ต้องมีอย่างน้อย 8 ตัวอักษร';

    } elseif ($password !== $confirm) {
        $error = 'Password และ Confirm Password ไม่ตรงกัน';

    } else {
        try {
            // ตรวจสอบ Username และ Email ซ้ำ
            $check = $conn->prepare(
                "SELECT user_id FROM Users
                 WHERE username = ? OR email = ?
                 LIMIT 1"
            );

            $check->bind_param("ss", $username, $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error = 'Username หรือ Email นี้ถูกใช้งานแล้ว';
            }

            $check->close();

            if ($error === '') {
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // ใช้ Transaction เพื่อให้ข้อมูลสองตาราง
                // ถูกบันทึกพร้อมกันหรือยกเลิกพร้อมกัน
                $conn->begin_transaction();

                try {
                    // เพิ่มสมาชิกใหม่ พร้อมแต้มเริ่มต้น 20
                    $stmt = $conn->prepare(
                        "INSERT INTO Users
                        (username, email, password_hash, points)
                        VALUES (?, ?, ?, 20)"
                    );

                    $stmt->bind_param(
                        "sss",
                        $username,
                        $email,
                        $passwordHash
                    );

                    $stmt->execute();

                    $newUserId = $conn->insert_id;
                    $stmt->close();

                    // บันทึกประวัติการได้รับแต้ม
                    $log = $conn->prepare(
                        "INSERT INTO Point_Transactions
                        (user_id, amount, transaction_type)
                        VALUES (?, 20, 'สมัครสมาชิก')"
                    );

                    $log->bind_param("i", $newUserId);
                    $log->execute();
                    $log->close();

                    $conn->commit();

                    $success = 'สมัครสมาชิกสำเร็จ! คุณได้รับ 20 แต้ม';
                    $_SESSION['register_success'] = $success;

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
    max-width: 440px;
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
    background: #27ae60;
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    margin-top: 22px;
}

button:hover {
    background: #219653;
}

.error {
    background: #fdeaea;
    color: #c0392b;
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

a:hover {
    text-decoration: underline;
}

.points {
    text-align: center;
    background: #fff5df;
    color: #b9770e;
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 18px;
}
</style>
</head>

<body>
<div class="card">

    <div class="logo">📚</div>

    <h1>สมัครสมาชิก BookSwap</h1>

    <p class="subtitle">
        ร่วมเป็นส่วนหนึ่งของชุมชนแลกเปลี่ยนหนังสือ
    </p>

    <div class="points">
        🎁 สมัครวันนี้ รับฟรี 20 แต้ม
    </div>

    <?php if ($error): ?>
        <div class="error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="register.php">

        <input type="hidden"
               name="csrf_token"
               value="<?= e(csrf_token()) ?>">

        <label>Username</label>
        <input
            type="text"
            name="username"
            placeholder="ตั้งชื่อผู้ใช้งาน"
            value="<?= e($_POST['username'] ?? '') ?>"
            maxlength="50"
            required
        >

        <label>Email</label>
        <input
            type="email"
            name="email"
            placeholder="example@email.com"
            value="<?= e($_POST['email'] ?? '') ?>"
            maxlength="100"
            required
        >

        <label>Password</label>
        <input
            type="password"
            name="password"
            placeholder="อย่างน้อย 8 ตัวอักษร"
            minlength="8"
            required
        >

        <label>Confirm Password</label>
        <input
            type="password"
            name="confirm_password"
            placeholder="กรอกรหัสผ่านอีกครั้ง"
            minlength="8"
            required
        >

        <button type="submit">
            สมัครสมาชิก
        </button>

    </form>

    <div class="footer">
        มีบัญชีแล้ว?
        <a href="login.php">เข้าสู่ระบบ</a>
    </div>

</div>
</body>
</html>
