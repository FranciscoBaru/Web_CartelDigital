<?php
// perfil_estacion.php - Perfil de la estación (edición + cambio de contraseña)
require_once 'config.php';

if (!esEstacion()) {
    redirigir('login.php');
}

$site_id = $_SESSION['site_id'];
$site_data = obtenerEstacionPorId($site_id);

$sql_carteles = "SELECT id, MAC, IP_LAN, estado485, estadovox, fecha, hora FROM Cartel WHERE site = ? ORDER BY id DESC";
$stmt = $conn->prepare($sql_carteles);
$stmt->bind_param("i", $site_data['site']);
$stmt->execute();
$carteles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$error = '';
$success = '';
$error_pass = '';
$success_pass = '';

// Procesar actualización de datos de perfil
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_perfil'])) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad.';
    } else {
        $nombre = trim($_POST['nombre']);
        $domicilio = trim($_POST['domicilio']);
        $localidad = trim($_POST['localidad']);
        $provincia = trim($_POST['provincia']);
        $telefono = trim($_POST['telefono']);
        $email = trim($_POST['email']);
        $cuit = trim($_POST['cuit']);

        if (empty($nombre)) {
            $error = 'El nombre es obligatorio.';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido.';
        } else {
            $sql_upd = "UPDATE sites SET nombre = ?, domicilio = ?, localidad = ?, provincia = ?, telefono = ?, email = ?, cuit = ?, updated_at = NOW() WHERE id = ?";
            $stmt_upd = $conn->prepare($sql_upd);
            $stmt_upd->bind_param("sssssssi", $nombre, $domicilio, $localidad, $provincia, $telefono, $email, $cuit, $site_id);
            if ($stmt_upd->execute()) {
                $success = 'Perfil actualizado correctamente.';
                $_SESSION['site_nombre'] = $nombre;
                $site_data = obtenerEstacionPorId($site_id);
            } else {
                $error = 'Error al actualizar: ' . $stmt_upd->error;
            }
            $stmt_upd->close();
        }
    }
}

// Procesar cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cambiar_password'])) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error_pass = 'Error de seguridad.';
    } else {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_pass = 'Todos los campos de contraseña son obligatorios.';
        } elseif ($new_password !== $confirm_password) {
            $error_pass = 'La nueva contraseña y su confirmación no coinciden.';
        } elseif (strlen($new_password) < 6) {
            $error_pass = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } else {
            // Verificar contraseña actual
            $sql_pass = "SELECT password FROM sites WHERE id = ?";
            $stmt = $conn->prepare($sql_pass);
            $stmt->bind_param("i", $site_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (password_verify($current_password, $row['password'])) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                // Actualizar en CARTELES.sites
                $update = $conn->prepare("UPDATE sites SET password = ? WHERE id = ?");
                $update->bind_param("si", $new_hash, $site_id);
                if ($update->execute()) {
                    $success_pass = 'Contraseña actualizada correctamente.';
                } else {
                    $error_pass = 'Error al actualizar la contraseña: ' . $update->error;
                }
                $update->close();
            } else {
                $error_pass = 'La contraseña actual es incorrecta.';
            }
        }
    }
}

$petroleras = obtenerPetroleras();
$provincias = obtenerProvincias();
$petrolera_nombre = '';
foreach ($petroleras as $p) {
    if ($p['id'] == $site_data['petrolera_id']) {
        $petrolera_nombre = $p['nombre'];
        break;
    }
}
$estados_wft = obtenerEstadosWFTMultiples(array_column($carteles, 'MAC'));
?>
<?php include 'header.php'; ?>
<style>
    .info-card { background: #1e1e1e; border: 1px solid #ffc107; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 20px; }
    .info-icon { font-size: 2rem; color: #ffc107; margin-bottom: 10px; }
    .info-value { font-size: 1.1rem; font-weight: 600; color: #fff; }
    .btn-warning-custom { background-color: #ffc107; color: #212529; font-weight: 600; }
    .cartel-table { background-color: #1e1e1e; border-radius: 12px; overflow: hidden; }
    .cartel-table th { background-color: #121212; color: #ffc107; }
    .form-control-custom {
        background-color: #2d2d2d;
        border: 1px solid #444;
        color: #fff;
    }
    .form-control-custom:focus {
        background-color: #2d2d2d;
        border-color: #ffc107;
        box-shadow: none;
    }
</style>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-person-circle"></i> Mi Perfil</h2>
        <a href="dashboard_site.php" class="btn btn-outline-warning"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success_pass): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_pass); ?></div><?php endif; ?>
    <?php if ($error_pass): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_pass); ?></div><?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-3"><div class="info-card"><div class="info-icon"><i class="bi bi-hash"></i></div><div class="info-value"><?php echo $site_data['site']; ?></div><div class="small text-muted">Número</div></div></div>
        <div class="col-md-3"><div class="info-card"><div class="info-icon"><i class="bi bi-fuel-pump"></i></div><div class="info-value"><?php echo htmlspecialchars($petrolera_nombre); ?></div><div class="small text-muted">Petrolera</div></div></div>
        <div class="col-md-3"><div class="info-card"><div class="info-icon"><i class="bi bi-geo-alt"></i></div><div class="info-value"><?php echo htmlspecialchars($site_data['localidad'] . ', ' . $site_data['provincia']); ?></div><div class="small text-muted">Ubicación</div></div></div>
        <div class="col-md-3"><div class="info-card"><div class="info-icon"><i class="bi bi-telephone"></i></div><div class="info-value"><?php echo htmlspecialchars($site_data['telefono']); ?></div><div class="small text-muted">Teléfono</div></div></div>
    </div>

    <!-- Editar información -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-pencil-square"></i> Editar información</h5></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                <input type="hidden" name="actualizar_perfil" value="1">
                <div class="row">
                    <div class="col-md-6 mb-3"><label>Nombre *</label><input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($site_data['nombre']); ?>" required></div>
                    <div class="col-md-6 mb-3"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($site_data['email']); ?>"></div>
                </div>
                <div class="mb-3"><label>Domicilio</label><input type="text" name="domicilio" class="form-control" value="<?php echo htmlspecialchars($site_data['domicilio']); ?>"></div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label>Localidad</label><input type="text" name="localidad" class="form-control" value="<?php echo htmlspecialchars($site_data['localidad']); ?>"></div>
                    <div class="col-md-6 mb-3"><label>Provincia *</label><select name="provincia" class="form-select" required><option value="">Seleccione</option><?php foreach ($provincias as $p): ?><option value="<?php echo htmlspecialchars($p['nombre']); ?>" <?php echo ($site_data['provincia'] == $p['nombre']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nombre']); ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label>Teléfono</label><input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($site_data['telefono']); ?>"></div>
                    <div class="col-md-6 mb-3"><label>CUIT</label><input type="text" name="cuit" class="form-control" value="<?php echo htmlspecialchars($site_data['cuit']); ?>"></div>
                </div>
                <button type="submit" class="btn btn-warning-custom"><i class="bi bi-save"></i> Actualizar perfil</button>
            </form>
        </div>
    </div>

    <!-- Cambiar contraseña -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-key"></i> Cambiar contraseña</h5></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                <input type="hidden" name="cambiar_password" value="1">
                <div class="mb-3">
                    <label>Contraseña actual *</label>
                    <input type="password" name="current_password" class="form-control form-control-custom" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label>Nueva contraseña *</label>
                    <input type="password" name="new_password" class="form-control form-control-custom" required autocomplete="new-password">
                    <small class="text-muted">Mínimo 6 caracteres.</small>
                </div>
                <div class="mb-3">
                    <label>Confirmar nueva contraseña *</label>
                    <input type="password" name="confirm_password" class="form-control form-control-custom" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-warning-custom"><i class="bi bi-save"></i> Cambiar contraseña</button>
            </form>
        </div>
    </div>

    <!-- Carteles asociados -->
    <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-tv"></i> Carteles asociados</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table cartel-table">
                    <thead><tr class="text-center"><th>MAC</th><th>IP LAN</th><th>Estado Cartel</th><th>Estado VOX</th><th>Estado WFT</th><th>Últ. actualización</th></tr></thead>
                    <tbody>
                        <?php foreach ($carteles as $c): 
                            $wft_estado = $estados_wft[$c['MAC']] ?? 'Error';
                        ?>
                        <tr class="text-center">
                            <td><code><?php echo htmlspecialchars($c['MAC']); ?></code></td>
                            <td><?php echo htmlspecialchars($c['IP_LAN'] ?: 'No asignada'); ?></td>
                            <td><?php echo ($wft_estado == 'Error' || $c['estado485'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></td>
                            <td><?php echo ($wft_estado == 'Error' || $c['estadovox'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></td>
                            <td><?php echo ($wft_estado == 'OK') ? '<span class="text-ok">OK</span>' : '<span class="text-error">Error</span>'; ?></td>
                            <td><?php echo $c['fecha'] . ' ' . $c['hora']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>