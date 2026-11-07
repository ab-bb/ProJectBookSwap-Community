<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rewards.php';

require_login();

$me = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT points FROM Users WHERE user_id = ?");
$stmt->bind_param("i", $me);
$stmt->execute();
$stmt->bind_result($myPoints);
$stmt->fetch();
$stmt->close();

// คำขอที่ฉันส่งไป (ฉันเป็นผู้ขอ)  /  คำขอที่ส่งมาหาฉัน (ฉันเป็นเจ้าของหนังสือ)
$baseSql = "
    SELECT s.swap_id, s.swap_status, s.tracking_number, s.created_at,
           b.title, b.author, b.book_value_points,
           u.user_id AS other_id, u.username AS other_name,
           r.review_id AS my_review_id
    FROM Swaps s
    JOIN User_Books ub ON ub.user_book_id = s.requested_book_id
    JOIN Books_Master b ON b.book_id = ub.book_id
    JOIN Users u ON u.user_id = %s
    LEFT JOIN User_Reviews r ON r.swap_id = s.swap_id AND r.reviewer_id = ?
    WHERE %s = ?
    ORDER BY s.swap_id DESC
";

$stmt = $conn->prepare(sprintf($baseSql, 's.receiver_id', 's.requester_id'));
$stmt->bind_param("ii", $me, $me);
$stmt->execute();
$sent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(sprintf($baseSql, 's.requester_id', 's.receiver_id'));
$stmt->bind_param("ii", $me, $me);
$stmt->execute();
$received = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- ตัวช่วยแสดงผล ----------
function status_info(string $status, string $role): array
{
    $owner = ($role === 'owner');
    switch ($status) {
        case 'รอดำเนินการ':
            return ['wait', $owner ? 'มีคนขอแลก รอคุณตอบรับ' : 'รอเจ้าของหนังสือตอบรับ'];
        case 'ยอมรับแล้ว':
            return ['go', $owner ? 'คุณยอมรับแล้ว กรุณาจัดส่งหนังสือ' : 'เจ้าของยอมรับแล้ว รอการจัดส่ง'];
        case 'ส่งของแล้ว':
            return ['go', $owner ? 'จัดส่งแล้ว รอผู้รับยืนยัน' : 'จัดส่งแล้ว เมื่อได้รับหนังสือให้กดยืนยัน'];
        case 'สำเร็จ':
            return ['done', 'แลกสำเร็จ'];
        case 'ปฏิเสธ':
            return ['bad', 'ปฏิเสธแล้ว คืนมัดจำเรียบร้อย'];
        case 'มีข้อพิพาท':
            return ['bad', 'มีข้อพิพาท รอผู้ดูแลระบบตรวจสอบ'];
        default:
            return ['wait', $status];
    }
}

function swap_form(int $id, string $action, string $label, string $class, string $confirmMsg = ''): void
{
    $onsubmit = $confirmMsg !== '' ? ' onsubmit="return confirm(\'' . e($confirmMsg) . '\')"' : '';
    echo '<form method="POST" action="swap_action.php"' . $onsubmit . '>';
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    echo '<input type="hidden" name="swap_id" value="' . $id . '">';
    echo '<input type="hidden" name="action" value="' . e($action) . '">';
    echo '<button type="submit" class="btn-sm ' . e($class) . '">' . e($label) . '</button>';
    echo '</form>';
}

function swap_card(array $r, string $role): void
{
    $id = (int)$r['swap_id'];
    [$cls, $label] = status_info($r['swap_status'], $role);
    $closed = in_array($r['swap_status'], ['สำเร็จ', 'ปฏิเสธ'], true);
    $otherLabel  = $role === 'owner' ? 'ผู้ขอแลก' : 'เจ้าของหนังสือ';
    ?>
    <article class="swap-card">
        <div class="swap-top">
            <div>
                <h3 class="book-title"><?= e($r['title']) ?></h3>
                <p class="book-author">โดย <?= e($r['author']) ?></p>
            </div>
            <span class="status <?= e($cls) ?>"><?= e($label) ?></span>
        </div>

        <p class="swap-meta">
            <?= e($otherLabel) ?>: <a class="user-link" href="profile.php?id=<?= (int)$r['other_id'] ?>">@<?= e($r['other_name']) ?></a><br>
            มัดจำ <?= e($r['book_value_points']) ?> แต้ม · ขอเมื่อ <?= e(date('d/m/Y', strtotime($r['created_at']))) ?>
            <?php if (!empty($r['tracking_number'])): ?>
                <br>เลขพัสดุ: <strong><?= e($r['tracking_number']) ?></strong>
            <?php endif; ?>
        </p>

        <div class="swap-actions">
            <?php if ($role === 'owner' && $r['swap_status'] === 'รอดำเนินการ'): ?>
                <?php swap_form($id, 'accept', 'ยอมรับคำขอ', 'btn-ok'); ?>
                <?php swap_form($id, 'reject', 'ปฏิเสธ', 'btn-no', 'ปฏิเสธคำขอนี้? มัดจำจะคืนให้ผู้ขอ และหนังสือกลับขึ้นชั้น'); ?>
            <?php elseif ($role === 'owner' && $r['swap_status'] === 'ยอมรับแล้ว'): ?>
                <form method="POST" action="swap_action.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="swap_id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="ship">
                    <input type="text" name="tracking_number" placeholder="เลขพัสดุ" maxlength="50" required>
                    <button type="submit" class="btn-sm btn-ok">บันทึกว่าส่งแล้ว</button>
                </form>
            <?php elseif ($role === 'requester' && $r['swap_status'] === 'ส่งของแล้ว'): ?>
                <?php swap_form($id, 'confirm', 'ได้รับหนังสือแล้ว', 'btn-ok', 'ยืนยันว่าได้รับหนังสือเรียบร้อยแล้ว?'); ?>
                <?php swap_form($id, 'dispute', 'มีปัญหา แจ้งข้อพิพาท', 'btn-no', 'แจ้งข้อพิพาท? มัดจำจะถูกล็อกไว้จนกว่าผู้ดูแลระบบตรวจสอบ'); ?>
            <?php endif; ?>
            <a class="btn-sm btn-ghost" href="chat.php?swap_id=<?= $id ?>"><i class="fa-regular fa-comments"></i> <?= $closed ? 'ดูประวัติแชท' : 'แชท' ?></a>
        </div>

        <?php if ($r['swap_status'] === 'สำเร็จ'): ?>
            <?php if (!empty($r['my_review_id'])): ?>
                <p class="swap-meta" style="margin-top:12px;">✓ คุณรีวิวรายการนี้แล้ว</p>
            <?php else: ?>
                <form class="review-form" method="POST" action="submit_review.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="swap_id" value="<?= $id ?>">
                    <label for="rating-<?= $id ?>" class="swap-meta">ให้คะแนน @<?= e($r['other_name']) ?></label>
                    <select id="rating-<?= $id ?>" name="rating" required>
                        <option value="">-- เลือกคะแนน --</option>
                        <option value="5">★★★★★ ประทับใจมาก</option>
                        <option value="4">★★★★☆ ดี</option>
                        <option value="3">★★★☆☆ พอใช้</option>
                        <option value="2">★★☆☆☆ ไม่ค่อยดี</option>
                        <option value="1">★☆☆☆☆ แย่</option>
                    </select>
                    <textarea name="comment" maxlength="500" placeholder="เล่าประสบการณ์การแลก (ไม่บังคับ)"></textarea>
                    <div><button type="submit" class="btn-sm btn-ok">ส่งรีวิว</button></div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คำขอของฉัน | BookSwap</title>
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
        <span class="nav-info-block">
            <span><i class="fa-solid fa-user"></i> @<?= e($_SESSION['username']) ?></span>
            <span class="points"><i class="fa-solid fa-coins"></i> <?= e($myPoints) ?> แต้ม</span>
        </span>
        <a href="index.php"><i class="fa-solid fa-book-open"></i> ชั้นหนังสือ</a>
        <a href="add_book.php" class="btn-add"><i class="fa-solid fa-plus"></i> ฝากหนังสือ</a>
        <form action="logout.php" method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="logout-btn">ออกจากร้าน</button>
        </form>
    </div>
</nav>

<main class="container">

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <h2 class="section-title"><i class="fa-solid fa-inbox"></i> คำขอที่ส่งมาหาคุณ</h2>
    <?php if (!$received): ?>
        <p class="empty">ยังไม่มีใครขอแลกหนังสือของคุณ</p>
    <?php else: ?>
        <div class="swap-list">
            <?php foreach ($received as $r) { swap_card($r, 'owner'); } ?>
        </div>
    <?php endif; ?>

    <h2 class="section-title"><i class="fa-solid fa-paper-plane"></i> หนังสือที่คุณขอแลก</h2>
    <?php if (!$sent): ?>
        <p class="empty">คุณยังไม่ได้ขอแลกหนังสือเล่มไหน <a href="index.php" style="color:var(--candle)">ไปเลือกจากชั้นหนังสือ</a></p>
    <?php else: ?>
        <div class="swap-list">
            <?php foreach ($sent as $r) { swap_card($r, 'requester'); } ?>
        </div>
    <?php endif; ?>

</main>
</body>
</html>