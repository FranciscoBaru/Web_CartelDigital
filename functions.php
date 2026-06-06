<?php
// functions.php - Funciones reutilizables del sistema

// ==================== FUNCIONES DE FORMATEO ====================
function formatearPrecio($precio) {
    if (is_numeric($precio) && $precio == floor($precio)) {
        return number_format($precio, 0, '', '');
    } else {
        return number_format($precio, 1, '.', '');
    }
}

function formatearFecha($fecha) {
    return date('d/m/Y', strtotime($fecha));
}

function formatearFechaHora($fecha, $hora) {
    if (empty($fecha) || empty($hora)) return 'No disponible';
    $partes = explode('-', $fecha);
    if (count($partes) == 3) {
        $fecha_formateada = $partes[2] . '/' . $partes[1] . '/' . $partes[0];
    } else {
        $fecha_formateada = $fecha;
    }
    return $fecha_formateada . ' ' . $hora;
}

// ==================== FUNCIONES DE BASE DE DATOS ====================
function obtenerPetroleras() {
    global $conn;
    $result = $conn->query("SELECT id, nombre FROM Petroleras ORDER BY nombre");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function obtenerProvincias() {
    global $conn;
    $result = $conn->query("SELECT id, nombre FROM Provincias ORDER BY nombre");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function obtenerEstacionPorId($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function obtenerCartelPorSite($site_numero) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM Cartel WHERE site = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $site_numero);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function obtenerNombreProducto($petrolera_id, $idproducto) {
    global $conn;
    if (empty($idproducto) || $idproducto == 0) return '';
    $stmt = $conn->prepare("SELECT nombre FROM Productos WHERE petrolera_id = ? AND idproducto = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $petrolera_id, $idproducto);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['nombre'];
        }
        $stmt->close();
    }
    $nombres_defecto = [
        1 => 'SUPER', 2 => 'NAFTA PREMIUM', 3 => 'DIESEL 500',
        4 => 'INFINIA NAFTA', 5 => 'GNC', 6 => 'INFINIA DIESEL',
        7 => 'ULTRA DIESEL', 8 => 'DIESEL 500'
    ];
    return $nombres_defecto[$idproducto] ?? "Producto #$idproducto";
}

function obtenerProductosPorPetrolera($petrolera_id) {
    global $conn;
    $productos = [];
    $stmt = $conn->prepare("SELECT idproducto, nombre FROM Productos WHERE petrolera_id = ? ORDER BY idproducto");
    if ($stmt) {
        $stmt->bind_param("i", $petrolera_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $productos[$row['idproducto']] = $row['nombre'];
        }
        $stmt->close();
    }
    if (empty($productos)) {
        $productos = [
            1 => 'SUPER', 2 => 'NAFTA PREMIUM', 3 => 'DIESEL 500',
            4 => 'INFINIA NAFTA', 5 => 'GNC', 6 => 'INFINIA DIESEL',
            7 => 'ULTRA DIESEL', 8 => 'DIESEL 500'
        ];
    }
    return $productos;
}

// ==================== ESTADO WFT OPTIMIZADO ====================
function obtenerEstadoWFT($mac) {
    global $conn;
    if (empty($mac)) return 'Error';
    $stmt = $conn->prepare("SELECT CONCAT(fecha, ' ', hora) as last_time FROM poleo WHERE MAC = ? ORDER BY fecha DESC, hora DESC LIMIT 1");
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if (!$row) return 'Error (sin registro)';
    $last_time = $row['last_time'];
    $last_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $last_time);
    if (!$last_datetime) $last_datetime = DateTime::createFromFormat('d/m/Y H:i:s', $last_time);
    if (!$last_datetime) return 'Error (formato fecha)';
    $now = new DateTime();
    $diff = $now->getTimestamp() - $last_datetime->getTimestamp();
    return ($diff > 15 * 60) ? 'Error' : 'OK';
}

function obtenerEstadosWFTMultiples($macs) {
    global $conn;
    if (empty($macs)) return [];
    $placeholders = implode(',', array_fill(0, count($macs), '?'));
    $sql = "SELECT MAC, MAX(CONCAT(fecha, ' ', hora)) as last_time 
            FROM poleo 
            WHERE MAC IN ($placeholders) 
            GROUP BY MAC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $types = str_repeat('s', count($macs));
    $stmt->bind_param($types, ...$macs);
    $stmt->execute();
    $result = $stmt->get_result();
    $estados = [];
    $now = new DateTime();
    while ($row = $result->fetch_assoc()) {
        $last_time = $row['last_time'];
        if (!$last_time) {
            $estados[$row['MAC']] = 'Error';
            continue;
        }
        $last_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $last_time);
        if (!$last_datetime) $last_datetime = DateTime::createFromFormat('d/m/Y H:i:s', $last_time);
        if (!$last_datetime) {
            $estados[$row['MAC']] = 'Error';
            continue;
        }
        $diff = $now->getTimestamp() - $last_datetime->getTimestamp();
        $estados[$row['MAC']] = ($diff > 15 * 60) ? 'Error' : 'OK';
    }
    $stmt->close();
    foreach ($macs as $mac) {
        if (!isset($estados[$mac])) $estados[$mac] = 'Error';
    }
    return $estados;
}

// ==================== FUNCIONES DE CORREO ====================
function registrarErrorCorreo($mensaje, $mensaje_publico = null) {
    $GLOBALS['ultimo_error_correo'] = $mensaje_publico ?: $mensaje;
    error_log($mensaje);
}

function obtenerUltimoErrorCorreo() {
    return $GLOBALS['ultimo_error_correo'] ?? '';
}

function buscarArchivoSinDistinguirMayusculas($directory, $filename) {
    if (!is_dir($directory)) {
        return null;
    }

    $files = scandir($directory);
    if ($files === false) {
        return null;
    }

    foreach ($files as $file) {
        if (strcasecmp($file, $filename) === 0) {
            return $directory . '/' . $file;
        }
    }

    return null;
}

function cargarPHPMailer() {
    static $phpmailer_loaded = null;

    if ($phpmailer_loaded === true) {
        return true;
    }

    if (
        class_exists('PHPMailer\PHPMailer\PHPMailer')
        && class_exists('PHPMailer\PHPMailer\SMTP')
        && class_exists('PHPMailer\PHPMailer\Exception')
    ) {
        $phpmailer_loaded = true;
        return true;
    }

    $missing_paths = [];
    $autoload_path = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
        if (
            class_exists('PHPMailer\PHPMailer\PHPMailer')
            && class_exists('PHPMailer\PHPMailer\SMTP')
            && class_exists('PHPMailer\PHPMailer\Exception')
        ) {
            $phpmailer_loaded = true;
            return true;
        }
    } else {
        $missing_paths[] = $autoload_path;
    }

    $phpmailer_directories = [
        __DIR__ . '/vendor/src',
        __DIR__ . '/vendor/phpmailer/phpmailer/src',
        __DIR__ . '/vendor/PHPMailer/src',
        __DIR__ . '/PHPMailer/src',
        __DIR__ . '/PHPMailer-master/src',
        __DIR__ . '/phpmailer/src',
    ];

    foreach ($phpmailer_directories as $phpmailer_directory) {
        $phpmailer_path = buscarArchivoSinDistinguirMayusculas($phpmailer_directory, 'PHPMailer.php');
        $smtp_path = buscarArchivoSinDistinguirMayusculas($phpmailer_directory, 'SMTP.php');
        $exception_path = buscarArchivoSinDistinguirMayusculas($phpmailer_directory, 'Exception.php');
        $required_paths = [
            $exception_path ?: $phpmailer_directory . '/Exception.php',
            $phpmailer_path ?: $phpmailer_directory . '/PHPMailer.php',
            $smtp_path ?: $phpmailer_directory . '/SMTP.php',
        ];

        $missing_directory_paths = array_filter($required_paths, function ($path) {
            return !file_exists($path);
        });

        if (empty($missing_directory_paths)) {
            require_once $exception_path;
            require_once $phpmailer_path;
            require_once $smtp_path;
            $phpmailer_loaded = true;
            return true;
        }

        $missing_paths = array_merge($missing_paths, $missing_directory_paths);
    }

    registrarErrorCorreo(
        "ERROR CRÍTICO: PHPMailer no encontrado. Rutas faltantes: " . implode(', ', $missing_paths),
        "El servicio de correo no está disponible porque PHPMailer no está instalado en el servidor."
    );
    $phpmailer_loaded = false;
    return false;
}

function enviarEmailVerificacionUsuario($email, $token, $nombre) {
    if (!cargarPHPMailer()) {
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
        $mail->addAddress($email);
        $mail->isHTML(false);
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $script_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $link = $base_url . $script_path . "/verify_user.php?token=$token&email=" . urlencode($email);
        $mail->Subject = 'Verificación de correo - Registro de Usuario';
        $mail->Body    = "Hola $nombre,\n\nGracias por registrarte. Para activar tu cuenta, haz clic en:\n$link\n\nSi no solicitaste esto, ignora el mensaje.\n\nSaludos.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error enviando email a $email: " . $mail->ErrorInfo);
        return false;
    }
}

function enviarEmailVerificacionRegistroCartel($email, $token, $mac) {
    if (!cargarPHPMailer()) {
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
        $mail->addAddress($email);
        $mail->isHTML(false);
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $script_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $link = $base_url . $script_path . "/verify_email.php?token=$token&mac=$mac";
        $mail->Subject = 'Verificación de correo - Registro de Cartel';
        $mail->Body    = "Estimado usuario,\n\nPara completar el registro, haga clic en:\n$link\n\nSi no solicitó esto, ignore el mensaje.\n\nSaludos.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error enviando email: " . $mail->ErrorInfo);
        return false;
    }
}

function enviarCredencialesUsuario($email, $nombre, $usuario, $password) {
    if (!cargarPHPMailer()) {
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
        $mail->addAddress($email);
        $mail->isHTML(false);
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $script_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $login_link = $base_url . $script_path . "/login.php";
        $mail->Subject = 'Credenciales de acceso - Sistema de Carteles';
        $mail->Body    = "Hola $nombre,\n\nSe ha creado una cuenta para usted.\nUsuario: $usuario\nContraseña temporal: $password\n\nPor favor, inicie sesión en $login_link y cambie su contraseña.\n\nSaludos.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error enviando credenciales: " . $mail->ErrorInfo);
        return false;
    }
}

function enviarEmailRecuperacionUsuario($email, $nombre) {
    global $conn_clientes;
    // Rate limiting
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn_clientes->prepare("DELETE FROM password_reset_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute();
    $stmt->close();
    
    $stmt = $conn_clientes->prepare("SELECT COUNT(*) as attempts FROM password_reset_attempts WHERE (email = ? OR ip = ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if ($row['attempts'] >= 3) {
        error_log("Rate limit excedido para recuperación: $email / $ip");
        return false;
    }
    
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt = $conn_clientes->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $email, $token, $expires);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $stmt->close();
    
    $stmt = $conn_clientes->prepare("INSERT INTO password_reset_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $stmt->close();
    
    if (!cargarPHPMailer()) {
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
        $mail->addAddress($email);
        $mail->isHTML(false);
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $script_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $reset_link = $base_url . $script_path . "/reset_password_user.php?token=$token";
        $mail->Subject = 'Restablecimiento de contraseña';
        $mail->Body    = "Hola $nombre,\n\nHaga clic en el siguiente enlace para restablecer su contraseña (válido por 1 hora):\n$reset_link\n\nSi no solicitó este cambio, ignore este mensaje.\n\nSaludos.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error enviando correo de recuperación: " . $mail->ErrorInfo);
        return false;
    }
}

function enviarEmailRecuperacionEstacion($site_numero, $email, $nombre) {
    global $conn;
    // Rate limiting
    $ip = $_SERVER['REMOTE_ADDR'];

    if (!cargarPHPMailer()) {
        $stmt = $conn->prepare("DELETE FROM password_reset_attempts WHERE email = ? OR ip = ?");
        if ($stmt) {
            $stmt->bind_param("ss", $email, $ip);
            $stmt->execute();
            $stmt->close();
        }
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM password_reset_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    if ($stmt) {
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("No se pudo limpiar rate limit de estaciones: " . $conn->error);
    }

    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM password_reset_attempts WHERE (email = ? OR ip = ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    if ($stmt) {
        $stmt->bind_param("ss", $email, $ip);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        if (($row['attempts'] ?? 0) >= 3) {
            registrarErrorCorreo("Rate limit excedido para recuperación de estación: $email / $ip");
            return false;
        }
    } else {
        error_log("No se pudo consultar rate limit de estaciones: " . $conn->error);
    }
    
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt = $conn->prepare("DELETE FROM password_resets_sites WHERE site = ? OR email = ? OR expires_at < NOW()");
    if (!$stmt) {
        registrarErrorCorreo("No se pudo preparar limpieza de tokens de estación: " . $conn->error);
        return false;
    }
    $stmt->bind_param("is", $site_numero, $email);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO password_resets_sites (site, email, token, expires_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        registrarErrorCorreo("No se pudo preparar token de recuperación de estación: " . $conn->error);
        return false;
    }
    $stmt->bind_param("isss", $site_numero, $email, $token, $expires);
    if (!$stmt->execute()) {
        registrarErrorCorreo("No se pudo guardar token de recuperación de estación: " . $stmt->error);
        $stmt->close();
        return false;
    }
    $stmt->close();
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
        $mail->addAddress($email);
        $mail->isHTML(false);
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $script_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $reset_link = $base_url . $script_path . "/reset_password.php?token=$token";
        $mail->Subject = 'Restablecimiento de contraseña - Estación';
        $mail->Body    = "Estimado responsable de la estación $nombre (APIES $site_numero),\n\nHaga clic en el siguiente enlace para restablecer la contraseña (válido por 1 hora):\n$reset_link\n\nSi no solicitó este cambio, ignore este mensaje.\n\nSaludos.";
        $mail->send();
        $stmt = $conn->prepare("INSERT INTO password_reset_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("ss", $email, $ip);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log("No se pudo registrar intento de recuperación de estación: " . $conn->error);
        }
        return true;
    } catch (Exception $e) {
        registrarErrorCorreo("Error enviando correo de recuperación para estación $site_numero a $email: " . $mail->ErrorInfo);
        return false;
    }
}

// ==================== FUNCIONES DE SANITIZACIÓN ====================
function sanitizarTexto($texto) {
    $texto = str_replace(['"', "'", ',', '`'], ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    return trim($texto);
}

// ==================== FUNCIÓN DE LOG UNIFICADA ====================
function registrarLog($tabla, $campo, $valor_anterior, $valor_nuevo, $usuario = null) {
    global $conn_clientes;
    if ($usuario === null) {
        $usuario = $_SESSION['user_nombre'] ?? $_SESSION['site_nombre'] ?? 'sistema';
    }
    $stmt = $conn_clientes->prepare("INSERT INTO log_cambios (usuario, tabla, campo, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sssss", $usuario, $tabla, $campo, $valor_anterior, $valor_nuevo);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Error preparando inserción en log_cambios: " . $conn_clientes->error);
    }
}
?>