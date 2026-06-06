<?php
// reset_password.php - Restablecimiento de contraseña para estaciones
require_once 'config.php';

$token = $_GET['token'] ?? '';
if (empty($token)) die('Token inválido.');

$sql = "SELECT s.id, s.site, s.nombre, s.email 
        FROM sites s 
        INNER JOIN password_resets_sites pr ON s.site = pr.site AND s.email = pr.email 
        WHERE pr.token = ? AND pr.expires_at > NOW()";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $token);
$stmt->execute();
$site = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$site) die('El enlace ha expirado o es inválido.');

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
            // Actualizar en CARTELES.sites
            $update = $conn->prepare("UPDATE sites SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
            $update->bind_param("si", $hash, $site['id']);
            if ($update->execute()) {
                // Eliminar token usado
                $del = $conn->prepare("DELETE FROM password_resets_sites WHERE token = ?");
                $del->bind_param("s", $token);
                $del->execute();
                $del->close();
                $success = 'Contraseña actualizada correctamente.';
            } else {
                $error = 'Error al actualizar.';
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
                <p class="mb-0">Estación: <?php echo htmlspecialchars($site['nombre']); ?></p>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <div class="text-center mt-3"><a href="login.php" class="btn btn-primary">Iniciar sesión</a></div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                        <div class="mb-3"><label>Nueva contraseña *</label><input type="password" name="password" class="form-control" required></div>
                        <div class="mb-3"><label>Confirmar *</label><input type="password" name="confirm_password" class="form-control" required></div>
                        <button type="submit" class="btn btn-primary w-100">Cambiar contraseña</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>