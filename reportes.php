<?php
// reportes.php - Reportes para la estación
require_once 'config.php';

if (!esEstacion()) {
    redirigir('login.php');
}

$site_data = obtenerEstacionPorId($_SESSION['site_id']);
$site_num = $site_data['site'];
$petrolera_id = $site_data['petrolera_id'];

$petrolera_nombre = '';
$stmt_pet = $conn->prepare("SELECT nombre FROM Petroleras WHERE id = ?");
$stmt_pet->bind_param("i", $petrolera_id);
$stmt_pet->execute();
$row_pet = $stmt_pet->get_result()->fetch_assoc();
$petrolera_nombre = $row_pet['nombre'] ?? '';
$stmt_pet->close();

$sql_macs = "SELECT DISTINCT MAC FROM Cartel WHERE site = ?";
$stmt = $conn->prepare($sql_macs);
$stmt->bind_param("i", $site_num);
$stmt->execute();
$macs_result = $stmt->get_result();
$macs = [];
while ($row = $macs_result->fetch_assoc()) {
    $macs[] = $row['MAC'];
}
$stmt->close();

$tipo = $_GET['tipo'] ?? 'precios';
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

if (isset($_GET['exportar'])) {
    $export_tipo = $_GET['export_tipo'] ?? $tipo;
    $export_desde = $_GET['export_desde'] ?? $fecha_desde;
    $export_hasta = $_GET['export_hasta'] ?? $fecha_hasta;

    $data = [];
    $headers = [];

    if ($export_tipo == 'precios') {
        $sql = "SELECT r.fecha, r.hora, r.Lama, p.nombre AS producto, r.precio
                FROM Registro r
                INNER JOIN sites s ON r.site = s.site
                LEFT JOIN Productos p ON r.idproducto = p.idproducto AND p.petrolera_id = ?
                WHERE r.site = ? AND r.fecha BETWEEN ? AND ?
                ORDER BY r.fecha DESC, r.hora DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiss", $petrolera_id, $site_num, $export_desde, $export_hasta);
        $stmt->execute();
        $result = $stmt->get_result();
        $headers = ['Fecha', 'Hora', 'Línea', 'Producto', 'Precio'];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                formatearFecha($row['fecha']),
                $row['hora'],
                $row['Lama'],
                $row['producto'] ?? 'Desconocido',
                formatearPrecio($row['precio'])
            ];
        }
        $stmt->close();
    } elseif ($export_tipo == '485') {
        if (empty($macs)) {
            $data = [['No hay carteles']];
            $headers = ['Mensaje'];
        } else {
            $placeholders = implode(',', array_fill(0, count($macs), '?'));
            $sql = "SELECT e.fecha, e.hora, e.Estado
                    FROM Estado485 e
                    WHERE e.MAC IN ($placeholders) AND e.fecha BETWEEN ? AND ?
                    ORDER BY e.fecha DESC, e.hora DESC";
            $stmt = $conn->prepare($sql);
            $types = str_repeat('s', count($macs)) . 'ss';
            $params = array_merge($macs, [$export_desde, $export_hasta]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $headers = ['Fecha', 'Hora', 'Estado'];
            while ($row = $result->fetch_assoc()) {
                $data[] = [formatearFecha($row['fecha']), $row['hora'], $row['Estado'] == 1 ? 'Error comunicación' : 'OK'];
            }
            $stmt->close();
        }
    } elseif ($export_tipo == 'vox') {
        if (empty($macs)) {
            $data = [['No hay carteles']];
            $headers = ['Mensaje'];
        } else {
            $placeholders = implode(',', array_fill(0, count($macs), '?'));
            $sql = "SELECT e.fecha, e.hora, e.Estado
                    FROM EstadoVOX e
                    WHERE e.MAC IN ($placeholders) AND e.fecha BETWEEN ? AND ?
                    ORDER BY e.fecha DESC, e.hora DESC";
            $stmt = $conn->prepare($sql);
            $types = str_repeat('s', count($macs)) . 'ss';
            $params = array_merge($macs, [$export_desde, $export_hasta]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $headers = ['Fecha', 'Hora', 'Estado'];
            while ($row = $result->fetch_assoc()) {
                $data[] = [formatearFecha($row['fecha']), $row['hora'], $row['Estado'] == 1 ? 'Error VOX' : 'OK'];
            }
            $stmt->close();
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $export_tipo . '_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($data as $row) fputcsv($output, $row);
    fclose($output);
    exit;
}
?>
<?php include 'header.php'; ?>
<style>
    .report-table { background-color: #1e1e1e; border-radius: 12px; overflow: hidden; }
    .report-table th { background-color: #121212; color: #ffc107; }
    .btn-export { background-color: #28a745; color: #fff; }
</style>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-bar-chart-line"></i> Reportes</h2>
        <a href="dashboard_site.php" class="btn btn-outline-warning"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?php echo $tipo=='precios'?'active':''; ?>" href="?tipo=precios&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>">Historial de Precios</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $tipo=='485'?'active':''; ?>" href="?tipo=485&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>">Estado 485</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $tipo=='vox'?'active':''; ?>" href="?tipo=vox&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>">Estado VOX</a></li>
    </ul>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="tipo" value="<?php echo $tipo; ?>">
                <div class="col-md-4"><label>Desde</label><input type="date" name="fecha_desde" class="form-control" value="<?php echo $fecha_desde; ?>"></div>
                <div class="col-md-4"><label>Hasta</label><input type="date" name="fecha_hasta" class="form-control" value="<?php echo $fecha_hasta; ?>"></div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100 mt-4">Filtrar</button></div>
            </form>
        </div>
    </div>

    <?php if ($tipo == 'precios'): ?>
        <?php
        $sql = "SELECT r.fecha, r.hora, r.Lama, p.nombre AS producto, r.precio
                FROM Registro r
                LEFT JOIN Productos p ON r.idproducto = p.idproducto AND p.petrolera_id = ?
                WHERE r.site = ? AND r.fecha BETWEEN ? AND ?
                ORDER BY r.fecha DESC, r.hora DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiss", $petrolera_id, $site_num, $fecha_desde, $fecha_hasta);
        $stmt->execute();
        $result = $stmt->get_result();
        ?>
        <div class="table-responsive">
            <table class="table report-table">
                <thead><tr class="text-center"><th>Fecha</th><th>Hora</th><th>Línea</th><th>Producto</th><th>Precio</th></tr></thead>
                <tbody>
                    <?php if ($result->num_rows == 0): ?><tr><td colspan="5" class="text-center">No hay registros</td></tr>
                    <?php else: while ($row = $result->fetch_assoc()): ?>
                    <tr class="text-center">
                        <td><?php echo formatearFecha($row['fecha']); ?></td>
                        <td><?php echo $row['hora']; ?></td>
                        <td><?php echo $row['Lama']; ?></td>
                        <td><?php echo htmlspecialchars($row['producto'] ?? 'Desconocido'); ?></td>
                        <td>$ <?php echo formatearPrecio($row['precio']); ?></td>
                    </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="text-end mt-3"><a href="?tipo=precios&exportar=1&export_tipo=precios&export_desde=<?php echo urlencode($fecha_desde); ?>&export_hasta=<?php echo urlencode($fecha_hasta); ?>" class="btn btn-export"><i class="bi bi-file-excel"></i> Exportar</a></div>
        <?php $stmt->close(); ?>
    <?php elseif ($tipo == '485'): ?>
        <?php if (empty($macs)): ?><div class="alert alert-info">No hay carteles asociados.</div>
        <?php else:
            $placeholders = implode(',', array_fill(0, count($macs), '?'));
            $sql = "SELECT e.fecha, e.hora, e.Estado FROM Estado485 e WHERE e.MAC IN ($placeholders) AND e.fecha BETWEEN ? AND ? ORDER BY e.fecha DESC, e.hora DESC";
            $stmt = $conn->prepare($sql);
            $types = str_repeat('s', count($macs)) . 'ss';
            $params = array_merge($macs, [$fecha_desde, $fecha_hasta]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        ?>
        <div class="table-responsive"><table class="table report-table"><thead><tr class="text-center"><th>Fecha</th><th>Hora</th><th>Estado</th></tr></thead><tbody>
        <?php if ($result->num_rows == 0): ?><tr><td colspan="3" class="text-center">No hay registros</td></tr>
        <?php else: while ($row = $result->fetch_assoc()): ?>
        <tr class="text-center"><td><?php echo formatearFecha($row['fecha']); ?></td><td><?php echo $row['hora']; ?></td><td><?php echo $row['Estado'] == 1 ? '<span class="text-error">Error comunicación</span>' : '<span class="text-ok">OK</span>'; ?></td></tr>
        <?php endwhile; endif; ?>
        </tbody></table></div>
        <div class="text-end mt-3"><a href="?tipo=485&exportar=1&export_tipo=485&export_desde=<?php echo urlencode($fecha_desde); ?>&export_hasta=<?php echo urlencode($fecha_hasta); ?>" class="btn btn-export">Exportar</a></div>
        <?php $stmt->close(); endif; ?>
    <?php elseif ($tipo == 'vox'): ?>
        <?php if (empty($macs)): ?><div class="alert alert-info">No hay carteles asociados.</div>
        <?php else:
            $placeholders = implode(',', array_fill(0, count($macs), '?'));
            $sql = "SELECT e.fecha, e.hora, e.Estado FROM EstadoVOX e WHERE e.MAC IN ($placeholders) AND e.fecha BETWEEN ? AND ? ORDER BY e.fecha DESC, e.hora DESC";
            $stmt = $conn->prepare($sql);
            $types = str_repeat('s', count($macs)) . 'ss';
            $params = array_merge($macs, [$fecha_desde, $fecha_hasta]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        ?>
        <div class="table-responsive"><table class="table report-table"><thead><tr class="text-center"><th>Fecha</th><th>Hora</th><th>Estado</th></tr></thead><tbody>
        <?php if ($result->num_rows == 0): ?><tr><td colspan="3" class="text-center">No hay registros</td></tr>
        <?php else: while ($row = $result->fetch_assoc()): ?>
        <tr class="text-center"><td><?php echo formatearFecha($row['fecha']); ?></td><td><?php echo $row['hora']; ?></td><td><?php echo $row['Estado'] == 1 ? '<span class="text-error">Error VOX</span>' : '<span class="text-ok">OK</span>'; ?></td></tr>
        <?php endwhile; endif; ?>
        </tbody></table></div>
        <div class="text-end mt-3"><a href="?tipo=vox&exportar=1&export_tipo=vox&export_desde=<?php echo urlencode($fecha_desde); ?>&export_hasta=<?php echo urlencode($fecha_hasta); ?>" class="btn btn-export">Exportar</a></div>
        <?php $stmt->close(); endif; ?>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>