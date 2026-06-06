<?php
// index.php - Punto de entrada
require_once 'config.php';

if (estaLogueado()) {
    if (esUsuario()) {
        redirigir('dashboard_user.php');
    } elseif (esEstacion()) {
        redirigir('dashboard_site.php');
    }
} else {
    redirigir('login.php');
}
?>