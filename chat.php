<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav.php';

require_login();

$me     = (int)$_SESSION['user_id'];
$swapId = (int)($_GET['swap_id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT s.requester_id, s.receiver_id, s.swap_status, b.title,
            ou.user_id, ou.username
     FROM Swaps s
     JOIN User_Books ub ON ub.user_book_id = s.requested_book_id
     JOIN Books_Master b ON b.book_id = ub.book_id
     JOIN Users ou ON ou.user_id = IF(s.requester_id = ?, s.receiver_id, s.requester_id)
     WHERE s.swap_id = ? AND (s.requester_id = ? OR s.receiver_id = ?)"
);
$stmt->bind_param("iiii", $me, $swapId, $me, $me);
$stmt->execute();
$stmt->bind_result($requesterId, $receiverId, $status, $title, $otherId, $otherName);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    http_response_code(404);
}
$readonly = $found && in_array($status, ['สำเร็จ', 'ปฏิเสธ'], true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แชท | BookSwap</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Thai:wght@600;700&family=Sarabun:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="theme.css">
</head>
<body>
<div class="dust" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
<?php render_nav($conn); ?>

<main class="container" style="max-width: 760px;">
<?php if (!$found): ?>
    <p class="empty">ไม่พบห้องแชทนี้ <a href="my_swaps.php" style="color:var(--candle)">กลับไปคำขอของฉัน</a></p>
<?php else: ?>

    <div class="chat-head">
        <h1><i class="fa-regular fa-comments"></i> <?= e($title) ?></h1>
        <p>
            คุยกับ <a class="user-link" href="profile.php?id=<?= (int)$otherId ?>">@<?= e($otherName) ?></a>
            · สถานะ: <?= e($status) ?>
            · <a class="user-link" href="my_swaps.php">กลับไปคำขอของฉัน</a>
        </p>
    </div>

    <div class="chat-box" id="log" aria-live="polite">
        <div class="chat-empty" id="empty">ยังไม่มีข้อความ ทักทายหรือถามสภาพหนังสือได้เลย</div>
    </div>

    <?php if ($readonly): ?>
        <p class="chat-note">รายการนี้ปิดแล้ว อ่านประวัติการคุยได้อย่างเดียว</p>
    <?php else: ?>
        <form class="chat-form" id="form" autocomplete="off">
            <input type="text" id="msg" maxlength="500" placeholder="พิมพ์ข้อความ (สูงสุด 500 ตัวอักษร)" required>
            <button type="submit" class="btn-sm btn-ok" id="send">ส่ง</button>
        </form>
        <div class="chat-err" id="err"></div>
        <p class="chat-note">โปรดไม่แชร์รหัสผ่านหรือข้อมูลบัตรในแชท ข้อความอาจถูกใช้ตรวจสอบหากมีข้อพิพาท</p>
    <?php endif; ?>

<script>
(function () {
    const swapId = <?= (int)$swapId ?>;
    const csrf   = <?= json_encode(csrf_token()) ?>;
    const log    = document.getElementById('log');
    const empty  = document.getElementById('empty');
    const form   = document.getElementById('form');
    const input  = document.getElementById('msg');
    const btn    = document.getElementById('send');
    const err    = document.getElementById('err');
    let lastId = 0;

    function addMsg(m) {
        if (empty) empty.style.display = 'none';
        const wrap = document.createElement('div');
        wrap.className = 'msg ' + (m.mine ? 'mine' : 'theirs');
        const who = document.createElement('div');
        who.className = 'msg-who';
        who.textContent = (m.mine ? 'คุณ' : '@' + m.sender) + ' · ' + m.time;
        const body = document.createElement('div');
        body.className = 'msg-body';
        body.textContent = m.body;          // textContent กัน XSS
        wrap.append(who, body);
        log.appendChild(wrap);
    }

    async function poll(forceScroll) {
        try {
            const r = await fetch('chat_api.php?swap_id=' + swapId + '&after=' + lastId, { credentials: 'same-origin' });
            if (!r.ok) return;
            const d = await r.json();
            if (d.messages && d.messages.length) {
                const nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
                const first = lastId === 0;
                d.messages.forEach(function (m) { addMsg(m); lastId = Math.max(lastId, m.id); });
                if (forceScroll || first || nearBottom) log.scrollTop = log.scrollHeight;
            }
        } catch (e) { /* ลองใหม่รอบถัดไป */ }
    }

    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const text = input.value.trim();
            if (!text) return;
            btn.disabled = true;
            err.textContent = '';
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('swap_id', swapId);
                fd.append('body', text);
                const r = await fetch('chat_api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const d = await r.json();
                if (d.ok) { input.value = ''; await poll(true); }
                else { err.textContent = d.error || 'ส่งไม่สำเร็จ'; }
            } catch (ex) {
                err.textContent = 'ส่งไม่สำเร็จ กรุณาลองใหม่';
            }
            btn.disabled = false;
            input.focus();
        });
    }

    poll(true);
    setInterval(function () { if (!document.hidden) poll(false); }, 4000);
})();
</script>

<?php endif; ?>
</main>
</body>
</html>
