<?php
// logout.php - Cierre de sesión seguro
require_once 'config.php';

borrarCookieAutenticacion();

// Limpiar todas las variables de sesión
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

// Destruir la sesión
session_destroy();
// Redirigir al inicio
header('Location: index.php');
exit;
?>