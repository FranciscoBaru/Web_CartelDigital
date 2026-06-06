<?php
// verify_user.php - Verificación de email
require_once 'config.php';

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

if (empty($token) || empty($email)) {
    die('Enlace inválido.');
}

$sql = "SELECT id, email_verified FROM Usuarios WHERE verification_token = ? AND email = ?";
$stmt = $conn_clientes->prepare($sql);
$stmt->bind_param("ss", $token, $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($user) {
    if ($user['email_verified']) {
        header('Location: login.php?msg=email_ya_verificado');
        exit;
    }
    $update = $conn_clientes->prepare("UPDATE Usuarios SET email_verified = 1, verification_token = NULL WHERE id = ?");
    $update->bind_param("i", $user['id']);
    if ($update->execute()) {
        header('Location: login.php?msg=verificado_ok');
        exit;
    } else {
        die('Error al verificar. Contacte al administrador.');
    }
} else {
    die('Token inválido o expirado.');
}
?>