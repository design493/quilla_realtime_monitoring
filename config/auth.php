<?php
// ============================================================
// config/auth.php  — Session / auth helpers
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /quilla_production/login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: /quilla_production/index.php');
        exit;
    }
}

function currentUser(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? 0,
        'username'  => $_SESSION['username']  ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']      ?? 'viewer',
    ];
}

function logout(): void {
    session_destroy();
    header('Location: /quilla_production/login.php');
    exit;
}
