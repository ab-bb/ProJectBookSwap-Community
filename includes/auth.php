
<?php

require_once __DIR__ . '/../config/db.php';

// สร้าง CSRF Token
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// ตรวจสอบ CSRF Token
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
    }
}

// ตรวจสอบสถานะ Login
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// ตรวจสอบว่า Login อยู่หรือไม่
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

// ป้องกัน XSS
function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}
