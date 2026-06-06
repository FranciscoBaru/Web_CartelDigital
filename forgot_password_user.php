<?php
// forgot_password_user.php - Recuperación para usuarios (con rate limiting)
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad. Por favor, recargue la página.';
    } else {
        $email = trim($_POST['email']);
        if (empty($email)) {
            $error = 'Por favor, ingrese su email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido.';
        } else {
            // Buscar usuario en CLIENTES
            $sql = "SELECT id, Nombre FROM Usuarios WHERE email = ? AND email_verified = 1";
            $stmt = $conn_clientes->prepare($sql);
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user) {
                if (enviarEmailRecuperacionUsuario($email, $user['Nombre'])) {
                    $success = 'Se ha enviado un enlace de recuperación a su correo electrónico. Revise su bandeja (incluyendo spam).';
                } else {
                    $error = 'No se pudo enviar el correo. Por favor, intente más tarde.';
                }
            } else {
                // No revelamos si el email existe o no por seguridad
                $success = 'Si el email está registrado y verificado, recibirá un enlace de recuperación.';
            }
        }
    }
}
?>
<?php include 'header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header text-center">
                <h4><i class="bi bi-envelope-paper"></i> Recuperar contraseña</h4>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Email registrado *</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Enviar enlace</button>
                </form>
                <div class="text-center mt-3">
                    <a href="login.php">Volver al inicio de sesión</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>