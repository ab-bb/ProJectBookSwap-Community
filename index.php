<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rewards.php';

date_default_timezone_set('Asia/Bangkok');

$currentUserPoints = 0;
$lastCheckin = null;
$streak = 0;
$pendingActions = 0;

if (is_logged_in()) {
    $stmtUser = $conn->prepare(
        "SELECT points, last_checkin, checkin_streak FROM Users WHERE user_id = ?"
    );
    $stmtUser->bind_param("i", $_SESSION['user_id']);
    $stmtUser->execute();
    $stmtUser->bind_result($currentUserPoints, $lastCheckin, $streak);
    $stmtUser->fetch();
    $stmtUser->close();

    // จำนวนรายการแลกที่รอให้ฉันทำอะไรสักอย่าง
    $uid = (int)$_SESSION['user_id'];
    $stmtN = $conn->prepare(
        "SELECT COUNT(*) FROM Swaps
         WHERE (receiver_id = ? AND swap_status IN ('รอดำเนินการ','ยอมรับแล้ว'))
            OR (requester_id = ? AND swap_status = 'ส่งของแล้ว')"
    );
    $stmtN->bind_param("ii", $uid, $uid);
    $stmtN->execute();
    $stmtN->bind_result($pendingActions);
    $stmtN->fetch();
    $stmtN->close();
}

// สถานะการเช็คอิน
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$checkedToday = ($lastCheckin === $today);
// ถ้าขาดไปเกิน 1 วัน สตรีคที่แสดงจะเริ่มใหม่
if ($lastCheckin !== $today && $lastCheckin !== $yesterday) {
    $streak = 0;
}
$stampsDone = $streak % 7;
if ($streak > 0 && $stampsDone === 0) {
    $stampsDone = 7; // ครบรอบพอดี แสดงตราครบทั้ง 7
}

$sql = "
    SELECT
        b.book_id, b.title, b.author, b.category, b.book_value_points,
        ub.user_book_id, ub.book_condition, ub.photo_url, ub.status,
        u.user_id AS owner_id,
        u.username,
        (SELECT ROUND(AVG(rating), 1) FROM User_Reviews
          WHERE reviewed_user_id = u.user_id AND rating IS NOT NULL) AS owner_rating,
        (SELECT COUNT(*) FROM User_Reviews
          WHERE reviewed_user_id = u.user_id AND rating IS NOT NULL) AS owner_reviews
    FROM Books_Master b
    LEFT JOIN User_Books ub
        ON b.book_id = ub.book_id
        AND ub.status = 'พร้อมแลก'
    LEFT JOIN Users u
        ON ub.user_id = u.user_id
    ORDER BY b.book_id DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute();
$books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BookSwap - ห้องสมุดลับ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Thai:wght@600;700&family=Sarabun:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="theme.css">
</head>
<body>

<div class="dust" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>

<nav class="navbar">
    <a href="index.php" class="navbar-brand"><i class="fa-solid fa-book-skull"></i> BookSwap</a>
    <div class="nav-links">
        <?php if (is_logged_in()): ?>
            <span class="nav-info-block">
                <a href="profile.php?id=<?= (int)$_SESSION['user_id'] ?>"><i class="fa-solid fa-user"></i> @<?= e($_SESSION['username']) ?></a>
                <span class="points"><i class="fa-solid fa-coins"></i> <?= e($currentUserPoints) ?> แต้ม</span>
            </span>
            <a href="my_swaps.php"><i class="fa-solid fa-envelope-open-text"></i> คำขอของฉัน<?php if ($pendingActions > 0): ?><span class="nav-badge"><?= (int)$pendingActions ?></span><?php endif; ?></a>
            <a href="add_book.php" class="btn-add"><i class="fa-solid fa-plus"></i> ฝากหนังสือ</a>
            <form action="logout.php" method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="logout-btn">ออกจากร้าน</button>
            </form>
        <?php else: ?>
            <a href="login.php"><i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ร้าน</a>
            <a href="register.php"><i class="fa-solid fa-user-plus"></i> สมัครสมาชิก</a>
        <?php endif; ?>
    </div>
</nav>

<header class="hero-banner">
    <div class="key"><i class="fa-solid fa-key"></i></div>
    <h1>ห้องสมุดลับใต้แสงเทียน</h1>
    <p>ผลักชั้นหนังสือบานนั้นเข้าไป แล้วคุณจะพบเรื่องราวที่ใครบางคนซ่อนไว้ ฝากเล่มของคุณ หยิบเล่มของเขา แล้วเก็บแต้มระหว่างทาง</p>
</header>

<main class="container">

    <?php if (isset($_GET['added']) && $_GET['added'] === '1'): ?>
        <div class="alert alert-success">✓ วางหนังสือลงบนชั้นลับเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if (is_logged_in()): ?>
    <section class="stamp-card" aria-labelledby="stamp-title">
        <div class="stamp-info">
            <h3 id="stamp-title"><i class="fa-solid fa-stamp"></i> ตราประทับประจำวัน</h3>
            <p>แวะมาประทับตราทุกวัน รับ <?= REWARD_CHECKIN_DAILY ?> แต้ม และรับโบนัสอีก <?= REWARD_CHECKIN_STREAK_BONUS ?> แต้มเมื่อครบ 7 วันติดกัน<br>
               ตอนนี้คุณประทับต่อเนื่อง <strong><?= e($streak) ?></strong> วัน</p>
            <div class="stamp-row" aria-label="ความคืบหน้า 7 วัน">
                <?php for ($i = 1; $i <= 7; $i++): ?>
                    <span class="stamp <?= $i === 7 ? 'bonus' : '' ?> <?= $i <= $stampsDone ? 'done' : '' ?>">
                        <i class="fa-solid <?= $i === 7 ? 'fa-gift' : 'fa-feather-pointed' ?>"></i>
                    </span>
                <?php endfor; ?>
            </div>
        </div>
        <form action="checkin.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="btn-checkin" <?= $checkedToday ? 'disabled' : '' ?>>
                <?= $checkedToday ? 'ประทับตราวันนี้แล้ว' : 'ประทับตราวันนี้' ?>
            </button>
        </form>
    </section>
    <?php endif; ?>

    <h2 class="section-title"><i class="fa-solid fa-book-open"></i> หนังสือบนชั้นลับ พร้อมให้คุณหยิบไปอ่าน</h2>

    <?php if (count($books) === 0): ?>
        <p class="empty">ชั้นยังว่างอยู่ ฝากหนังสือเล่มแรกเพื่อเริ่มต้นห้องสมุดนี้</p>
    <?php else: ?>
        <div class="bookshelf-grid">
            <?php foreach ($books as $book): ?>
                <?php $available = !empty($book['user_book_id']); ?>
                <article class="book-card <?= $available ? '' : 'taken' ?>">
                    <div>
                        <?php if (!empty($book['photo_url'])): ?>
                            <img src="<?= e($book['photo_url']) ?>" alt="<?= e($book['title']) ?>"
                                 style="width:100%; height:200px; object-fit:cover; border-radius:6px; margin-bottom:12px; border:1px solid #4a3024;">
                        <?php endif; ?>
                        <h3 class="book-title"><?= e($book['title']) ?></h3>
                        <p class="book-author">โดย <?= e($book['author']) ?></p>
                        <span class="badge"><i class="fa-solid fa-bookmark"></i> <?= e($book['category']) ?></span>
                        <p class="book-meta"><strong>สภาพ:</strong> <?= !empty($book['book_condition']) ? e($book['book_condition']) : 'ยังไม่มีข้อมูล' ?></p>
                        <div class="points-tag"><i class="fa-solid fa-coins"></i> มัดจำ <?= e($book['book_value_points']) ?> แต้ม</div>
                    </div>
                    <div class="book-footer">
                        <p class="book-owner">ผู้ฝาก:
                            <?php if (!empty($book['username'])): ?>
                                <a class="user-link" href="profile.php?id=<?= (int)$book['owner_id'] ?>">@<?= e($book['username']) ?></a>
                                <?php if ((int)$book['owner_reviews'] > 0): ?>
                                    <span class="rating-mini">★ <?= e($book['owner_rating']) ?> (<?= (int)$book['owner_reviews'] ?>)</span>
                                <?php else: ?>
                                    <span class="rating-mini muted">ยังไม่มีรีวิว</span>
                                <?php endif; ?>
                            <?php else: ?>ยังไม่มีผู้ฝาก<?php endif; ?>
                        </p>
                        <?php if ($available): ?>
                            <form action="request_swap.php" method="POST" onsubmit="return confirm('คุณต้องการขอแลกหนังสือเล่มนี้ใช่หรือไม่?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="user_book_id" value="<?= (int)$book['user_book_id'] ?>">
                                <button type="submit" class="btn-swap">หยิบหนังสือเล่มนี้</button>
                            </form>
                        <?php else: ?>
                            <p class="book-gone">มีคนหยิบเล่มนี้ไปแล้ว</p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

</body>
</html>