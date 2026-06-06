<?php
// forgot_password.php - Recuperacion para estaciones
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad. Por favor, recargue la pagina.';
    } else {
        $site = intval($_POST['site'] ?? 0);
        $email = trim($_POST['email']);
        if ($site <= 0 || empty($email)) {
            $error = 'Por favor, ingrese el APIES de la estacion y su email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email invalido.';
        } else {
            // Buscar estacion en CARTELES
            $sql = "SELECT site, nombre, email FROM sites WHERE site = ? AND email = ? AND nombre != 'N/A'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $site, $email);
            $stmt->execute();
            $station = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($station) {
                if (enviarEmailRecuperacionEstacion($station['site'], $email, $station['nombre'])) {
                    $success = 'Se ha enviado un enlace de recuperacion a su correo electronico.';
                } else {
                    $detalle_error = obtenerUltimoErrorCorreo();
                    $error = 'No se pudo enviar el correo. ' . ($detalle_error ?: 'Revise la configuracion SMTP/PHPMailer en php_errors.log.');
                }
            } else {
                $success = 'Si el APIES y el email coinciden con una estacion registrada, recibira un enlace de recuperacion.';
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
                <h4><i class="bi bi-envelope-paper"></i> Recuperar contrasena (Estacion)</h4>
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
                        <label class="form-label">APIES de la estacion *</label>
                        <input type="number" name="site" class="form-control" required value="<?php echo htmlspecialchars($_POST['site'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email registrado *</label>
                        <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Enviar enlace</button>
                </form>
                <div class="text-center mt-3">
                    <a href="login.php">Volver al inicio de sesion</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>