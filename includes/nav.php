<?php
// แถบเมนูสำหรับหน้าที่ล็อกอินแล้ว (ใช้กับ profile.php, chat.php)
function render_nav(mysqli $conn): void
{
    $me = (int)$_SESSION['user_id'];
    $s = $conn->prepare("SELECT points FROM Users WHERE user_id = ?");
    $s->bind_param("i", $me);
    $s->execute();
    $s->bind_result($pts);
    $s->fetch();
    $s->close();
    ?>
<nav class="navbar">
    <a href="index.php" class="navbar-brand"><i class="fa-solid fa-book-skull"></i> BookSwap</a>
    <div class="nav-links">
        <span class="nav-info-block">
            <a href="profile.php?id=<?= $me ?>"><i class="fa-solid fa-user"></i> @<?= e($_SESSION['username']) ?></a>
            <span class="points"><i class="fa-solid fa-coins"></i> <?= e($pts) ?> แต้ม</span>
        </span>
        <a href="index.php"><i class="fa-solid fa-book-open"></i> ชั้นหนังสือ</a>
        <a href="my_swaps.php"><i class="fa-solid fa-envelope-open-text"></i> คำขอของฉัน</a>
        <a href="add_book.php" class="btn-add"><i class="fa-solid fa-plus"></i> ฝากหนังสือ</a>
        <form action="logout.php" method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="logout-btn">ออกจากร้าน</button>
        </form>
    </div>
</nav>
<?php
}
