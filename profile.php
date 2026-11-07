<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav.php';

require_login();

$id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT username, created_at FROM Users WHERE user_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($username, $joinedAt);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    http_response_code(404);
}

function stars(int $n): string
{
    $n = max(0, min(5, $n));
    return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
}

$done = $disputes = $reviewCount = 0;
$avg = null;
$reviews = $books = [];

if ($found) {
    // แลกสำเร็จ / มีข้อพิพาท (นับทั้งฝั่งผู้ขอและเจ้าของหนังสือ)
    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(swap_status = 'สำเร็จ'), 0),
            COALESCE(SUM(swap_status = 'มีข้อพิพาท'), 0)
         FROM Swaps WHERE requester_id = ? OR receiver_id = ?"
    );
    $stmt->bind_param("ii", $id, $id);
    $stmt->execute();
    $stmt->bind_result($done, $disputes);
    $stmt->fetch();
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*), AVG(rating) FROM User_Reviews
         WHERE reviewed_user_id = ? AND rating IS NOT NULL"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($reviewCount, $avg);
    $stmt->fetch();
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT r.rating, r.comment, r.created_at, u.username
         FROM User_Reviews r JOIN Users u ON u.user_id = r.reviewer_id
         WHERE r.reviewed_user_id = ?
         ORDER BY r.review_id DESC LIMIT 10"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT b.title, b.author, ub.book_condition
         FROM User_Books ub JOIN Books_Master b ON b.book_id = ub.book_id
         WHERE ub.user_id = ? AND ub.status = 'พร้อมแลก'
         ORDER BY ub.user_book_id DESC LIMIT 12"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$isNew = ((int)$done === 0 && (int)$reviewCount === 0);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ | BookSwap</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Thai:wght@600;700&family=Sarabun:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="theme.css">
</head>
<body>
<div class="dust" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
<?php render_nav($conn); ?>

<main class="container" style="max-width: 900px;">

<?php if (!$found): ?>
    <p class="empty">ไม่พบสมาชิกคนนี้ <a href="index.php" style="color:var(--candle)">กลับชั้นหนังสือ</a></p>
<?php else: ?>

    <section class="profile-head">
        <h1>
            @<?= e($username) ?>
            <?php if ($isNew): ?><span class="badge">สมาชิกใหม่</span><?php endif; ?>
        </h1>
        <p class="since">เข้าร่วมเมื่อ <?= e(date('d/m/Y', strtotime($joinedAt))) ?></p>

        <div class="stat-row">
            <div class="stat"><b><?= (int)$done ?></b><span>แลกสำเร็จ (ครั้ง)</span></div>
            <div class="stat">
                <?php if ((int)$reviewCount > 0): ?>
                    <b><?= e(number_format((float)$avg, 1)) ?> <span class="stars">★</span></b>
                    <span>จากรีวิว <?= (int)$reviewCount ?> ครั้ง</span>
                <?php else: ?>
                    <b>-</b><span>ยังไม่มีรีวิว</span>
                <?php endif; ?>
            </div>
            <div class="stat"><b><?= (int)$disputes ?></b><span>รายการที่มีข้อพิพาท</span></div>
        </div>
    </section>

    <h2 class="section-title"><i class="fa-solid fa-feather-pointed"></i> รีวิวจากคนที่เคยแลก</h2>
    <?php if (!$reviews): ?>
        <p class="empty">ยังไม่มีรีวิว รีวิวจะปรากฏหลังจากมีการแลกสำเร็จ</p>
    <?php else: ?>
        <?php foreach ($reviews as $rv): ?>
            <div class="review-item">
                <div><span class="stars"><?= e(stars((int)$rv['rating'])) ?></span></div>
                <div class="who">โดย @<?= e($rv['username']) ?> · <?= e(date('d/m/Y', strtotime($rv['created_at']))) ?></div>
                <?php if (!empty($rv['comment'])): ?><p><?= e($rv['comment']) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 class="section-title"><i class="fa-solid fa-book"></i> หนังสือที่วางอยู่บนชั้น</h2>
    <?php if (!$books): ?>
        <p class="empty">ตอนนี้ไม่มีหนังสือพร้อมแลก</p>
    <?php else: ?>
        <div class="bookshelf-grid">
            <?php foreach ($books as $b): ?>
                <article class="book-card">
                    <div>
                        <h3 class="book-title"><?= e($b['title']) ?></h3>
                        <p class="book-author">โดย <?= e($b['author']) ?></p>
                        <p class="book-meta"><strong>สภาพ:</strong> <?= e($b['book_condition']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
</main>
</body>
</html>
