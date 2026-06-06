<?php
// login.php - Acceso al sistema (con CSRF)
require_once 'config.php';

if (estaLogueado()) {
    redirigir('index.php');
}

$error = '';
$modo = $_GET['modo'] ?? 'usuario';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad. Por favor, recargue la página.';
    } else {
        $modo = $_POST['modo'];
        if ($modo == 'usuario') {
            $identificador = trim($_POST['identificador']);
            $password = $_POST['password'];
            if (autenticarUsuario($identificador, $password)) {
                redirigirSegunRol();
            } else {
                if (isset($_SESSION['login_error'])) {
                    $error = $_SESSION['login_error'];
                    unset($_SESSION['login_error']);
                } else {
                    $error = 'Identificador o contraseña incorrectos.';
                }
            }
        } elseif ($modo == 'estacion') {
            $site = intval($_POST['site']);
            $password = $_POST['password'];
            if (autenticarEstacion($site, $password)) {
                redirigir('dashboard_site.php');
            } else {
                $error = 'Número de estación o contraseña incorrectos.';
            }
        }
    }
}

$csrf_token = generarTokenCSRF();
?>
<?php include 'header.php'; ?>
<style>
    .login-tabs .nav-link {
        color: #ccc;
        background-color: #1e1e1e;
        border: 1px solid #444;
        margin-right: 5px;
    }
    .login-tabs .nav-link.active {
        background-color: #ffc107;
        color: #212529 !important;
        border-color: #ffc107;
    }
    .login-card {
        background-color: #1e1e1e;
        border: 1px solid #444;
        border-radius: 12px;
    }
    .login-card .card-header {
        background-color: #121212;
        border-bottom: 1px solid #ffc107;
        color: #ffc107;
    }
    .btn-login {
        background-color: #ffc107;
        border-color: #ffc107;
        color: #212529;
        font-weight: 600;
    }
    .btn-login:hover {
        background-color: #e6ac00;
        border-color: #e6ac00;
    }
</style>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card login-card">
            <div class="card-header text-center">
                <h4><i class="bi bi-box-arrow-in-right"></i> Acceso al Sistema</h4>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <ul class="nav nav-tabs login-tabs" id="loginTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $modo=='usuario'?'active':''; ?>" id="usuario-tab" data-bs-toggle="tab" data-bs-target="#usuario" type="button" role="tab">Usuario</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $modo=='estacion'?'active':''; ?>" id="estacion-tab" data-bs-toggle="tab" data-bs-target="#estacion" type="button" role="tab">Estación</button>
                    </li>
                </ul>
                <div class="tab-content mt-3">
                    <div class="tab-pane fade <?php echo $modo=='usuario'?'show active':''; ?>" id="usuario" role="tabpanel">
                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="modo" value="usuario">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="mb-3">
                                <label class="form-label">Usuario / Email / DNI</label>
                                <input type="text" name="identificador" class="form-control" required autocomplete="off" value="">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password" name="password" class="form-control" required autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-login w-100">Ingresar como Usuario</button>
                            <div class="text-center mt-3">
                                <a href="forgot_password_user.php">¿Olvidaste tu contraseña?</a>
                            </div>
                        </form>
                    </div>
                    <div class="tab-pane fade <?php echo $modo=='estacion'?'show active':''; ?>" id="estacion" role="tabpanel">
                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="modo" value="estacion">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="mb-3">
                                <label class="form-label">Número de Estación</label>
                                <input type="number" name="site" class="form-control" required autocomplete="off" value="">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password" name="password" class="form-control" required autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-login w-100">Ingresar como Estación</button>
                            <div class="text-center mt-3">
                                <a href="forgot_password.php">¿Olvidaste tu contraseña?</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-footer text-center">
                <a href="register_user.php">Registrar nuevo usuario</a> |
                <a href="register_cartel.php">Registrar cartel por MAC</a>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>