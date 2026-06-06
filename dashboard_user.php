<?php
// dashboard_user.php - Perfil de usuario (edición directa + cambio de contraseña)
require_once 'config.php';

if (!esUsuario()) {
    redirigir('login.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';
$error_pass = '';
$success_pass = '';

// Obtener datos del usuario desde CLIENTES
$sql = "SELECT u.Nombre, u.Usuario, u.email, u.DNI, u.telefono, u.provincia, u.rol, r.rol as nombre_rol
        FROM Usuarios u
        LEFT JOIN roles r ON u.rol = r.id
        WHERE u.id = ?";
$stmt = $conn_clientes->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die('Usuario no encontrado.');
}

$provincias = obtenerProvincias();

// Procesar actualización de perfil
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_perfil'])) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad. Por favor, recargue la página.';
    } else {
        $nombre = trim($_POST['nombre']);
        $usuario = trim($_POST['usuario']);
        $email = trim($_POST['email']);
        $dni = trim($_POST['dni']);
        $telefono = trim($_POST['telefono']);
        $provincia = trim($_POST['provincia']);

        if (empty($nombre) || empty($usuario) || empty($email) || empty($dni) || empty($telefono)) {
            $error = 'Todos los campos son obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido.';
        } else {
            // Verificar usuario único
            $check_user = $conn_clientes->prepare("SELECT id FROM Usuarios WHERE Usuario = ? AND id != ?");
            $check_user->bind_param("si", $usuario, $user_id);
            $check_user->execute();
            $check_user->store_result();
            if ($check_user->num_rows > 0) {
                $error = 'El nombre de usuario ya está en uso.';
            } else {
                $check_user->close();
                // Verificar email único
                $check_email = $conn_clientes->prepare("SELECT id FROM Usuarios WHERE email = ? AND id != ?");
                $check_email->bind_param("si", $email, $user_id);
                $check_email->execute();
                $check_email->store_result();
                if ($check_email->num_rows > 0) {
                    $error = 'El email ya está registrado por otro usuario.';
                } else {
                    $check_email->close();
                    // Verificar DNI único
                    $check_dni = $conn_clientes->prepare("SELECT id FROM Usuarios WHERE DNI = ? AND id != ?");
                    $check_dni->bind_param("si", $dni, $user_id);
                    $check_dni->execute();
                    $check_dni->store_result();
                    if ($check_dni->num_rows > 0) {
                        $error = 'El DNI ya está registrado por otro usuario.';
                    } else {
                        $check_dni->close();
                        $update = $conn_clientes->prepare("UPDATE Usuarios SET Nombre = ?, Usuario = ?, email = ?, DNI = ?, telefono = ?, provincia = ? WHERE id = ?");
                        $update->bind_param("ssssssi", $nombre, $usuario, $email, $dni, $telefono, $provincia, $user_id);
                        if ($update->execute()) {
                            $_SESSION['user_nombre'] = $nombre;
                            $_SESSION['user_usuario'] = $usuario;
                            $success = 'Perfil actualizado correctamente.';
                            // Actualizar datos locales
                            $user['Nombre'] = $nombre;
                            $user['Usuario'] = $usuario;
                            $user['email'] = $email;
                            $user['DNI'] = $dni;
                            $user['telefono'] = $telefono;
                            $user['provincia'] = $provincia;
                        } else {
                            $error = 'Error al actualizar: ' . $update->error;
                        }
                        $update->close();
                    }
                }
            }
        }
    }
}

// Procesar cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cambiar_password'])) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error_pass = 'Error de seguridad. Por favor, recargue la página.';
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
            $sql_pass = "SELECT Password FROM Usuarios WHERE id = ?";
            $stmt = $conn_clientes->prepare($sql_pass);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (password_verify($current_password, $row['Password'])) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $conn_clientes->prepare("UPDATE Usuarios SET Password = ? WHERE id = ?");
                $update->bind_param("si", $new_hash, $user_id);
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
?>
<?php include 'header.php'; ?>
<style>
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
    .form-control-custom:disabled {
        background-color: #2d2d2d;
        opacity: 0.7;
    }
    .btn-warning-custom {
        background-color: #ffc107;
        border-color: #ffc107;
        color: #212529;
        font-weight: 600;
        padding: 10px 20px;
        transition: all 0.3s;
    }
    .btn-warning-custom:hover {
        background-color: #e6ac00;
        transform: translateY(-2px);
    }
    .card-separator {
        margin-top: 2rem;
        border-top: 1px solid #444;
    }
</style>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-person-circle me-2"></i> Mi Perfil</h2>
        <div>
            <?php if ($user['rol'] == 1): ?>
                <a href="user_stations.php" class="btn btn-outline-warning me-2"><i class="bi bi-building"></i> Mis Estaciones</a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-outline-warning"><i class="bi bi-box-arrow-right"></i> Salir</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i> Editar información personal</h5>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="actualizar_perfil" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Nombre completo *</label>
                        <input type="text" name="nombre" class="form-control form-control-custom" 
                               value="<?php echo htmlspecialchars($user['Nombre']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Usuario (nickname) *</label>
                        <input type="text" name="usuario" class="form-control form-control-custom" 
                               value="<?php echo htmlspecialchars($user['Usuario']); ?>" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Email *</label>
                        <input type="email" name="email" class="form-control form-control-custom" 
                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">DNI *</label>
                        <input type="text" name="dni" class="form-control form-control-custom" 
                               value="<?php echo htmlspecialchars($user['DNI']); ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Teléfono *</label>
                    <input type="text" name="telefono" class="form-control form-control-custom" 
                           value="<?php echo htmlspecialchars($user['telefono']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Provincia *</label>
                    <select name="provincia" class="form-select form-control-custom" required>
                        <option value="">Seleccione una provincia...</option>
                        <?php foreach ($provincias as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['nombre']); ?>" 
                                <?php echo ($user['provincia'] == $p['nombre']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Rol (no editable)</label>
                        <input type="text" class="form-control form-control-custom" 
                               value="<?php echo htmlspecialchars($user['nombre_rol']); ?>" disabled>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-3">
                    <button type="submit" class="btn btn-warning-custom">
                        <i class="bi bi-save me-2"></i> Actualizar perfil
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Sección para cambiar contraseña -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-key me-2"></i> Cambiar contraseña</h5>
        </div>
        <div class="card-body">
            <?php if ($error_pass): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_pass); ?></div>
            <?php endif; ?>
            <?php if ($success_pass): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_pass); ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="cambiar_password" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold">Contraseña actual *</label>
                    <input type="password" name="current_password" class="form-control form-control-custom" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Nueva contraseña *</label>
                    <input type="password" name="new_password" class="form-control form-control-custom" required autocomplete="new-password">
                    <small class="text-muted">Mínimo 6 caracteres.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Confirmar nueva contraseña *</label>
                    <input type="password" name="confirm_password" class="form-control form-control-custom" required autocomplete="new-password">
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-warning-custom">
                        <i class="bi bi-save me-2"></i> Cambiar contraseña
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>