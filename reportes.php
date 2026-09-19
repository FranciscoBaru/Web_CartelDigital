<?php
// reportes.php - Módulo de reportes RETIRADO.
// Dependía de las tablas de historial Registro / Estado485 / EstadoVOX, que eran
// pobladas por un proceso externo y no forman parte de la base PostgreSQL.
// Se deja una página informativa para no romper enlaces existentes.
require_once 'config.php';

if (!esEstacion()) {
    redirigir('login.php');
}
?>
<?php include 'header.php'; ?>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-bar-chart-line"></i> Reportes</h2>
        <a href="dashboard_site.php" class="btn btn-outline-warning"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
    <div class="alert alert-secondary">
        <i class="bi bi-info-circle me-2"></i>
        El módulo de reportes no está disponible en esta versión.
    </div>
</div>
<?php include 'footer.php'; ?>
