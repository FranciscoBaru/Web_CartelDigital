<?php
// user_stations.php - Lista de estaciones asociadas al usuario común
require_once 'config.php';

if (!esUsuario() || $_SESSION['user_rol'] != 1) {
    redirigir('login.php');
}

$user_dni = $_SESSION['user_dni'];
$user_email = '';

$stmt = $conn_clientes->prepare("SELECT DNI, email FROM Usuarios WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
if ($user_data = $stmt->get_result()->fetch_assoc()) {
    $user_dni = $user_data['DNI'] ?: $user_dni;
    $user_email = $user_data['email'] ?? '';
}
$stmt->close();

$user_dni_normalizado = preg_replace('/\D+/', '', (string) $user_dni);
$apies_asociadas = [];

$stmt = $conn_clientes->prepare("SELECT DISTINCT apies FROM Relaciones WHERE DNI = ? OR REPLACE(REPLACE(DNI, '-', ''), '.', '') = ?");
$stmt->bind_param("ss", $user_dni, $user_dni_normalizado);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $apies_asociadas[] = (int) $row['apies'];
}
$stmt->close();

$where_parts = [];
$params = [];
$types = '';

if (!empty($apies_asociadas)) {
    $placeholders = implode(',', array_fill(0, count($apies_asociadas), '?'));
    $where_parts[] = "s.site IN ($placeholders)";
    foreach ($apies_asociadas as $apies) {
        $params[] = $apies;
        $types .= 'i';
    }
}

if (!empty($user_email)) {
    $where_parts[] = "LOWER(s.email) = LOWER(?)";
    $params[] = $user_email;
    $types .= 's';
}

if (!empty($user_dni_normalizado)) {
    $where_parts[] = "REPLACE(REPLACE(s.cuit, '-', ''), '.', '') = ?";
    $params[] = $user_dni_normalizado;
    $types .= 's';
}

$stations = [];
if (!empty($where_parts)) {
    $sql = "SELECT s.site as apies, s.nombre, s.petrolera_id, s.localidad, s.provincia,
                   (SELECT c.estado485 FROM Cartel c WHERE c.site = s.site ORDER BY c.id DESC LIMIT 1) as estado485,
                   (SELECT c.estadovox FROM Cartel c WHERE c.site = s.site ORDER BY c.id DESC LIMIT 1) as estadovox,
                   (SELECT CONCAT(c.fecha, ' ', c.hora) FROM Cartel c WHERE c.site = s.site ORDER BY c.id DESC LIMIT 1) as ultima_actualizacion,
                   (SELECT c.MAC FROM Cartel c WHERE c.site = s.site ORDER BY c.id DESC LIMIT 1) as mac
            FROM sites s
            WHERE " . implode(' OR ', $where_parts) . "
            ORDER BY s.nombre";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$selected_site = isset($_GET['site']) ? intval($_GET['site']) : (count($stations) > 0 ? $stations[0]['apies'] : 0);
$selected_station = null;
foreach ($stations as $s) {
    if ($s['apies'] == $selected_site) {
        $selected_station = $s;
        break;
    }
}

$cartel_activo = null;
if ($selected_station) {
    $stmt = $conn->prepare("SELECT * FROM Cartel WHERE site = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $selected_site);
    $stmt->execute();
    $cartel_activo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$refresh_url = "?site=" . $selected_site;
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
    <style>
        .stations-table { background-color: #1e1e1e; border-radius: 12px; width: 100%; white-space: nowrap; }
        .stations-table th { background-color: #121212; color: #ffc107; border-bottom: 2px solid #ffc107; }
        .stations-table tr { cursor: pointer; transition: background-color 0.2s; }
        .stations-table tr:hover { background-color: #2a2a2a; }
        .stations-table tr.active { background-color: #2a2a2a; border-left: 3px solid #ffc107; }
        .text-ok { color: #28a745 !important; font-weight: 600; }
        .text-error { color: #dc3545 !important; font-weight: 600; }
        .price-list { background-color: #1e1e1e; border-radius: 12px; overflow: hidden; }
        .price-item { display: flex; justify-content: space-between; padding: 15px 20px; border-bottom: 1px solid #444; }
        .product-name { color: #ffc107; font-weight: 600; }
        .product-price { font-family: monospace; font-weight: 700; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-building me-2"></i> Mis Estaciones</h2>
            <a href="dashboard_user.php" class="btn btn-outline-warning"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>

        <?php if (count($stations) == 0): ?>
            <div class="alert alert-info">No tiene estaciones asociadas.</div>
        <?php else: ?>
            <?php if ($selected_station && $cartel_activo): ?>
                <div class="card mb-4 bg-dark">
                    <div class="card-header text-warning"><h5>Estación <?php echo $selected_station['apies']; ?> - <?php echo htmlspecialchars($selected_station['nombre']); ?></h5></div>
                    <div class="price-list">
                        <?php
                        $productos_mostrados = 0;
                        for ($i = 1; $i <= 5; $i++):
                            $idproducto = (int) ($cartel_activo['idproducto'.$i] ?? 0);
                            if ($idproducto === 0) continue;

                            $nombre_producto = obtenerNombreProducto($selected_station['petrolera_id'], $idproducto);
                            if (empty($nombre_producto)) continue;

                            $productos_mostrados++;
                        ?>
                        <div class="price-item">
                            <span class="product-name"><?php echo htmlspecialchars($nombre_producto); ?></span>
                            <span class="product-price">$ <?php echo formatearPrecio($cartel_activo['precio'.$i] ?? 0); ?></span>
                        </div>
                        <?php endfor; ?>
                        <?php if ($productos_mostrados == 0): ?>
                            <div class="price-item text-center text-muted">
                                <span>No hay productos configurados para este cartel</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <p><strong>MAC:</strong> <?php echo htmlspecialchars($cartel_activo['MAC']); ?></p>
                        <p><strong>IP LAN:</strong> <?php echo htmlspecialchars($cartel_activo['IP_LAN'] ?: 'No asignada'); ?></p>
                        <p><strong>Estado Cartel:</strong> <?php echo ($cartel_activo['estado485']==1)?'<span class="text-error">Error</span>':'<span class="text-ok">OK</span>'; ?></p>
                        <p><strong>Estado VOX:</strong> <?php echo ($cartel_activo['estadovox']==1)?'<span class="text-error">Error</span>':'<span class="text-ok">OK</span>'; ?></p>
                        <p><strong>Estado WFT:</strong> <?php $wft = obtenerEstadoWFT($cartel_activo['MAC']); echo ($wft=='OK')?'<span class="text-ok">OK</span>':'<span class="text-error">Error</span>'; ?></p>
                        <p><strong>Última actualización:</strong> <?php echo formatearFechaHora($cartel_activo['fecha'], $cartel_activo['hora']); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table stations-table">
                    <thead><tr class="text-center"><th>APIES</th><th>Nombre</th><th>Estado Cartel</th><th>Estado VOX</th><th>Estado WFT</th><th>Últ. actualización</th></tr></thead>
                    <tbody>
                        <?php foreach ($stations as $s): 
                            $wft = obtenerEstadoWFT($s['mac']);
                        ?>
                        <tr class="text-center <?php echo ($selected_site == $s['apies']) ? 'active' : ''; ?>" onclick="window.location.href='?site=<?php echo $s['apies']; ?>'">
                            <td><?php echo $s['apies']; ?></td>
                            <td><?php echo htmlspecialchars($s['nombre']); ?></td>
                            <td><?php echo ($s['estado485']==1)?'<span class="text-error">Error</span>':'<span class="text-ok">OK</span>'; ?></td>
                            <td><?php echo ($s['estadovox']==1)?'<span class="text-error">Error</span>':'<span class="text-ok">OK</span>'; ?></td>
                            <td><?php echo ($wft=='OK')?'<span class="text-ok">OK</span>':'<span class="text-error">Error</span>'; ?></td>
                            <td><?php echo $s['ultima_actualizacion'] ?? 'No disponible'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>