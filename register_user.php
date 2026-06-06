<?php
// register_user.php - Registro de usuarios (con CSRF)
require_once 'config.php';

if (estaLogueado()) redirigir('index.php');

$error = '';
$success = '';
$provincias = obtenerProvincias();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad. Por favor, recargue la página.';
    } else {
        $nombre = sanitizarTexto(trim($_POST['nombre']));
        $usuario = sanitizarTexto(trim($_POST['usuario']));
        $email = trim($_POST['email']);
        $dni = trim($_POST['dni']);
        $telefono = trim($_POST['telefono']);
        $provincia = sanitizarTexto(trim($_POST['provincia']));
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];

        if (empty($nombre) || empty($usuario) || empty($email) || empty($password) || empty($dni) || empty($telefono) || empty($provincia)) {
            $error = 'Todos los campos son obligatorios.';
        } elseif ($password !== $confirm) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido.';
        } else {
            $check = $conn_clientes->prepare("SELECT id FROM Usuarios WHERE Usuario = ? OR email = ? OR DNI = ?");
            $check->bind_param("sss", $usuario, $email, $dni);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $error = 'El nombre de usuario, email o DNI ya está registrado.';
            } else {
                $check->close();
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $token = generarTokenVerificacion();
                $sql = "INSERT INTO Usuarios (Nombre, Usuario, Password, email, DNI, telefono, provincia, Petrolera, rol, email_verified, verification_token) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, '', 1, 0, ?)";
                $stmt = $conn_clientes->prepare($sql);
                $stmt->bind_param("ssssssss", $nombre, $usuario, $hash, $email, $dni, $telefono, $provincia, $token);
                if ($stmt->execute()) {
                    if (enviarEmailVerificacionUsuario($email, $token, $nombre)) {
                        $success = 'Registro exitoso. Se ha enviado un enlace de verificación a su correo.';
                    } else {
                        $error = 'Error al enviar el correo. Por favor, intente más tarde.';
                        $conn_clientes->query("DELETE FROM Usuarios WHERE email = '$email'"); // No inyección, pero mejor usar prepared
                        $del = $conn_clientes->prepare("DELETE FROM Usuarios WHERE email = ?");
                        $del->bind_param("s", $email);
                        $del->execute();
                        $del->close();
                    }
                } else {
                    $error = 'Error al registrar: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}
?>
<?php include 'header.php'; ?>
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header text-center">
                <h4><i class="bi bi-person-plus"></i> Registro de Usuario</h4>
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
                        <label class="form-label">Nombre completo *</label>
                        <input type="text" name="nombre" class="form-control" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Usuario (nickname) *</label>
                        <input type="text" name="usuario" class="form-control" required value="<?php echo htmlspecialchars($_POST['usuario'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">DNI *</label>
                        <input type="text" name="dni" class="form-control" required value="<?php echo htmlspecialchars($_POST['dni'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Teléfono *</label>
                        <input type="text" name="telefono" class="form-control" required value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Provincia *</label>
                        <select name="provincia" class="form-select" required>
                            <option value="">Seleccione una provincia...</option>
                            <?php foreach ($provincias as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['nombre']); ?>" <?php echo (isset($_POST['provincia']) && $_POST['provincia'] == $p['nombre']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contraseña *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Confirmar contraseña *</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Registrarse</button>
                </form>
            </div>
            <div class="card-footer text-center">
                ¿Ya tienes cuenta? <a href="login.php">Ingresa aquí</a>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>