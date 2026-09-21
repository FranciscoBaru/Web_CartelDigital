<?php
// dashboard_user_petrolera.php - Panel de petrolera (excluye site 9999)
require_once 'config.php';

if (!esUsuario() || $_SESSION['user_rol'] != 4) {
    redirigir('login.php');
}

$user_petrolera = $_SESSION['user_petrolera'];

// Obtener ID de la petrolera
$stmt = $conn->prepare("SELECT id FROM Petroleras WHERE nombre = ?");
$stmt->bind_param("s", $user_petrolera);
$stmt->execute();
$pet_id = $stmt->get_result()->fetch_assoc()['id'] ?? 0;
$stmt->close();

// Obtener todas las estaciones de la petrolera excluyendo site 9999
$sql_all = "SELECT s.site, s.nombre, s.domicilio, s.localidad, s.provincia, s.telefono, s.email, s.cuit, s.petrolera_id,
               s.Fecha, s.hora,
               c.mac, c.ip_lan, c.est_485 AS estado485, c.est_cont AS estadovox,
               c.price1 AS precio1, c.price2 AS precio2, c.price3 AS precio3, c.price4 AS precio4, c.price5 AS precio5,
               c.linea1 AS lama1, c.linea2 AS lama2, c.linea3 AS lama3, c.linea4 AS lama4, c.linea5 AS lama5,
               c.idproducto1, c.idproducto2, c.idproducto3, c.idproducto4, c.idproducto5,
               to_char(c.updated_at, 'YYYY-MM-DD HH24:MI:SS') as ultima_actualizacion
        FROM sites s
        INNER JOIN Petroleras p ON s.petrolera_id = p.id
        LEFT JOIN (
            SELECT site, MAX(id) as max_id FROM sign_prices GROUP BY site
        ) cm ON s.site = cm.site
        LEFT JOIN sign_prices c ON cm.max_id = c.id
        WHERE p.nombre = ? AND s.site != 9999
        ORDER BY s.site";
$stmt_all = $conn->prepare($sql_all);
$stmt_all->bind_param("s", $user_petrolera);
$stmt_all->execute();
$all_stations = $stmt_all->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_all->close();

$macs = array_filter(db_column($all_stations, 'MAC'));
// Firmware: el módulo IOT (tabla Dispositivos, MySQL) se retiró al apagar MySQL.
// Se deja el firmware como 'N/A'.
$firmware_map = [];
foreach ($all_stations as &$s) {
    $s['firmware'] = $firmware_map[$s['MAC']] ?? 'N/A';
}
unset($s);
unset($s);

// Estados WFT en lote
$estados_wft = obtenerEstadosWFTMultiples($macs);

// Estadísticas (solo donde WFT OK)
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
$filter_wft = $_GET['wft'] ?? '';
$filter_485 = $_GET['estado485'] ?? '';
$filter_vox = $_GET['estadovox'] ?? '';
$search = trim($_GET['search'] ?? '');

$filtered_stations = [];
foreach ($all_stations as $s) {
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

$query_params = array_filter(['wft'=>$filter_wft, 'estado485'=>$filter_485, 'estadovox'=>$filter_vox, 'search'=>$search, 'site'=>$selected_site]);
$refresh_url = '?' . http_build_query($query_params);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30; url=<?php echo $refresh_url; ?>">
    <title><?php echo APP_NAME; ?> - Petrolera</title>
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
        .card.h-100 { height: 100% !important; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="dashboard-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-building me-2"></i> Panel de Petrolera: <?php echo htmlspecialchars($user_petrolera); ?></h2>
            <a href="logout.php" class="btn btn-outline-warning"><i class="bi bi-box-arrow-right me-2"></i> Salir</a>
        </div>

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
                <div class="col-md-3"><label>Estado WFT</label><select name="wft" class="form-select"><option value="">Todos</option><option value="ok" <?php echo $filter_wft=='ok'?'selected':''; ?>>OK</option><option value="error" <?php echo $filter_wft=='error'?'selected':''; ?>>Error</option></select></div>
                <div class="col-md-3"><label>Estado Cartel</label><select name="estado485" class="form-select"><option value="">Todos</option><option value="ok" <?php echo $filter_485=='ok'?'selected':''; ?>>OK</option><option value="error" <?php echo $filter_485=='error'?'selected':''; ?>>Error</option></select></div>
                <div class="col-md-3"><label>Estado VOX</label><select name="estadovox" class="form-select"><option value="">Todos</option><option value="ok" <?php echo $filter_vox=='ok'?'selected':''; ?>>OK</option><option value="error" <?php echo $filter_vox=='error'?'selected':''; ?>>Error</option></select></div>
                <div class="col-md-3"><label>Buscar</label><input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Site, Nombre, CUIT"></div>
                <div class="col-md-12 text-end mt-3"><button type="submit" class="btn btn-warning"><i class="bi bi-funnel"></i> Filtrar</button> <a href="dashboard_user_petrolera.php" class="btn btn-secondary"><i class="bi bi-eraser"></i> Limpiar</a></div>
            </form>
        </div>

        <!-- Detalle estación seleccionada -->
        <?php if ($selected_station && $cartel_activo): 
            $wft_estado = $estados_wft[$selected_station['MAC']] ?? 'Error';
            $telefono_clean = preg_replace('/[^0-9]/', '', $selected_station['telefono']);
            $whatsapp_url = !empty($telefono_clean) ? "https://wa.me/$telefono_clean" : '#';
        ?>
            <div class="row mb-4">
                <div class="col-md-6 d-flex">
                    <div class="card bg-dark w-100 h-100">
                        <div class="card-header text-warning">
                            <h5 class="mb-0"><i class="bi bi-calculator"></i> Precios actuales</h5>
                        </div>
                        <div class="price-list">
                            <?php 
                            $productos_mostrados = 0;
                            for ($i = 1; $i <= 5; $i++):
                                // Nombre del producto = etiqueta de la línea (linea/lama) del cartel.
                                $nombre_producto = trim((string)($cartel_activo['lama'.$i] ?? ''));
                                if ($nombre_producto === '') continue;
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
                        <div class="card-header text-warning">
                            <h5 class="mb-0"><i class="bi bi-building"></i> Estación <?php echo $selected_station['site']; ?> - <?php echo htmlspecialchars($selected_station['nombre']); ?></h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Domicilio:</strong> <?php echo htmlspecialchars($selected_station['domicilio']); ?></p>
                            <p><strong>Localidad:</strong> <?php echo htmlspecialchars($selected_station['localidad']); ?> - <strong>Provincia:</strong> <?php echo htmlspecialchars($selected_station['provincia']); ?></p>
                            <p><strong>Teléfono:</strong> <?php if (!empty($telefono_clean)): ?><a href="<?php echo $whatsapp_url; ?>" target="_blank" class="text-success"><i class="bi bi-whatsapp"></i> <?php echo htmlspecialchars($selected_station['telefono']); ?></a><?php else: ?><?php echo htmlspecialchars($selected_station['telefono']); ?><?php endif; ?></p>
                            <p><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($selected_station['email']); ?>" class="text-warning"><?php echo htmlspecialchars($selected_station['email']); ?></a></p>
                            <p><strong>CUIT:</strong> <?php echo htmlspecialchars($selected_station['cuit']); ?></p>
                            <hr>
                            <p><strong>MAC:</strong> <?php echo htmlspecialchars($selected_station['MAC']); ?></p>
                            <p><strong>IP LAN:</strong> <?php echo htmlspecialchars($selected_station['IP_LAN'] ?: 'No asignada'); ?></p>
                            <p><strong>Firmware:</strong> <?php echo htmlspecialchars($selected_station['firmware']); ?></p>
                            <p><strong>Estado Cartel:</strong> <?php echo ($wft_estado == 'Error' || $selected_station['estado485'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></p>
                            <p><strong>Estado VOX:</strong> <?php echo ($wft_estado == 'Error' || $selected_station['estadovox'] == 1) ? '<span class="text-error">Error</span>' : '<span class="text-ok">OK</span>'; ?></p>
                            <p><strong>Estado WFT:</strong> <?php echo ($wft_estado == 'OK') ? '<span class="text-ok">OK</span>' : '<span class="text-error">Error</span>'; ?></p>
                            <p><strong>Última actualización:</strong> <?php echo formatearFechaHora($selected_station['Fecha'], $selected_station['hora']); ?></p>
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
                        $click_params = array_filter(['site'=>$s['site'], 'wft'=>$filter_wft, 'estado485'=>$filter_485, 'estadovox'=>$filter_vox, 'search'=>$search]);
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
    <?php include 'footer.php'; ?>
</body>
</html>