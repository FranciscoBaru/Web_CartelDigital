<?php
// register_cartel.php - Solicitud de registro de cartel (solo MAC y email)
require_once 'config.php';

if (estaLogueado()) redirigir('index.php');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad. Por favor, recargue la página y vuelva a intentar.';
    } else {
        $mac = strtoupper(trim($_POST['mac']));
        $email = trim($_POST['email']);

        if (!preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $mac)) {
            $error = 'Formato de MAC inválido. Ejemplo: 44:1D:64:CC:DC:AC';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido.';
        } else {
            $check_cartel = $conn->prepare("SELECT id FROM Cartel WHERE MAC = ?");
            $check_cartel->bind_param("s", $mac);
            $check_cartel->execute();
            $cartel_exists = $check_cartel->get_result()->num_rows > 0;
            $check_cartel->close();

            if (!$cartel_exists) {
                $error = 'La MAC no está registrada en el sistema. Asegúrese de que el cartel haya enviado datos.';
            } else {
                $del_stmt = $conn_clientes->prepare("DELETE FROM pendientes_registro WHERE MAC = ?");
                $del_stmt->bind_param("s", $mac);
                $del_stmt->execute();
                $del_stmt->close();

                $token = generarTokenVerificacion();
                $fecha = date('Y-m-d H:i:s');
                $expira = date('Y-m-d H:i:s', strtotime('+24 hours'));
                $stmt = $conn_clientes->prepare("INSERT INTO pendientes_registro (MAC, email, token, fecha_solicitud, expira) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $mac, $email, $token, $fecha, $expira);
                if ($stmt->execute()) {
                    if (enviarEmailVerificacionRegistroCartel($email, $token, $mac)) {
                        $success = 'Se ha enviado un enlace de verificación a su correo. Revise su bandeja de entrada (incluyendo spam).';
                    } else {
                        $error = 'Error al enviar el correo. Por favor, intente más tarde.';
                        $del2 = $conn_clientes->prepare("DELETE FROM pendientes_registro WHERE MAC = ?");
                        $del2->bind_param("s", $mac);
                        $del2->execute();
                        $del2->close();
                    }
                } else {
                    $error = 'Error al registrar la solicitud.';
                }
                $stmt->close();
            }
        }
    }
}
?>
<?php include 'header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card">
            <div class="card-header text-center"><h4><i class="bi bi-tv"></i> Registrar Cartel</h4></div>
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                    <div class="mb-3"><label class="form-label">Dirección MAC *</label><input type="text" name="mac" class="form-control" placeholder="44:1D:64:CC:DC:AC" required value=""></div>
                    <div class="mb-3"><label class="form-label">Correo electrónico *</label><input type="email" name="email" class="form-control" required value=""></div>
                    <button type="submit" class="btn btn-primary w-100">Solicitar registro</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>