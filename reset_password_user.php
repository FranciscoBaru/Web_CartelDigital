<?php
// reset_password_user.php - Restablecer contraseña de usuario
require_once 'config.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die('Token inválido.');
}

// Buscar token en CLIENTES.password_resets
$sql = "SELECT u.id, u.Nombre, u.email 
        FROM Usuarios u 
        INNER JOIN password_resets pr ON u.email = pr.email 
        WHERE pr.token = ? AND pr.expires_at > NOW()";
$stmt = $conn_clientes->prepare($sql);
$stmt->bind_param("s", $token);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die('El enlace ha expirado o es inválido. Por favor, solicite uno nuevo.');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad.';
    } else {
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        if (empty($password) || $password !== $confirm) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn_clientes->prepare("UPDATE Usuarios SET Password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
            $update->bind_param("si", $hash, $user['id']);
            if ($update->execute()) {
                // Eliminar el token usado
                $del = $conn_clientes->prepare("DELETE FROM password_resets WHERE token = ?");
                $del->bind_param("s", $token);
                $del->execute();
                $del->close();
                $success = 'Su contraseña ha sido actualizada correctamente. Ya puede iniciar sesión.';
            } else {
                $error = 'Error al actualizar la contraseña.';
            }
            $update->close();
        }
    }
}
?>
<?php include 'header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-center">
                <h4><i class="bi bi-key"></i> Restablecer contraseña</h4>
                <p class="mb-0">Para: <?php echo htmlspecialchars($user['Nombre']); ?></p>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-primary">Iniciar sesión</a>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                        <div class="mb-3">
                            <label class="form-label">Nueva contraseña *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirmar nueva contraseña *</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Cambiar contraseña</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>