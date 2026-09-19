<?php
// dashboard_site.php - Panel de la estación (productos dinámicos)
require_once 'config.php';

if (!esEstacion()) {
    redirigir('login.php');
}

$site_data = obtenerEstacionPorId($_SESSION['site_id']);
$petrolera_id = $site_data['petrolera_id'];

// Cartel -> sign_prices. Se aliasan las columnas al nombre que espera el resto
// del código (precio/lama/estado485/estadovox/fecha/hora).
$sql_carteles = "SELECT *,
        price1 AS precio1, price2 AS precio2, price3 AS precio3, price4 AS precio4, price5 AS precio5,
        linea1 AS lama1, linea2 AS lama2, linea3 AS lama3, linea4 AS lama4, linea5 AS lama5,
        est_485 AS estado485, est_cont AS estadovox,
        to_char(updated_at, 'YYYY-MM-DD') AS fecha, to_char(updated_at, 'HH24:MI:SS') AS hora
    FROM sign_prices WHERE site = ? ORDER BY id DESC";
$stmt = $conn->prepare($sql_carteles);
$stmt->bind_param("i", $site_data['site']);
$stmt->execute();
$carteles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($carteles)) {
    // La estación no tiene ningún cartel (sign_prices) asociado. Se usa un
    // placeholder en memoria para renderizar la vista sin insertar filas
    // ficticias (mac es UNIQUE en sign_prices).
    $placeholder = [
        'id' => 0, 'MAC' => '00:00:00:00:00:00', 'IP_LAN' => '',
        'fecha' => null, 'hora' => null, 'estado485' => 0, 'estadovox' => 0,
    ];
    for ($i = 1; $i <= 5; $i++) {
        $placeholder['idproducto' . $i] = 0;
        $placeholder['precio' . $i] = 0;
        $placeholder['lama' . $i] = '';
    }
    $carteles = [$placeholder];
}

$cartel_seleccionado_id = isset($_GET['cartel_id']) ? intval($_GET['cartel_id']) : ($carteles[0]['id'] ?? 0);
$cartel_activo = null;
foreach ($carteles as $c) {
    if ($c['id'] == $cartel_seleccionado_id) {
        $cartel_activo = $c;
        break;
    }
}
if (!$cartel_activo && !empty($carteles)) {
    $cartel_activo = $carteles[0];
}

// Optimización: obtener estado WFT de una sola vez para todos los carteles
$macs = db_column($carteles, 'MAC');
$estados_wft = obtenerEstadosWFTMultiples($macs);
$comunicacion = $estados_wft[$cartel_activo['MAC']] ?? 'Error';

$refresh_url = "?cartel_id=" . $cartel_activo['id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30; url=<?php echo $refresh_url; ?>">
    <title><?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        main.container { max-width: 100% !important; padding-left: 10% !important; padding-right: 10% !important; }
        .dashboard-container { max-width: 1480px; margin: 0 auto; padding: 0 20px; }
        .price-list { background-color: #1e1e1e; border-radius: 12px; overflow: hidden; }
        .price-item { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid #444; }
        .price-item:last-child { border-bottom: none; }
        .product-name { font-size: 1.1rem; font-weight: 600; color: #ffc107; }
        .product-price { font-size: 1.3rem; font-weight: 700; color: #fff; font-family: monospace; }
        .text-ok { color: #28a745 !important; font-weight: 600; }
        .text-error { color: #dc3545 !important; font-weight: 600; }
        .cartel-info-card { background-color: #1e1e1e; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .cartel-info-label { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; color: #ffc107; margin-bottom: 5px; }
        .cartel-info-value { font-size: 1rem; font-weight: 500; color: #fff; }
        @media (max-width: 768px) { .product-price { font-size: 1rem; } .product-name { font-size: 0.9rem; } }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="dashboard-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-building me-2"></i> Panel de Estación</h2>
            <div>
                <a href="usuario_eess.php" class="btn btn-outline-warning me-2"><i class="bi bi-people me-2"></i> Gestión de Usuarios</a>
                <a href="perfil_estacion.php" class="btn btn-outline-warning"><i class="bi bi-person-circle me-2"></i> Mi Perfil</a>
            </div>
        </div>

        <!-- Precios actuales -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-calculator me-2"></i> Precios actuales</h5></div>
            <div class="card-body p-0">
                <div class="price-list">
                    <?php 
                    $productos_mostrados = 0;
                    for ($i = 1; $i <= 5; $i++):
                        $idproducto = $cartel_activo['idproducto'.$i] ?? null;
                        if (empty($idproducto)) continue;
                        
                        $nombre_producto = obtenerNombreProducto($petrolera_id, $idproducto);
                        if (empty($nombre_producto)) continue;
                        
                        $precio = $cartel_activo['precio'.$i] ?? 0;
                        $precio_formateado = formatearPrecio($precio);
                        if ($precio == 0) $precio_formateado = '000,0';
                        $productos_mostrados++;
                    ?>
                    <div class="price-item">
                        <span class="product-name"><?php echo htmlspecialchars($nombre_producto); ?></span>
                        <span class="product-price">$ <?php echo $precio_formateado; ?></span>
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

        <!-- Selector de carteles -->
        <?php if (count($carteles) > 1): ?>
        <div class="cartel-selector bg-dark p-3 rounded mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div><i class="bi bi-tv me-2"></i><strong>Carteles asociados:</strong></div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($carteles as $c): ?>
                        <a href="?cartel_id=<?php echo $c['id']; ?>" class="btn <?php echo ($cartel_activo['id'] == $c['id']) ? 'btn-warning' : 'btn-outline-secondary'; ?> btn-sm">
                            <i class="bi bi-upc-scan"></i> <?php echo substr($c['MAC'], -8); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Datos del cartel -->
        <div class="cartel-info-card">
            <h5 class="mb-3"><i class="bi bi-tv me-2"></i> Datos del cartel</h5>
            <div class="row">
                <div class="col-md-3 mb-2"><div class="cartel-info-label">MAC</div><div class="cartel-info-value"><?php echo htmlspecialchars($cartel_activo['MAC']); ?></div></div>
                <div class="col-md-3 mb-2"><div class="cartel-info-label">IP LAN</div><div class="cartel-info-value"><?php echo htmlspecialchars($cartel_activo['IP_LAN'] ?: 'No asignada'); ?></div></div>
                <div class="col-md-3 mb-2"><div class="cartel-info-label">Última actualización</div><div class="cartel-info-value"><?php echo formatearFechaHora($cartel_activo['fecha'], $cartel_activo['hora']); ?></div></div>
                <div class="col-md-3 mb-2"><div class="cartel-info-label">Estado Cartel</div><div class="cartel-info-value"><?php echo ($comunicacion == 'Error' || $cartel_activo['estado485'] == 1) ? '<span class="text-error">Error comunicación</span>' : '<span class="text-ok">OK</span>'; ?></div></div>
                <div class="col-md-3 mb-2"><div class="cartel-info-label">Estado VOX</div><div class="cartel-info-value"><?php echo ($comunicacion == 'Error' || $cartel_activo['estadovox'] == 1) ? '<span class="text-error">Error conexión</span>' : '<span class="text-ok">OK</span>'; ?></div></div>
                <div class="col-md-3 mb-2"><div class="cartel-info-label">Estado WFT</div><div class="cartel-info-value"><?php echo ($comunicacion == 'OK') ? '<span class="text-ok">OK</span>' : '<span class="text-error">Error</span>'; ?></div></div>
            </div>
        </div>

        <!-- Tabla de todos los carteles -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-tv me-2"></i> Carteles asociados</h5></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-hover">
                        <thead><tr class="text-center"><th>MAC</th><th>IP LAN</th><th>Estado Cartel</th><th>Estado VOX</th><th>Estado WFT</th><th>Última actualización</th></tr></thead>
                        <tbody>
                            <?php foreach ($carteles as $c): 
                                $estado_wft = $estados_wft[$c['MAC']] ?? 'Error';
                            ?>
                            <tr class="text-center">
                                <td><code><?php echo htmlspecialchars($c['MAC']); ?></code></td>
                                <td><?php echo htmlspecialchars($c['IP_LAN'] ?: 'No asignada'); ?></td>
                                <td><?php echo ($estado_wft == 'Error' || $c['estado485'] == 1) ? '<span class="text-error">Error comunicación</span>' : '<span class="text-ok">OK</span>'; ?></td>
                                <td><?php echo ($estado_wft == 'Error' || $c['estadovox'] == 1) ? '<span class="text-error">Error VOX</span>' : '<span class="text-ok">OK</span>'; ?></td>
                                <td><?php echo ($estado_wft == 'OK') ? '<span class="text-ok">OK</span>' : '<span class="text-error">Error</span>'; ?></td>
                                <td><?php echo formatearFechaHora($c['fecha'], $c['hora']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>