<?php
// verify_email.php - Verificación de email para registro de cartel
require_once 'config.php';

$token = $_GET['token'] ?? '';
$mac = $_GET['mac'] ?? '';

if (empty($token) || empty($mac)) die('Enlace inválido.');

$sql = "SELECT id, email FROM pendientes_registro WHERE token = ? AND MAC = ?";
$stmt = $conn_clientes->prepare($sql);
$stmt->bind_param("ss", $token, $mac);
$stmt->execute();
$pendiente = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pendiente) die('Token inválido o expirado.');

header("Location: complete_site_registration.php?mac=" . urlencode($mac));
exit;
?>