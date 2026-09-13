<?php
/**
 * logout.php
 * ---------------------------------------------------------------------------
 * Session Terminator — Rajagiri College Grievance Redressal Portal
 *
 * Destroys the current session and redirects to the appropriate login page.
 * Optionally accepts ?role=xxx to redirect to a specific login portal.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. START / RESUME SESSION
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// 2. CAPTURE ROLE BEFORE CLEARING (for redirect decision)
// ---------------------------------------------------------------------------
$role = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : '';

if ($role === '' && !empty($_SESSION['role'])) {
    $role = strtolower((string) $_SESSION['role']);
}

// ---------------------------------------------------------------------------
// 3. CLEAR ALL SESSION DATA
// ---------------------------------------------------------------------------
$_SESSION = [];

// Destroy the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path']     ?? '/',
            'domain'   => $params['domain']   ?? '',
            'secure'   => $params['secure']   ?? false,
            'httponly' => $params['httponly'] ?? true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

// Destroy server-side session
session_unset();
session_destroy();

// ---------------------------------------------------------------------------
// 4. REDIRECT TO APPROPRIATE LOGIN PAGE
// ---------------------------------------------------------------------------
$allowedRoles = ['admin', 'student', 'parent', 'teacher', 'non_teaching', 'management'];

if (in_array($role, $allowedRoles, true)) {
    header('Location: login.php?role=' . $role);
} else {
    // Default to the main login page
    header('Location: login.php');
}
exit;