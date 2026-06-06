<?php
// dashboard_user_sop.php - Panel de Soporte (Rol 9) con exclusión de site 9999 y listado de carteles sin registrar
require_once 'config.php';

if (!esUsuario() || $_SESSION['user_rol'] != 9) {
    redirigir('login.php');
}

$petroleras = obtenerPetroleras();

// ====================== GESTIÓN DE USUARIOS ======================
$mensaje_user = '';
$error_user = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_action'])) {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error_user = 'Error de seguridad.';
    } else {
        if ($_POST['user_action'] == 'create') {
            $nombre = sanitizarTexto(trim($_POST['nombre']));
            $usuario = sanitizarTexto(trim($_POST['usuario']));
            $email = trim($_POST['email']);
            $dni = trim($_POST['dni']);
            $rol = intval($_POST['rol']);
            $email_verified = isset($_POST['email_verified']) ? 1 : 0;
            $petrolera = ($rol == 4 && !empty($_POST['petrolera'])) ? sanitizarTexto(trim($_POST['petrolera'])) : '';
            if ($rol == 4 && empty($petrolera)) $error_user = "Debe seleccionar una petrolera.";
            
            $temp_password = bin2hex(random_bytes(4));
            $hash = password_hash($temp_password, PASSWORD_DEFAULT);
            
            if (empty($nombre) || empty($usuario) || empty($email) || empty($dni)) {
                $error_user = "Todos los campos obligatorios.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_user = "Email inválido.";
            } else {
                $check = $conn_clientes->prepare("SELECT id FROM Usuarios WHERE Usuario = ? OR Email = ? OR DNI = ?");
                $check->bind_param("sss", $usuario, $email, $dni);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $error_user = "Ya existe un usuario con ese Usuario, Email o DNI.";
                } else {
                    $check->close();
                    $sql = "INSERT INTO Usuarios (Nombre, Usuario, Email, Password, rol, Petrolera, email_verified, DNI) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn_clientes->prepare($sql);
                    $stmt->bind_param("ssssisss", $nombre, $usuario, $email, $hash, $rol, $petrolera, $email_verified, $dni);
                    if ($stmt->execute()) {
                        if (enviarCredencialesUsuario($email, $nombre, $usuario, $temp_password)) {
                            $mensaje_user = "Usuario creado. Se ha enviado un correo con sus credenciales.";
                        } else {
                            $mensaje_user = "Usuario creado, pero no se pudo enviar el correo.";
                        }
                    } else {
                        $error_user = "Error al crear usuario: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        } elseif ($_POST['user_action'] == 'update') {
            $id = intval($_POST['user_id']);
            $nombre = sanitizarTexto(trim($_POST['nombre']));
            $usuario = sanitizarTexto(trim($_POST['usuario']));
            $email = trim($_POST['email']);
            $dni = trim($_POST['dni']);
            $rol = intval($_POST['rol']);
            $email_verified = isset($_POST['email_verified']) ? 1 : 0;
            $petrolera = ($rol == 4 && !empty($_POST['petrolera'])) ? sanitizarTexto(trim($_POST['petrolera'])) : '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $cambiar_password = $new_password !== '' || $confirm_password !== '';
            if ($rol == 4 && empty($petrolera)) $error_user = "Debe seleccionar una petrolera.";
            
            if (empty($nombre) || empty($usuario) || empty($email) || empty($dni)) {
                $error_user = "Los campos nombre, usuario, email y DNI son obligatorios.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_user = "Email inválido.";
            } elseif ($cambiar_password && $new_password !== $confirm_password) {
                $error_user = "La nueva contraseña y su confirmación no coinciden.";
            } elseif ($cambiar_password && strlen($new_password) < 6) {
                $error_user = "La nueva contraseña debe tener al menos 6 caracteres.";
            } else {
                $check = $conn_clientes->prepare("SELECT id FROM Usuarios WHERE (Usuario = ? OR Email = ? OR DNI = ?) AND id != ?");
                $check->bind_param("sssi", $usuario, $email, $dni, $id);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $error_user = "Ya existe otro usuario con ese Usuario, Email o DNI.";
                } else {
                    $check->close();
                    if ($cambiar_password) {
                        $hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $sql = "UPDATE Usuarios SET Nombre=?, Usuario=?, Email=?, rol=?, Petrolera=?, email_verified=?, DNI=?, Password=? WHERE id=?";
                    } else {
                        $sql = "UPDATE Usuarios SET Nombre=?, Usuario=?, Email=?, rol=?, Petrolera=?, email_verified=?, DNI=? WHERE id=?";
                    }
                    $stmt = $conn_clientes->prepare($sql);
                    if ($cambiar_password) {
                        $stmt->bind_param("sssissssi", $nombre, $usuario, $email, $rol, $petrolera, $email_verified, $dni, $hash, $id);
                    } else {
                        $stmt->bind_param("sssisssi", $nombre, $usuario, $email, $rol, $petrolera, $email_verified, $dni, $id);
                    }
                    if ($stmt->execute()) {
                        $mensaje_user = $cambiar_password ? "Usuario y contraseña actualizados correctamente." : "Usuario actualizado correctamente.";
                    } else {
                        $error_user = "Error al actualizar: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        } elseif ($_POST['user_action'] == 'delete') {
            $id = intval($_POST['user_id']);
            if ($id == $_SESSION['user_id']) {
                $error_user = "No puedes eliminar tu propio usuario.";
            } else {
                $stmt = $conn_clientes->prepare("DELETE FROM Usuarios WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $mensaje_user = "Usuario eliminado correctamente.";
                } else {
                    $error_user = "Error al eliminar: " . $stmt->error;
                }
                $stmt->close();
            }
        } elseif ($_POST['user_action'] == 'reset_password') {
            $id = intval($_POST['user_id']);
            $stmt = $conn_clientes->prepare("SELECT Email, Nombre FROM Usuarios WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($user && enviarEmailRecuperacionUsuario($user['Email'], $user['Nombre'])) {
                $mensaje_user = "Se ha enviado un correo de restablecimiento a {$user['Email']}.";
            } else {
                $error_user = "No se pudo enviar el correo.";
            }
        }
    }
    header("Location: dashboard_user_sop.php?user_msg=" . urlencode($mensaje_user) . "&user_err=" . urlencode($error_user));
    exit;
}

// ====================== OBTENER DATOS DE ESTACIONES (optimizado, excluyendo site 9999) ======================
$sql_all = "SELECT s.site, s.nombre, s.domicilio, s.localidad, s.provincia, s.telefono, s.email, s.cuit, s.petrolera_id,
               s.Fecha, s.hora,
               c.MAC, c.IP_LAN, c.estado485, c.estadovox, c.precio1, c.precio2, c.precio3, c.precio4, c.precio5,
               c.idproducto1, c.idproducto2, c.idproducto3, c.idproducto4, c.idproducto5,
               CONCAT(c.fecha, ' ', c.hora) as ultima_actualizacion
        FROM sites s
        LEFT JOIN (
            SELECT site, MAX(id) as max_id FROM Cartel GROUP BY site
        ) cm ON s.site = cm.site
        LEFT JOIN Cartel c ON cm.max_id = c.id
        WHERE s.site != 9999
        ORDER BY s.site";
$stmt_all = $conn->prepare($sql_all);
$stmt_all->execute();
$all_stations = $stmt_all->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_all->close();

// Firmware desde IOT
$macs = array_filter(array_column($all_stations, 'MAC'));
$firmware_map = [];
if (!empty($macs)) {
    $conn_iot = new mysqli(IOT_DB_HOST, IOT_DB_USER, IOT_DB_PASS, IOT_DB_NAME);
    if (!$conn_iot->connect_error) {
        $placeholders = implode(',', array_fill(0, count($macs), '?'));
        $sql_fw = "SELECT MAC, firmware FROM Dispositivos WHERE MAC IN ($placeholders)";
        $stmt_fw = $conn_iot->prepare($sql_fw);
        if ($stmt_fw) {
            $types = str_repeat('s', count($macs));
            $stmt_fw->bind_param($types, ...$macs);
            $stmt_fw->execute();
            $result_fw = $stmt_fw->get_result();
            while ($row = $result_fw->fetch_assoc()) {
                $firmware_map[$row['MAC']] = $row['firmware'];
            }
            $stmt_fw->close();
        }
        $conn_iot->close();
    }
}
foreach ($all_stations as &$s) {
    $s['firmware'] = $firmware_map[$s['MAC']] ?? 'N/A';
}
unset($s);

// Estados WFT en lote
$estados_wft = obtenerEstadosWFTMultiples($macs);

// Estadísticas
$total_stations = count($all_stations);
$cartel_ok = $vox_ok = $wft_ok = 0;
foreach ($all_stations as $s) {
    $wft_estado = $estados_wft[$s['MAC']] ?? 'Error';
    if ($wft_estado == 'OK') {
        $wft_ok++;
        if ($s['estado485'] == 0) $cartel_ok++;
        if ($s['estadovox'] == 0) $vox_ok++;
    }
}
$cartel_porcentaje = $total_stations > 0 ? round(($cartel_ok / $total_stations) * 100) : 0;
$vox_porcentaje = $total_stations > 0 ? round(($vox_ok / $total_stations) * 100) : 0;
$wft_porcentaje = $total_stations > 0 ? round(($wft_ok / $total_stations) * 100) : 0;

// Filtros
$filter_petrolera = isset($_GET['petrolera']) ? intval($_GET['petrolera']) : 0;
$filter_mac = isset($_GET['mac']) ? trim($_GET['mac']) : '';
$filter_wft = $_GET['wft'] ?? '';
$filter_485 = $_GET['estado485'] ?? '';
$filter_vox = $_GET['estadovox'] ?? '';
$search = trim($_GET['search'] ?? '');

$filtered_stations = [];
foreach ($all_stations as $s) {
    if ($filter_petrolera != 0 && $s['petrolera_id'] != $filter_petrolera) continue;
    if ($filter_mac !== '' && stripos($s['MAC'], $filter_mac) === false) continue;
    $wft_estado = $estados_wft[$s['MAC']] ?? 'Error';
    $estado_texto = ($wft_estado == 'OK') ? 'ok' : 'error';
    if ($filter_wft !== '' && $filter_wft != $estado_texto) continue;
    if ($filter_485 !== '' && $filter_485 != ($s['estado485'] == 0 ? 'ok' : 'error')) continue;
    if ($filter_vox !== '' && $filter_vox != ($s['estadovox'] == 0 ? 'ok' : 'error')) continue;
    if ($search !== '') {
        $match = stripos((string)$s['site'], $search) !== false ||
                 stripos($s['nombre'], $search) !== false ||
                 stripos($s['cuit'], $search) !== false;
        if (!$match) continue;
    }
    $filtered_stations[] = $s;
}

$selected_site = isset($_GET['site']) ? intval($_GET['site']) : 0;
$selected_station = null;
$cartel_activo = null;
foreach ($filtered_stations as $s) {
    if ($s['site'] == $selected_site) {
        $selected_station = $s;
        $cartel_activo = $s;
        break;
    }
}

// Productos para modales
$productos_por_petrolera = [];
$sql_prod = "SELECT idproducto, petrolera_id, nombre FROM Productos ORDER BY petrolera_id, idproducto";
$result_prod = $conn->query($sql_prod);
if ($result_prod) {
    while ($row = $result_prod->fetch_assoc()) {
        $productos_por_petrolera[$row['petrolera_id']][] = $row;
    }
}

// Procesar ediciones POST de estaciones
$mensaje = '';
$error_edit = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !verificarTokenCSRF($_POST['csrf_token'])) {
        $error_edit = 'Error de seguridad.';
    } else {
        if (isset($_POST['edit_site']) && $_POST['edit_site'] == '1') {
            $old_site = intval($_POST['old_site']);
            $new_site = intval($_POST['new_site']);
            $nombre = sanitizarTexto(trim($_POST['nombre']));
            $domicilio = sanitizarTexto(trim($_POST['domicilio']));
            $localidad = sanitizarTexto(trim($_POST['localidad']));
            $provincia = sanitizarTexto(trim($_POST['provincia']));
            $telefono = trim($_POST['telefono']);
            $email = trim($_POST['email']);
            $cuit = trim($_POST['cuit']);
            $petrolera_id = intval($_POST['petrolera_id']);

            if ($new_site != $old_site) {
                $check = $conn->prepare("SELECT id FROM sites WHERE site = ? AND site != ?");
                $check->bind_param("ii", $new_site, $old_site);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error_edit = "El número de estación $new_site ya está en uso.";
                } else {
                    $check->close();
                    $conn->begin_transaction();
                    try {
                        $stmt_upd = $conn->prepare("UPDATE sites SET site=?, nombre=?, domicilio=?, localidad=?, provincia=?, telefono=?, email=?, cuit=?, petrolera_id=? WHERE site=?");
                        $stmt_upd->bind_param("isssssssii", $new_site, $nombre, $domicilio, $localidad, $provincia, $telefono, $email, $cuit, $petrolera_id, $old_site);
                        $stmt_upd->execute();
                        $stmt_upd->close();
                        $conn->query("UPDATE Cartel SET site = $new_site WHERE site = $old_site");
                        $conn_iot = new mysqli(IOT_DB_HOST, IOT_DB_USER, IOT_DB_PASS, IOT_DB_NAME);
                        if (!$conn_iot->connect_error) {
                            $conn_iot->query("UPDATE Dispositivos SET site = $new_site WHERE site = $old_site");
                            $conn_iot->close();
                        }
                        $conn_clientes->query("UPDATE pendientes_registro SET site = $new_site WHERE site = $old_site");
                        $conn->commit();
                        $mensaje = "Estación actualizada correctamente.";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error_edit = "Error en transacción: " . $e->getMessage();
                    }
                }
            } else {
                $stmt_upd = $conn->prepare("UPDATE sites SET nombre=?, domicilio=?, localidad=?, provincia=?, telefono=?, email=?, cuit=?, petrolera_id=? WHERE site=?");
                $stmt_upd->bind_param("sssssssii", $nombre, $domicilio, $localidad, $provincia, $telefono, $email, $cuit, $petrolera_id, $old_site);
                if ($stmt_upd->execute()) $mensaje = "Datos actualizados.";
                else $error_edit = "Error: " . $stmt_upd->error;
                $stmt_upd->close();
            }
        }
        if (isset($_POST['edit_prices']) && $_POST['edit_prices'] == '1') {
            $site_id = intval($_POST['site_id']);
            $precios = [];
            $idproductos = [];
            for ($i=1;$i<=5;$i++) {
                $precios[$i] = floatval(str_replace(',', '.', $_POST["precio$i"]));
                $idproductos[$i] = intval($_POST["idproducto$i"]);
            }
            $sql_get = "SELECT id FROM Cartel WHERE site = ? ORDER BY id DESC LIMIT 1";
            $stmt = $conn->prepare($sql_get);
            $stmt->bind_param("i", $site_id);
            $stmt->execute();
            $last = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($last) {
                $sql_upd = "UPDATE Cartel SET precio1=?, precio2=?, precio3=?, precio4=?, precio5=?, idproducto1=?, idproducto2=?, idproducto3=?, idproducto4=?, idproducto5=? WHERE id=?";
                $stmt = $conn->prepare($sql_upd);
                $stmt->bind_param("dddddiiiiii", $precios[1], $precios[2], $precios[3], $precios[4], $precios[5], $idproductos[1], $idproductos[2], $idproductos[3], $idproductos[4], $idproductos[5], $last['id']);
                if ($stmt->execute()) $mensaje = "Precios actualizados.";
                else $error_edit = "Error: " . $stmt->error;
                $stmt->close();
            }
        }
        if (isset($_POST['reset_password_site']) && $_POST['reset_password_site'] == '1') {
            $site_id = intval($_POST['site_id']);
            $email = trim($_POST['email']);
            $nombre = trim($_POST['nombre']);
            if (!empty($email) && enviarEmailRecuperacionEstacion($site_id, $email, $nombre)) {
                $mensaje = "Correo de restablecimiento enviado a $email.";
            } else {
                $error_edit = "No se pudo enviar el correo.";
            }
        }
        if (isset($_POST['change_password_site']) && $_POST['change_password_site'] == '1') {
            $site_id = intval($_POST['site_id']);
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if ($site_id <= 0) {
                $error_edit = "APIES inválido.";
            } elseif ($new_password === '' || $confirm_password === '') {
                $error_edit = "Debe ingresar y confirmar la nueva contraseña.";
            } elseif ($new_password !== $confirm_password) {
                $error_edit = "La nueva contraseña y su confirmación no coinciden.";
            } elseif (strlen($new_password) < 6) {
                $error_edit = "La nueva contraseña debe tener al menos 6 caracteres.";
            } else {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE sites SET password = ? WHERE site = ?");
                $stmt->bind_param("si", $hash, $site_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $del = $conn->prepare("DELETE FROM password_resets_sites WHERE site = ?");
                    $del->bind_param("i", $site_id);
                    $del->execute();
                    $del->close();
                    $mensaje = "Contraseña de la estación $site_id actualizada correctamente.";
                } else {
                    $error_edit = "No se pudo actualizar la contraseña de la estación.";
                }
                $stmt->close();
            }
        }
    }
    header("Location: dashboard_user_sop.php?" . http_build_query(array_merge($_GET, ['mensaje' => urlencode($mensaje), 'error' => urlencode($error_edit)])));
    exit;
}

// Consulta para carteles sin registrar (site = 9999)
$sql_unregistered = "SELECT * FROM Cartel WHERE site = 9999 ORDER BY MAC";
$result_unreg = $conn->query($sql_unregistered);
$unregistered_carteles = $result_unreg ? $result_unreg->fetch_all(MYSQLI_ASSOC) : [];

$query_params = array_filter(['petrolera'=>$filter_petrolera, 'mac'=>$filter_mac, 'wft'=>$filter_wft, 'estado485'=>$filter_485, 'estadovox'=>$filter_vox, 'search'=>$search, 'site'=>$selected_site]);
$refresh_url = '?' . http_build_query($query_params);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30; url=<?php echo $refresh_url; ?>">
    <title><?php echo APP_NAME; ?> - Soporte</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #fff; }
         main.container { max-width: 100% !important; padding-left: 5% !important; padding-right: 5% !important; }
        .dashboard-container { width: 100% !important; margin: 0 !important; padding: 0 !important;}
        .stations-table { background-color: #1e1e1e; border-radius: 12px; overflow-x: auto; width: 100%; white-space: nowrap; }
        .stations-table th, .stations-table td { white-space: nowrap; padding: 12px 16px; vertical-align: middle; }
        .stations-table th { background-color: #121212; color: #ffc107; border-bottom: 2px solid #ffc107; }
        .stations-table tr { cursor: pointer; transition: background-color 0.2s; }
        .stations-table tr:hover { background-color: #2a2a2a; }
        .stations-table tr.active { background-color: #2a2a2a; border-left: 3px solid #ffc107; }
        .text-ok { color: #28a745 !important; font-weight: 600; }
        .text-error { color: #dc3545 !important; font-weight: 600; }
        .filter-card { background-color: #1e1e1e; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .metric-circle { flex: 1; min-width: 140px; text-align: center; background-color: #1e1e1e; border-radius: 12px; padding: 20px 15px; margin: 5px; }
        .circle { position: relative; width: 120px; height: 120px; margin: 0 auto 15px; background-color: #2a2a2a; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .circle-value { font-size: 1.8rem; font-weight: bold; color: #ffc107; }
        .circle-svg { position: absolute; width: 100%; height: 100%; transform: rotate(-90deg); }
        .circle-bg { stroke: #444; stroke-width: 6; fill: none; }
        .circle-fill { stroke: #ffc107; stroke-width: 6; fill: none; stroke-linecap: round; transition: stroke-dashoffset 0.8s ease; }
        .price-list { background-color: #1e1e1e; border-radius: 12px; overflow: hidden; }
        .price-item { display: flex; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid #444; }
        .product-name { color: #ffc107; font-weight: 600; }
        .product-price { font-family: monospace; font-weight: 700; }
        .modal-content { background-color: #1e1e1e; color: #fff; }
        .modal-header { border-bottom-color: #ffc107; background-color: #121212; color: #ffc107; }
        .btn-close { filter: invert(1); }
        .btn-warning { background-color: #ffc107; border-color: #ffc107; color: #212529; font-weight: 600; }
        .card.h-100 { height: 100% !important; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="dashboard-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-headset me-2"></i> Panel de Soporte Técnico</h2>
            <div>
                <button type="button" class="btn btn-outline-warning me-2" data-bs-toggle="modal" data-bs-target="#userManagerModal">
                    <i class="bi bi-people me-2"></i> Administrar usuarios
                </button>
                <button type="button" class="btn btn-outline-info me-2" data-bs-toggle="modal" data-bs-target="#unregisteredModal">
                    <i class="bi bi-tv me-2"></i> Carteles sin registrar (<?php echo count($unregistered_carteles); ?>)
                </button>
                <a href="logout.php" class="btn btn-outline-warning"><i class="bi bi-box-arrow-right me-2"></i> Salir</a>
            </div>
        </div>

        <?php if ($mensaje): ?><div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div><?php endif; ?>
        <?php if ($error_edit): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_edit); ?></div><?php endif; ?>
        <?php if ($mensaje_user): ?><div class="alert alert-success"><?php echo htmlspecialchars($mensaje_user); ?></div><?php endif; ?>
        <?php if ($error_user): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_user); ?></div><?php endif; ?>

        <!-- Métricas -->
        <div class="d-flex flex-wrap gap-3 mb-4">
            <div class="metric-circle"><div class="circle"><span class="circle-value"><?php echo $total_stations; ?></span><svg class="circle-svg" viewBox="0 0 120 120"><circle class="circle-bg" cx="60" cy="60" r="56"></circle><circle class="circle-fill" cx="60" cy="60" r="56" stroke-dashoffset="0"></circle></svg></div><div class="metric-label">Total estaciones</div></div>
            <div class="metric-circle"><div class="circle"><span class="circle-value"><?php echo $cartel_porcentaje; ?>%</span><svg class="circle-svg" viewBox="0 0 120 120"><circle class="circle-bg" cx="60" cy="60" r="56"></circle><circle class="circle-fill" cx="60" cy="60" r="56" stroke-dashoffset="<?php echo (100 - $cartel_porcentaje) * 351.858 / 100; ?>"></circle></svg></div><div class="metric-label">Estado Cartel OK</div><div class="metric-sub"><?php echo $cartel_ok; ?> / <?php echo $total_stations; ?></div></div>
            <div class="metric-circle"><div class="circle"><span class="circle-value"><?php echo $vox_porcentaje; ?>%</span><svg class="circle-svg" viewBox="0 0 120 120"><circle class="circle-bg" cx="60" cy="60" r="56"></circle><circle class="circle-fill" cx="60" cy="60" r="56" stroke-dashoffset="<?php echo (100 - $vox_porcentaje) * 351.858 / 100; ?>"></circle></svg></div><div class="metric-label">Estado VOX OK</div><div class="metric-sub"><?php echo $vox_ok; ?> / <?php echo $total_stations; ?></div></div>
            <div class="metric-circle"><div class="circle"><span class="circle-value"><?php echo $wft_porcentaje; ?>%</span><svg class="circle-svg" viewBox="0 0 120 120"><circle class="circle-bg" cx="60" cy="60" r="56"></circle><circle class="circle-fill" cx="60" cy="60" r="56" stroke-dashoffset="<?php echo (100 - $wft_porcentaje) * 351.858 / 100; ?>"></circle></svg></div><div class="metric-label">Estado WFT OK</div><div class="metric-sub"><?php echo $wft_ok; ?> / <?php echo $total_stations; ?></div></div>
        </div>

        <!-- Filtros -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2"><label>Petrolera</label><select name="petrolera" class="form-select"><option value="0">Todas</option><?php foreach ($petroleras as $p): ?><option value="<?php echo $p['id']; ?>" <?php echo ($filter_petrolera == $p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nombre']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label>MAC</label><input type="text" name="mac" class="form-control" value="<?php echo htmlspecialchars($filter_mac); ?>" placeholder="Ej: 44:1D:64"></div>
                <div class="col-md-2"><label>Estado WFT</label><select name="wft" class="form-select"><option value="">Todos</option><option value="ok" <?php echo $filter_wft=='ok'?'selected':''; ?>>OK</option><option value="error" <?php echo $filter_wft=='error'?'selected':''; ?>>Error</option></select></div>
                <div class="col-md-2"><label>Estado Cartel</label><select name="estado485" class="form-select"><option value="">Todos</option><option value="ok" <?php echo $filter_485=='ok'?'selected':''; ?>>OK</option><option value="error" <?php echo $filter_485=='error'?'selected':''; ?>>Error</option></select></div>
                <div class="col-md-2"><label>Estado VOX</label><select name="estadovox" class="form-select"><option value="">Todos</option><option value="ok" <?php echo $filter_vox=='ok'?'selected':''; ?>>OK</option><option value="error" <?php echo $filter_vox=='error'?'selected':''; ?>>Error</option></select></div>
                <div class="col-md-2"><label>Buscar</label><input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Site, Nombre, CUIT"></div>
                <div class="col-md-12 text-end mt-3"><button type="submit" class="btn btn-warning"><i class="bi bi-funnel"></i> Filtrar</button> <a href="dashboard_user_sop.php" class="btn btn-secondary"><i class="bi bi-eraser"></i> Limpiar</a></div>
            </form>
        </div>

        <!-- Detalle estación seleccionada -->
        <?php if ($selected_station && $cartel_activo): 
            $wft_estado = $estados_wft[$selected_station['MAC']] ?? 'Error';
            $telefono_clean = preg_replace('/[^0-9]/', '', $selected_station['telefono']);
        ?>
            <div class="row mb-4">
                <div class="col-md-6 d-flex">
                    <div class="card bg-dark w-100 h-100">
                        <div class="card-header text-warning d-flex justify-content-between">
                            <h5 class="mb-0"><i class="bi bi-calculator"></i> Precios actuales</h5>
                            <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editPricesModal"
                                data-site="<?php echo $selected_station['site']; ?>"
                                data-precio1="<?php echo $cartel_activo['precio1']; ?>" data-precio2="<?php echo $cartel_activo['precio2']; ?>"
                                data-precio3="<?php echo $cartel_activo['precio3']; ?>" data-precio4="<?php echo $cartel_activo['precio4']; ?>"
                                data-precio5="<?php echo $cartel_activo['precio5']; ?>"
                                data-idproducto1="<?php echo $cartel_activo['idproducto1']; ?>" data-idproducto2="<?php echo $cartel_activo['idproducto2']; ?>"
                                data-idproducto3="<?php echo $cartel_activo['idproducto3']; ?>" data-idproducto4="<?php echo $cartel_activo['idproducto4']; ?>"
                                data-idproducto5="<?php echo $cartel_activo['idproducto5']; ?>"
                                data-petrolera_id="<?php echo $selected_station['petrolera_id']; ?>">
                                <i class="bi bi-pencil-square"></i> Editar
                            </button>
                        </div>
                        <div class="price-list">
                            <?php 
                            $productos_mostrados = 0;
                            for ($i = 1; $i <= 5; $i++):
                                $idproducto = $cartel_activo['idproducto'.$i] ?? null;
                                if (empty($idproducto)) continue;
                                $nombre_producto = obtenerNombreProducto($selected_station['petrolera_id'], $idproducto);
                                if (empty($nombre_producto)) continue;
                                $precio = $cartel_activo['precio'.$i] ?? 0;
                                $productos_mostrados++;
                            ?>
                            <div class="price-item">
                                <span class="product-name"><?php echo htmlspecialchars($nombre_producto); ?></span>
                                <span class="product-price">$ <?php echo formatearPrecio($precio); ?></span>
                            </div>
                            <?php endfor; ?>
                            <?php if ($productos_mostrados == 0): ?>
                                <div class="price-item text-center text-muted">
                                    <span>No hay productos configurados para este cartel</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 d-flex">
                    <div class="card bg-dark w-100 h-100">
                        <div class="card-header text-warning d-flex justify-content-between">
                            <h5 class="mb-0"><i class="bi bi-building"></i> Estación <?php echo $selected_station['site']; ?> - <?php echo htmlspecialchars($selected_station['nombre']); ?></h5>
                            <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editSiteModal"
                                data-old-site="<?php echo $selected_station['site']; ?>"
                                data-nombre="<?php echo htmlspecialchars($selected_station['nombre']); ?>"
                                data-domicilio="<?php echo htmlspecialchars($selected_station['domicilio']); ?>"
                                data-localidad="<?php echo htmlspecialchars($selected_station['localidad']); ?>"
                                data-provincia="<?php echo htmlspecialchars($selected_station['provincia']); ?>"
                                data-telefono="<?php echo htmlspecialchars($selected_station['telefono']); ?>"
                                data-email="<?php echo htmlspecialchars($selected_station['email']); ?>"
                                data-cuit="<?php echo htmlspecialchars($selected_station['cuit']); ?>"
                                data-petrolera_id="<?php echo $selected_station['petrolera_id']; ?>">
                                <i class="bi bi-person-circle me-2"></i> Editar perfil
                            </button>
                        </div>
                        <div class="card-body">
                            <p><strong>Domicilio:</strong> <?php echo htmlspecialchars($selected_station['domicilio']); ?></p>
                            <p><strong>Localidad:</strong> <?php echo htmlspecialchars($selected_station['localidad']); ?> - <strong>Provincia:</strong> <?php echo htmlspecialchars($selected_station['provincia']); ?></p>
                            <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($selected_station['telefono']); ?></p>
                            <p><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($selected_station['email']); ?>" class="text-warning"><?php echo htmlspecialchars($selected_station['email']); ?></a></p>
                            <p><strong>CUIT:</strong> <?php echo htmlspecialchars($selected_station['cuit']); ?></p>
                            <hr>
                            <p><strong>MAC:</strong> <?php echo htmlspecialchars($selected_station['MAC']); ?></p>
                            <p><strong>IP LAN:</strong> <?php echo htmlspecialchars($selected_station['IP_LAN'] ?: 'No asignada'); ?></p>
                            <p><strong>Firmware:</strong> <?php echo htmlspecialchars($selected_station['firmware']); ?></p>
                            <p><strong>Estado Cartel:</strong> <?php echo ($wft_estado == 'Error' || $selected_station['estado485'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></p>
                            <p><strong>Estado VOX:</strong> <?php echo ($wft_estado == 'Error' || $selected_station['estadovox'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></p>
                            <p><strong>Estado WFT:</strong> <?php echo ($wft_estado == 'OK') ? '<span class="text-ok">OK</span>' : '<span class="text-error">Error</span>'; ?></p>
                            <hr>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                                <input type="hidden" name="reset_password_site" value="1">
                                <input type="hidden" name="site_id" value="<?php echo $selected_station['site']; ?>">
                                <input type="hidden" name="email" value="<?php echo htmlspecialchars($selected_station['email']); ?>">
                                <input type="hidden" name="nombre" value="<?php echo htmlspecialchars($selected_station['nombre']); ?>">
                                <button type="submit" class="btn btn-info btn-sm w-100" <?php echo empty($selected_station['email']) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-envelope me-2"></i> Enviar correo de restablecimiento
                                </button>
                            </form>
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                                <input type="hidden" name="change_password_site" value="1">
                                <input type="hidden" name="site_id" value="<?php echo $selected_station['site']; ?>">
                                <div class="mb-2">
                                    <label class="form-label">Nueva contraseña de estación</label>
                                    <input type="password" name="new_password" class="form-control form-control-sm" autocomplete="new-password" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Confirmar contraseña</label>
                                    <input type="password" name="confirm_password" class="form-control form-control-sm" autocomplete="new-password" required>
                                </div>
                                <button type="submit" class="btn btn-warning btn-sm w-100">
                                    <i class="bi bi-key me-2"></i> Cambiar contraseña de estación
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tabla de estaciones -->
        <div class="table-responsive">
            <table class="table stations-table">
                <thead><tr class="text-center"><th>APIES</th><th>Nombre</th><th>Dirección</th><th>Provincia</th><th>MAC</th><th>IP LAN</th><th>Firmware</th><th>Estado Cartel</th><th>Estado VOX</th><th>Estado WFT</th><th>Últ. actualización</th></tr></thead>
                <tbody>
                    <?php foreach ($filtered_stations as $s): 
                        $wft_estado = $estados_wft[$s['MAC']] ?? 'Error';
                        $click_params = array_filter(['site'=>$s['site'], 'petrolera'=>$filter_petrolera, 'mac'=>$filter_mac, 'wft'=>$filter_wft, 'estado485'=>$filter_485, 'estadovox'=>$filter_vox, 'search'=>$search]);
                        $click_url = '?' . http_build_query($click_params);
                    ?>
                    <tr class="text-center <?php echo ($selected_site == $s['site']) ? 'active' : ''; ?>" onclick="window.location.href='<?php echo $click_url; ?>'">
                        <td><?php echo $s['site']; ?></td>
                        <td><?php echo htmlspecialchars($s['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($s['domicilio']); ?></td>
                        <td><?php echo htmlspecialchars($s['provincia']); ?></td>
                        <td><code><?php echo htmlspecialchars($s['MAC']); ?></code></td>
                        <td><?php echo htmlspecialchars($s['IP_LAN'] ?: 'No asignada'); ?></td>
                        <td><?php echo htmlspecialchars($s['firmware']); ?></td>
                        <td><?php echo ($wft_estado == 'Error' || $s['estado485'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></td>
                        <td><?php echo ($wft_estado == 'Error' || $s['estadovox'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></td>
                        <td><?php echo ($wft_estado == 'OK') ? '<span class="text-ok">OK</span>' : '<span class="text-error">Error</span>'; ?></td>
                        <td><?php echo formatearFechaHora($s['Fecha'], $s['hora']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal de carteles sin registrar -->
    <div class="modal fade" id="unregisteredModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-tv"></i> Carteles sin registrar (site = 9999)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($unregistered_carteles)): ?>
                        <div class="alert alert-info">No hay carteles sin registrar.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-dark">
                                <thead>
                                    <tr class="text-center">
                                        <th>MAC</th>
                                        <th>IP LAN</th>
                                        <th>Estado 485</th>
                                        <th>Estado VOX</th>
                                        <th>Últ. actualización</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unregistered_carteles as $cartel): 
                                        $wft_estado = obtenerEstadoWFT($cartel['MAC']);
                                    ?>
                                    <tr class="text-center">
                                        <td><code><?php echo htmlspecialchars($cartel['MAC']); ?></code></td>
                                        <td><?php echo htmlspecialchars($cartel['IP_LAN'] ?: 'No asignada'); ?></td>
                                        <td><?php echo ($wft_estado == 'Error' || $cartel['estado485'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></td>
                                        <td><?php echo ($wft_estado == 'Error' || $cartel['estadovox'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></td>
                                        <td><?php echo formatearFechaHora($cartel['fecha'], $cartel['hora']); ?></td>
                                        <td>
                                            <a href="complete_site_registration.php?mac=<?php echo urlencode($cartel['MAC']); ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-pencil-square"></i> Completar registro
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modales existentes (usuarios, editar estación, precios) se mantienen igual -->
    <div class="modal fade" id="userManagerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-people"></i> Administrar usuarios</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between mb-3">
                        <input type="text" id="userSearchInput" class="form-control w-50" placeholder="Buscar...">
                        <button type="button" class="btn btn-success" onclick="openCreateUserModal()"><i class="bi bi-plus-circle"></i> Nuevo usuario</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark" id="usersTable">
                            <thead><tr><th>ID</th><th>Nombre</th><th>Usuario</th><th>Email</th><th>DNI</th><th>Rol</th><th>Petrolera</th><th>Verificado</th><th>Acciones</th></tr></thead>
                            <tbody id="usersTableBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="userForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                    <input type="hidden" name="user_action" id="user_action" value="create">
                    <input type="hidden" name="user_id" id="user_id" value="">
                    <div class="modal-header"><h5 class="modal-title" id="userModalTitle">Crear usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label>Nombre *</label><input type="text" name="nombre" id="user_nombre" class="form-control" required></div>
                        <div class="mb-3"><label>Usuario *</label><input type="text" name="usuario" id="user_usuario" class="form-control" required></div>
                        <div class="mb-3"><label>Email *</label><input type="email" name="email" id="user_email" class="form-control" required></div>
                        <div class="mb-3"><label>DNI *</label><input type="text" name="dni" id="user_dni" class="form-control" required></div>
                        <div class="mb-3"><label>Rol *</label><select name="rol" id="user_rol" class="form-select" required><option value="1">1 - Usuario</option><option value="4">4 - Petrolera</option><option value="5">5 - Técnico</option><option value="9">9 - Soporte</option></select></div>
                        <div class="mb-3" id="petrolera_div" style="display:none;"><label>Petrolera</label><select name="petrolera" id="user_petrolera" class="form-select"><option value="">Seleccionar...</option><?php foreach ($petroleras as $p): ?><option value="<?php echo htmlspecialchars($p['nombre']); ?>"><?php echo htmlspecialchars($p['nombre']); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-3 form-check"><input type="checkbox" name="email_verified" id="user_email_verified" class="form-check-input" value="1"> <label>Email verificado</label></div>
                        <div id="user_password_fields" style="display:none;">
                            <hr>
                            <div class="mb-3"><label>Nueva contraseña</label><input type="password" name="new_password" id="user_new_password" class="form-control" autocomplete="new-password"></div>
                            <div class="mb-3"><label>Confirmar nueva contraseña</label><input type="password" name="confirm_password" id="user_confirm_password" class="form-control" autocomplete="new-password"></div>
                            <div class="alert alert-warning small">Complete estos campos solo si desea modificar la contraseña del usuario.</div>
                        </div>
                        <div class="alert alert-info small" id="user_create_password_info">La contraseña se generará automáticamente y se enviará por correo.</div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editSiteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                    <input type="hidden" name="edit_site" value="1">
                    <input type="hidden" name="old_site" id="old_site">
                    <div class="modal-header"><h5 class="modal-title">Editar estación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label>Número APIES *</label><input type="number" name="new_site" id="new_site" class="form-control" required></div>
                        <div class="mb-3"><label>Petrolera</label><select name="petrolera_id" id="edit_petrolera_id" class="form-select"><?php foreach ($petroleras as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-3"><label>Nombre</label><input type="text" name="nombre" id="edit_nombre" class="form-control" required></div>
                        <div class="mb-3"><label>Domicilio</label><input type="text" name="domicilio" id="edit_domicilio" class="form-control"></div>
                        <div class="row"><div class="col-md-6 mb-3"><label>Localidad</label><input type="text" name="localidad" id="edit_localidad" class="form-control"></div><div class="col-md-6 mb-3"><label>Provincia</label><input type="text" name="provincia" id="edit_provincia" class="form-control"></div></div>
                        <div class="mb-3"><label>Teléfono</label><input type="text" name="telefono" id="edit_telefono" class="form-control"></div>
                        <div class="mb-3"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                        <div class="mb-3"><label>CUIT</label><input type="text" name="cuit" id="edit_cuit" class="form-control"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editPricesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>">
                    <input type="hidden" name="edit_prices" value="1">
                    <input type="hidden" name="site_id" id="prices_site_id">
                    <div class="modal-header"><h5 class="modal-title">Editar precios y productos</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label>Petrolera (referencia)</label><input type="text" id="prices_petrolera" class="form-control" readonly disabled></div>
                        <?php for ($i=1;$i<=5;$i++): ?>
                        <div class="row mb-3">
                            <div class="col-md-6"><label>Producto <?php echo $i; ?></label><select name="idproducto<?php echo $i; ?>" id="idproducto<?php echo $i; ?>" class="form-select product-select" required><option value="">Seleccionar...</option></select></div>
                            <div class="col-md-6"><label>Precio <?php echo $i; ?></label><input type="text" name="precio<?php echo $i; ?>" id="precio<?php echo $i; ?>" class="form-control" placeholder="Ej: 1234.56" required></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let allUsers = [];
        document.getElementById('userManagerModal').addEventListener('show.bs.modal', function() {
            fetch('get_users.php')
                .then(response => response.json())
                .then(data => { allUsers = data; renderUsersTable(allUsers); })
                .catch(err => console.error(err));
        });
        function renderUsersTable(users) {
            const tbody = document.getElementById('usersTableBody');
            tbody.innerHTML = '';
            users.forEach(u => {
                const row = tbody.insertRow();
                row.insertCell(0).innerText = u.id;
                row.insertCell(1).innerText = u.Nombre;
                row.insertCell(2).innerText = u.Usuario;
                row.insertCell(3).innerText = u.Email;
                row.insertCell(4).innerText = u.DNI;
                row.insertCell(5).innerText = `${u.rol} - ${getRolNombre(u.rol)}`;
                row.insertCell(6).innerText = u.Petrolera || '—';
                row.insertCell(7).innerHTML = u.email_verified == 1 ? '<span class="text-success"><i class="bi bi-check-circle"></i> Sí</span>' : '<span class="text-danger"><i class="bi bi-x-circle"></i> No</span>';
                const actions = row.insertCell(8);
                actions.innerHTML = `<button class="btn btn-sm btn-warning me-1" onclick="editUser(${u.id})"><i class="bi bi-pencil"></i></button>
                                     <button class="btn btn-sm btn-info me-1" onclick="resetUserPassword(${u.id})"><i class="bi bi-envelope"></i></button>
                                     <button class="btn btn-sm btn-danger" onclick="deleteUser(${u.id}, '${u.Nombre}')"><i class="bi bi-trash"></i></button>`;
            });
        }
        function getRolNombre(rol) { const roles = {1:'Admin',2:'Operador',3:'Usuario',4:'Petrolera',5:'Técnico',9:'Soporte'}; return roles[rol] || 'Desconocido'; }
        document.getElementById('userSearchInput').addEventListener('keyup', function() {
            const term = this.value.toLowerCase();
            const filtered = allUsers.filter(u => u.Nombre.toLowerCase().includes(term) || u.Email.toLowerCase().includes(term) || (u.DNI && u.DNI.toLowerCase().includes(term)));
            renderUsersTable(filtered);
        });
        function openCreateUserModal() {
            document.getElementById('user_action').value = 'create';
            document.getElementById('userModalTitle').innerText = 'Crear nuevo usuario';
            document.getElementById('userForm').reset();
            document.getElementById('user_id').value = '';
            document.getElementById('petrolera_div').style.display = 'none';
            document.getElementById('user_rol').value = '1';
            document.getElementById('user_email_verified').checked = false;
            document.getElementById('user_password_fields').style.display = 'none';
            document.getElementById('user_create_password_info').style.display = 'block';
            document.getElementById('user_new_password').value = '';
            document.getElementById('user_confirm_password').value = '';
            new bootstrap.Modal(document.getElementById('editUserModal')).show();
        }
        function editUser(id) {
            const user = allUsers.find(u => u.id == id);
            if (!user) return;
            document.getElementById('user_action').value = 'update';
            document.getElementById('userModalTitle').innerText = 'Editar usuario';
            document.getElementById('user_id').value = user.id;
            document.getElementById('user_nombre').value = user.Nombre;
            document.getElementById('user_usuario').value = user.Usuario;
            document.getElementById('user_email').value = user.Email;
            document.getElementById('user_dni').value = user.DNI;
            document.getElementById('user_rol').value = user.rol;
            document.getElementById('user_email_verified').checked = user.email_verified == 1;
            document.getElementById('user_password_fields').style.display = 'block';
            document.getElementById('user_create_password_info').style.display = 'none';
            document.getElementById('user_new_password').value = '';
            document.getElementById('user_confirm_password').value = '';
            if (user.rol == 4) {
                document.getElementById('petrolera_div').style.display = 'block';
                document.getElementById('user_petrolera').value = user.Petrolera || '';
            } else {
                document.getElementById('petrolera_div').style.display = 'none';
            }
            new bootstrap.Modal(document.getElementById('editUserModal')).show();
        }
        function resetUserPassword(id) {
            if (confirm('¿Enviar correo de restablecimiento?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>"><input type="hidden" name="user_action" value="reset_password"><input type="hidden" name="user_id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
        function deleteUser(id, nombre) {
            if (confirm(`¿Eliminar usuario "${nombre}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="csrf_token" value="<?php echo generarTokenCSRF(); ?>"><input type="hidden" name="user_action" value="delete"><input type="hidden" name="user_id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
        document.getElementById('user_rol').addEventListener('change', function() {
            document.getElementById('petrolera_div').style.display = this.value == '4' ? 'block' : 'none';
        });
        const editSiteModal = document.getElementById('editSiteModal');
        editSiteModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('old_site').value = button.getAttribute('data-old-site');
            document.getElementById('new_site').value = button.getAttribute('data-old-site');
            document.getElementById('edit_nombre').value = button.getAttribute('data-nombre');
            document.getElementById('edit_domicilio').value = button.getAttribute('data-domicilio');
            document.getElementById('edit_localidad').value = button.getAttribute('data-localidad');
            document.getElementById('edit_provincia').value = button.getAttribute('data-provincia');
            document.getElementById('edit_telefono').value = button.getAttribute('data-telefono');
            document.getElementById('edit_email').value = button.getAttribute('data-email');
            document.getElementById('edit_cuit').value = button.getAttribute('data-cuit');
            const petroleraId = button.getAttribute('data-petrolera_id');
            const selectPetrolera = document.getElementById('edit_petrolera_id');
            for (let opt of selectPetrolera.options) if (opt.value == petroleraId) opt.selected = true;
        });
        const editPricesModal = document.getElementById('editPricesModal');
        editPricesModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('prices_site_id').value = button.getAttribute('data-site');
            const petroleraId = button.getAttribute('data-petrolera_id');
            document.getElementById('prices_petrolera').value = `ID: ${petroleraId}`;
            for (let i=1;i<=5;i++) {
                document.getElementById(`precio${i}`).value = button.getAttribute(`data-precio${i}`);
                const idprod = button.getAttribute(`data-idproducto${i}`);
                document.getElementById(`idproducto${i}`).setAttribute('data-selected', idprod);
            }
            const productos = <?php echo json_encode($productos_por_petrolera); ?>;
            const prods = productos[petroleraId] || [];
            for (let i=1;i<=5;i++) {
                const select = document.getElementById(`idproducto${i}`);
                const selectedVal = select.getAttribute('data-selected') || '';
                select.innerHTML = '<option value="">Seleccionar...</option>';
                prods.forEach(prod => {
                    const option = document.createElement('option');
                    option.value = prod.idproducto;
                    option.textContent = prod.nombre;
                    if (prod.idproducto == selectedVal) option.selected = true;
                    select.appendChild(option);
                });
            }
        });
    </script>
    <?php include 'footer.php'; ?>
</body>
</html>