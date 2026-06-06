<?php
// get_users.php - Devuelve lista de usuarios para el panel de soporte
require_once 'config.php';
if (!esUsuario() || $_SESSION['user_rol'] != 9) {
    http_response_code(403);
    exit('Acceso denegado');
}
header('Content-Type: application/json');
$result = $conn_clientes->query("SELECT id, Nombre, Usuario, Email, DNI, rol, Petrolera, email_verified FROM Usuarios ORDER BY id");
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en consulta']);
    exit;
}
echo json_encode($result->fetch_all(MYSQLI_ASSOC));
?>