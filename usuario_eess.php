<?php
// usuario_eess.php - Gestión de usuarios para la estación
require_once 'config.php';

if (!esEstacion()) {
    redirigir('login.php');
}

$site_num = $_SESSION['site_numero'];
$error = '';
$success = '';

// Procesar alta de usuario
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'alta') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad.';
    } else {
        $dni = intval($_POST['dni']);
        $sql_check = "SELECT id, Nombre, rol FROM Usuarios WHERE DNI = ?";
        $stmt = $conn_clientes->prepare($sql_check);
        $stmt->bind_param("i", $dni);
        $stmt->execute();
        $usuario = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$usuario) {
            $error = "Usuario inexistente. Avisa al usuario que se dé de alta primero.";
        } elseif ($usuario['rol'] != 1) {
            $error = "Este usuario no es de tipo común (rol=1).";
        } else {
            $sql_check_rel = "SELECT id, estado FROM Relaciones WHERE apies = ? AND DNI = ?";
            $stmt = $conn_clientes->prepare($sql_check_rel);
            $stmt->bind_param("ii", $site_num, $dni);
            $stmt->execute();
            $rel = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($rel) {
                $estado_texto = ($rel['estado'] == 0) ? 'activo' : 'inactivo';
                $error = "El usuario ya está asociado (estado: $estado_texto).";
            } else {
                $sql_ins = "INSERT INTO Relaciones (apies, idUsuario, DNI, estado) VALUES (?, ?, ?, 0)";
                $stmt = $conn_clientes->prepare($sql_ins);
                $stmt->bind_param("iii", $site_num, $usuario['id'], $dni);
                if ($stmt->execute()) {
                    $success = "Usuario {$usuario['Nombre']} (DNI $dni) agregado correctamente.";
                } else {
                    $error = "Error al agregar: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Procesar cambio de estado (ahora por POST, no por GET)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle'])) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Error de seguridad.';
    } else {
        $dni = intval($_POST['dni']);
        $estado_actual = intval($_POST['estado_actual']);
        $nuevo_estado = $estado_actual == 0 ? 1 : 0;
        $sql_upd = "UPDATE Relaciones SET estado = ? WHERE apies = ? AND DNI = ?";
        $stmt = $conn_clientes->prepare($sql_upd);
        $stmt->bind_param("iii", $nuevo_estado, $site_num, $dni);
        if ($stmt->execute()) {
            $success = "Estado del usuario actualizado.";
        } else {
            $error = "Error al actualizar.";
        }
        $stmt->close();
    }
}

// Listar usuarios asociados
$sql_list = "SELECT r.DNI, r.estado, u.Nombre, u.telefono FROM Relaciones r INNER JOIN Usuarios u ON r.idUsuario = u.id WHERE r.apies = ? ORDER BY u.Nombre";
$stmt = $conn_clientes->prepare($sql_list);
$stmt->bind_param("i", $site_num);
$stmt->execute();
$usuarios_asociados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<?php include 'header.php'; ?>
<style>
    .status-active { background-color: #28a745; color: #fff; padding: 5px 12px; border-radius: 20px; display: inline-block; }
    .status-inactive { background-color: #6c757d; color: #fff; padding: 5px 12px; border-radius: 20px; display: inline-block; }
    .table-users { background-color: #1e1e1e; border-radius: 12px; overflow: hidden; }
    .table-users th { background-color: #121212; color: #ffc107; }
</style>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-people"></i> Gestión de Usuarios</h2>
        <a href="dashboard_site.php" class="btn btn-outline-warning"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-person-plus"></i> Agregar usuario existente</h5></div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                <input type="hidden" name="accion" value="alta">
                <div class="col-md-8"><label>DNI del usuario</label><input type="number" name="dni" class="form-control" required></div>
                <div class="col-md-4"><button type="submit" class="btn btn-warning w-100 mt-4"><i class="bi bi-check-circle"></i> Dar de alta</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-list-ul"></i> Usuarios asociados</h5></div>
        <div class="card-body">
            <?php if (count($usuarios_asociados) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-users">
                        <thead><tr class="text-center"><th>DNI</th><th>Nombre</th><th>Teléfono</th><th>Estado</th><th>Acción</th></tr></thead>
                        <tbody>
                            <?php foreach ($usuarios_asociados as $u): ?>
                            <tr class="text-center">
                                <td><?php echo $u['DNI']; ?></td>
                                <td><?php echo htmlspecialchars($u['Nombre']); ?></td>
                                <td><?php echo htmlspecialchars($u['telefono']); ?></td>
                                <td><span class="<?php echo $u['estado'] == 0 ? 'status-active' : 'status-inactive'; ?>"><?php echo $u['estado'] == 0 ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                                        <input type="hidden" name="toggle" value="1">
                                        <input type="hidden" name="dni" value="<?php echo $u['DNI']; ?>">
                                        <input type="hidden" name="estado_actual" value="<?php echo $u['estado']; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $u['estado'] == 0 ? 'btn-danger' : 'btn-success'; ?>">
                                            <?php echo $u['estado'] == 0 ? 'Deshabilitar' : 'Habilitar'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info">No hay usuarios asociados.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>