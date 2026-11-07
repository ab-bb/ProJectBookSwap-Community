
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
        exit('Invalid CSRF token. Please refresh and try again.');
    }
}

// ตรวจสอบ Login
function is_logged_in(): bool
{
    return isset($_SESSION['user_id'])
        && (int)$_SESSION['user_id'] > 0;
}

// บังคับให้ Login
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
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

// ดึง User ID
function current_user_id(): ?int
{
    return is_logged_in()
        ? (int)$_SESSION['user_id']
        : null;
}