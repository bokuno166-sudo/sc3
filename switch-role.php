<?php
require_once 'config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestedRole = isset($_POST['role']) ? strtolower(trim($_POST['role'])) : '';
    $baseRole = getUserBaseRole();

    if (in_array($baseRole, ['doctor', 'admin'], true) && in_array($requestedRole, ['doctor', 'admin'], true)) {
        switchUserRole($requestedRole);
        setFlashMessage('success', 'Role switched to ' . ucfirst($requestedRole) . ' view.');
    } else {
        setFlashMessage('error', 'Role switch is not available for your account.');
    }
}

$redirectTo = 'dashboard.php';
if (!empty($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    $parsed = parse_url($referer);
    if (!empty($parsed['path'])) {
        $redirectTo = str_replace(BASE_URL, '', $referer);
        if ($redirectTo === $referer) {
            $redirectTo = 'dashboard.php';
        }
    }
}

redirect($redirectTo);
