<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Estilos globales mínimos (opcional) */
        body { background-color: #121212; color: #fff; }
        .navbar { background-color: #1a1a1a !important; }
        .navbar-brand, .nav-link { color: #ffc107 !important; }
        .nav-link:hover { color: #e6ac00 !important; }
        footer { background-color: #1a1a1a; margin-top: 3rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-signpost-split"></i> <?php echo htmlspecialchars(APP_NAME); ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if (estaLogueado()): ?>
                        <?php if (esUsuario()): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="dashboard_user.php">
                                    <i class="bi bi-person"></i> <?php echo htmlspecialchars($_SESSION['user_nombre']); ?>
                                </a>
                            </li>
                        <?php elseif (esEstacion()): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="dashboard_site.php">
                                    <i class="bi bi-building"></i> 
                                    <?php echo htmlspecialchars($_SESSION['site_numero'] . ' - ' . $_SESSION['site_nombre']); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">
                                <i class="bi bi-box-arrow-right"></i> Salir
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">
                                <i class="bi bi-door-open"></i> Ingresar
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="register_user.php">
                                <i class="bi bi-person-plus"></i> Registrar Usuario
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="register_cartel.php">
                                <i class="bi bi-tv"></i> Registrar Cartel
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <main class="container my-4">