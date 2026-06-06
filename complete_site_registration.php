<?php
// complete_site_registration.php (con CSRF, updates condicionales y corrección de duplicados)
require_once 'config.php';

$mac = $_GET['mac'] ?? '';
if (empty($mac)) {
    redirigir('register_cartel.php');
}

// Verificar que exista una solicitud pendiente
$sql_pend = "SELECT email FROM pendientes_registro WHERE MAC = ?";
$stmt = $conn_clientes->prepare($sql_pend);
$stmt->bind_param("s", $mac);
$stmt->execute();
$pendiente = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pendiente) {
    die('No hay una solicitud de registro activa para esta MAC.');
}

$petroleras = obtenerPetroleras();
$provincias = obtenerProvincias();
$error = '';
$success = '';
$warning = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad. Por favor, recargue la página.';
    } else {
        $nuevo_site = intval($_POST['site']);
        $nombre = sanitizarTexto(trim($_POST['nombre']));
        $petrolera_id = intval($_POST['petrolera_id']);
        $domicilio = sanitizarTexto(trim($_POST['domicilio']));
        $localidad = sanitizarTexto(trim($_POST['localidad']));
        $provincia = sanitizarTexto(trim($_POST['provincia']));
        $telefono = trim($_POST['telefono']);
        $cuit = trim($_POST['cuit']);
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];

        $cuit_pattern = '/^\d{2}-\d{8}-\d$/';
        if (!preg_match($cuit_pattern, $cuit)) {
            $error = 'El formato de CUIT debe ser ##-########-# (ejemplo: 20-12345678-9).';
        }

        if (empty($error) && (empty($nuevo_site) || empty($nombre) || empty($petrolera_id) || empty($password))) {
            $error = 'Los campos APIES, Nombre, Petrolera y Contraseña son obligatorios.';
        } elseif (empty($error) && $password !== $confirm) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (empty($error) && strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif (empty($error)) {
            // Verificar si el APIES ya está en uso (por si acaso)
            $check_site = $conn->prepare("SELECT id FROM sites WHERE site = ?");
            $check_site->bind_param("i", $nuevo_site);
            $check_site->execute();
            $site_exists = $check_site->get_result()->num_rows > 0;
            $check_site->close();

            if ($site_exists) {
                $error = 'El APIES ya está en uso.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Verificar si la MAC ya existe en la tabla sites (para evitar duplicados)
                $check_mac = $conn->prepare("SELECT id, site FROM sites WHERE MAC = ?");
                $check_mac->bind_param("s", $mac);
                $check_mac->execute();
                $existing = $check_mac->get_result()->fetch_assoc();
                $check_mac->close();

                if ($existing) {
                    // Ya existe un registro con esta MAC → ACTUALIZAR
                    $sql_upd_site = "UPDATE sites SET 
                                        site = ?, nombre = ?, petrolera_id = ?, domicilio = ?, localidad = ?, 
                                        provincia = ?, telefono = ?, cuit = ?, email = ?, password = ?, 
                                        updated_at = NOW(), email_verified = 1, Fecha = CURDATE(), hora = CURTIME()
                                    WHERE MAC = ?";
                    $stmt_upd = $conn->prepare($sql_upd_site);
                    $stmt_upd->bind_param("isississsss", 
                        $nuevo_site, $nombre, $petrolera_id, $domicilio, $localidad, 
                        $provincia, $telefono, $cuit, $pendiente['email'], $hash, $mac
                    );
                    $insert_ok = $stmt_upd->execute();
                    $stmt_upd->close();
                } else {
                    // No existe → INSERTAR
                    $sql_ins_site = "INSERT INTO sites (
                        site, nombre, petrolera_id, domicilio, localidad, provincia, telefono, cuit, email, password, 
                        created_at, updated_at, MAC, email_verified, Fecha, hora
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, 1, CURDATE(), CURTIME())";
                    $stmt_ins = $conn->prepare($sql_ins_site);
                    $stmt_ins->bind_param("isississsss", 
                        $nuevo_site, $nombre, $petrolera_id, $domicilio, $localidad, $provincia, $telefono, $cuit, 
                        $pendiente['email'], $hash, $mac
                    );
                    $insert_ok = $stmt_ins->execute();
                    $stmt_ins->close();
                }

                if ($insert_ok) {
                    // 1. Actualizar Cartel
                    $sql_upd_cartel = "UPDATE Cartel SET site = ? WHERE MAC = ?";
                    $stmt_cart = $conn->prepare($sql_upd_cartel);
                    $stmt_cart->bind_param("is", $nuevo_site, $mac);
                    $stmt_cart->execute();
                    $stmt_cart->close();

                    // 2. Actualizar IOT.Dispositivos (site + reset=1)
                    $conn_iot = @new mysqli(IOT_DB_HOST, IOT_DB_USER, IOT_DB_PASS, IOT_DB_NAME);
                    if (!$conn_iot->connect_error) {
                        $descripcion = 'Cartel ' . $nombre;
                        $sql_iot = "UPDATE Dispositivos SET site = ?, Producto = 'Cartel de precios', Descripcion = ?, Dispositivo = 'Cartel STILUX', reset = 1 WHERE MAC = ?";
                        $stmt_iot = $conn_iot->prepare($sql_iot);
                        if ($stmt_iot) {
                            $stmt_iot->bind_param("iss", $nuevo_site, $descripcion, $mac);
                            if (!$stmt_iot->execute()) {
                                $warning .= " No se pudo actualizar IOT: " . $stmt_iot->error;
                            }
                            $stmt_iot->close();
                        } else {
                            $warning .= " No se pudo preparar consulta IOT.";
                        }
                        $conn_iot->close();
                    } else {
                        $warning .= " No se pudo conectar a IOT.";
                    }

                    // Eliminar pendiente
                    $del = $conn_clientes->prepare("DELETE FROM pendientes_registro WHERE MAC = ?");
                    $del->bind_param("s", $mac);
                    $del->execute();
                    $del->close();

                    $success = 'Registro completado exitosamente. Ya puede iniciar sesión.';
                    if ($warning) {
                        $success .= ' Advertencias: ' . $warning;
                    }
                    $_POST = [];
                } else {
                    $error = 'Error al guardar los datos de la estación.';
                }
            }
        }
    }
}
?>
<?php include 'header.php'; ?>
<style>
    .input-wrapper { position: relative; }
    .toggle-password {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #ffffff !important;
        z-index: 10;
    }
    .toggle-password:hover { color: #ffc107 !important; }
</style>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card">
            <div class="card-header text-center">
                <h4><i class="bi bi-pencil-square"></i> Completar registro de estación</h4>
                <p class="mb-0">MAC: <?php echo htmlspecialchars($mac); ?></p>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <div class="text-center mt-3"><a href="login.php" class="btn btn-primary">Ir al inicio de sesión</a></div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                        <div class="mb-3"><label>APIES *</label><input type="number" name="site" class="form-control" required value="<?php echo htmlspecialchars($_POST['site'] ?? ''); ?>"></div>
                        <div class="mb-3"><label>Nombre *</label><input type="text" name="nombre" class="form-control" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"></div>
                        <div class="mb-3"><label>Petrolera *</label><select name="petrolera_id" class="form-select" required><?php foreach ($petroleras as $p): ?><option value="<?php echo $p['id']; ?>" <?php echo (isset($_POST['petrolera_id']) && $_POST['petrolera_id'] == $p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nombre']); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-3"><label>Domicilio</label><input type="text" name="domicilio" class="form-control" value="<?php echo htmlspecialchars($_POST['domicilio'] ?? ''); ?>"></div>
                        <div class="row"><div class="col-md-6 mb-3"><label>Localidad</label><input type="text" name="localidad" class="form-control" value="<?php echo htmlspecialchars($_POST['localidad'] ?? ''); ?>"></div><div class="col-md-6 mb-3"><label>Provincia *</label><select name="provincia" class="form-select" required><option value="">Seleccione...</option><?php foreach ($provincias as $p): ?><option value="<?php echo htmlspecialchars($p['nombre']); ?>" <?php echo (isset($_POST['provincia']) && $_POST['provincia'] == $p['nombre']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nombre']); ?></option><?php endforeach; ?></select></div></div>
                        <div class="mb-3"><label>Teléfono</label><input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>"></div>
                        <div class="mb-3"><label>CUIT *</label><input type="text" name="cuit" class="form-control" required placeholder="20-12345678-9" value="<?php echo htmlspecialchars($_POST['cuit'] ?? ''); ?>"></div>
                        <div class="mb-3"><label>Contraseña *</label><div class="input-wrapper"><input type="password" name="password" id="password" class="form-control" required><i class="bi bi-eye-slash toggle-password" id="togglePassword"></i></div></div>
                        <div class="mb-3"><label>Confirmar *</label><div class="input-wrapper"><input type="password" name="confirm_password" id="confirm_password" class="form-control" required><i class="bi bi-eye-slash toggle-password" id="toggleConfirmPassword"></i></div></div>
                        <button type="submit" class="btn btn-primary w-100">Completar registro</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    togglePassword.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        this.classList.toggle('bi-eye');
        this.classList.toggle('bi-eye-slash');
    });
    const toggleConfirm = document.getElementById('toggleConfirmPassword');
    const confirmInput = document.getElementById('confirm_password');
    toggleConfirm.addEventListener('click', function() {
        const type = confirmInput.getAttribute('type') === 'password' ? 'text' : 'password';
        confirmInput.setAttribute('type', type);
        this.classList.toggle('bi-eye');
        this.classList.toggle('bi-eye-slash');
    });
</script>
<?php include 'footer.php'; ?>