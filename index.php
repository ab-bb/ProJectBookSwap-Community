<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';

$currentUserPoints = 0;

if (is_logged_in()) {
    $stmtUser = $conn->prepare(
        "SELECT points FROM Users WHERE user_id = ?"
    );

    $stmtUser->bind_param("i", $_SESSION['user_id']);
    $stmtUser->execute();
    $stmtUser->bind_result($currentUserPoints);
    $stmtUser->fetch();
    $stmtUser->close();
}

$sql = "
    SELECT
        b.book_id,
        b.title,
        b.author,
        b.category,
        b.book_value_points,
        ub.user_book_id,
        ub.book_condition,
        ub.photo_url,
        ub.status,
        u.username
    FROM Books_Master b
    LEFT JOIN User_Books ub
        ON b.book_id = ub.book_id
        AND ub.status = 'พร้อมแลก'
    LEFT JOIN Users u
        ON ub.user_id = u.user_id
    ORDER BY b.book_id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}

$stmt->execute();
$result = $stmt->get_result();
$books = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BookSwap - แพลตฟอร์มแลกหนังสือด้วยแต้มมัดจำ</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f6f9; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1100px; margin: auto; }
        h1 { color: #2c3e50; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #e1e8ed; transition: transform 0.2s; }
        .card:hover { transform: translateY(-3px); }
        .btn-swap { background: #27ae60; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none; display: inline-block; margin-top: 15px; font-weight: bold; }
        .btn-swap:hover { background: #219653; }
        .badge { background: #3498db; color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .points-tag { font-size: 16px; color: #e67e22; font-weight: bold; margin: 10px 0; }
    </style>
</head>
<body>
<div class="container">
    <div style="text-align:right; margin-bottom:20px;">

    <?php if (is_logged_in()): ?>
        สวัสดี @<?= e($_SESSION['username']) ?>
        <p>💰 แต้มของคุณ: <?= e($currentUserPoints) ?> แต้ม</p>
        <a href="add_book.php"
            style="background:#27ae60;color:white;
            padding:10px 15px;border-radius:6px;
            text-decoration:none;display:inline-block;">
            + ลงทะเบียนหนังสือ
        </a>

        <form action="logout.php" method="POST"
              style="display:inline;">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(csrf_token()) ?>">

            <button type="submit"
                    style="background:#e74c3c; color:white;
                    border:0; padding:8px 14px;
                    border-radius:6px; cursor:pointer;">
                ออกจากระบบ
            </button>
        </form>

    <?php else: ?>

        <a href="login.php">เข้าสู่ระบบ</a>
        |
        <a href="register.php">สมัครสมาชิก</a>

    <?php endif; ?>

</div>
    <?php if (isset($_GET['added']) && $_GET['added'] === '1'): ?>

        <div style="
            background:#e8f8ef;
            color:#218c52;
            padding:15px;
            border-radius:8px;
            margin-bottom:20px;
        ">
        ✓ ลงทะเบียนหนังสือสำเร็จแล้ว!
    </div>

<?php endif; ?>
    <h1>📚 BookSwap Community</h1>
    <p>ยินดีต้อนรับสู่ระบบแลกเปลี่ยนหนังสือสำหรับชาวหอสมุด ปลอดภัยด้วยแต้มมัดจำผ่านระบบกลาง</p>
    <hr style="border: 0; height: 1px; background: #dcdde1;">

    <h2>📖 รายการหนังสือที่พร้อมแลกเปลี่ยนในระบบ</h2>

    <?php if (count($books) === 0): ?>
        <p style="color: #7f8c8d;">ขณะนี้ยังไม่มีหนังสือพร้อมแลกในระบบ</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($books as $book): ?>
                <div class="card">
                    <?php if (!empty($book['photo_url'])): ?>

                        <img
                            src="<?= e($book['photo_url']) ?>"
                            alt="<?= e($book['title']) ?>"
                            style="
                            width:100%;
                            height:220px;
                            object-fit:cover;
                            border-radius:8px;
                            margin-bottom:12px;
                            "
                        >
                    <?php endif; ?>
                    <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                    <p><strong>ผู้เขียน:</strong> <?php echo htmlspecialchars($book['author']); ?></p>
                    <p><strong>หมวดหมู่:</strong> <span class="badge"><?php echo htmlspecialchars($book['category']); ?></span></p>
                    <p><strong>สภาพหนังสือ:</strong><?php echo !empty($book['book_condition'])? htmlspecialchars($book['book_condition']): 'ยังไม่มีข้อมูล';?></p>
                    <div class="points-tag">มัดจำ: 💰 <?php echo htmlspecialchars($book['book_value_points']); ?> แต้ม</div>
                    <p>ผู้โพสต์:<?php echo !empty($book['username'])? '@' . htmlspecialchars($book['username']): 'ยังไม่มีผู้โพสต์';?></p>
                    <?php if (!empty($book['user_book_id'])): ?><a href="request_swap.php?id=<?php echo urlencode($book['user_book_id']); ?>"class="btn-swap"onclick="return confirm('คุณต้องการขอแลกหนังสือเล่มนี้ใช่หรือไม่?')">ขอแลกเล่มนี้</a><?php else: ?><p style="color:#999;">หนังสือเล่มนี้ยังไม่พร้อมแลก</p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>