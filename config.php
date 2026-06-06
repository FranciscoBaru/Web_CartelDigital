<?php
// config.php - Configuración central del sistema (con PHPMailer, seguridad y funciones comunes)
require_once __DIR__ . '/credenciales.php';
require_once __DIR__ . '/functions.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

error_reporting(E_ALL);
// Iniciar sesión con configuración segura
if (session_status() === PHP_SESSION_NONE) {
    // Configurar opciones de sesión seguras (solo si no se han definido antes)
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if (!defined('APP_IS_HTTPS')) {
        define('APP_IS_HTTPS', $is_https);
    }
    ini_set('session.cookie_secure', $is_https ? '1' : '0'); // solo HTTPS en producción
    session_start();
}

// Configuración de errores (en producción, deshabilitar display y activar log)
define('ENVIRONMENT', 'production'); // Cambiar a 'development' para depuración
if (ENVIRONMENT === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// ==================== CONEXIONES A BASE DE DATOS ====================
// Base de datos principal (CARTELES)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error conexión CARTELES: " . $conn->connect_error);
    die("Error de conexión a la base de datos. Contacte al administrador.");
}
$conn->set_charset("utf8mb4");

// Base de datos CLIENTES
$conn_clientes = new mysqli(CLIENTES_DB_HOST, CLIENTES_DB_USER, CLIENTES_DB_PASS, CLIENTES_DB_NAME);
if ($conn_clientes->connect_error) {
    error_log("Error conexión CLIENTES: " . $conn_clientes->connect_error);
    die("Error de conexión a la base de datos de clientes. Contacte al administrador.");
}
$conn_clientes->set_charset("utf8mb4");

// PHPMailer se carga bajo demanda desde functions.php al enviar correos.

// ==================== INICIALIZACIÓN DE TABLAS (CARTELES) ====================
function crearTablasSistema() {
    global $conn, $conn_clientes;
    
    // Tabla Productos
    $conn->query("CREATE TABLE IF NOT EXISTS Productos (
        idproducto INT NOT NULL,
        petrolera_id INT NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        PRIMARY KEY (idproducto, petrolera_id),
        FOREIGN KEY (petrolera_id) REFERENCES Petroleras(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Tabla password_resets (usuarios CLIENTES)
    $conn_clientes->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Tabla password_resets_sites (estaciones)
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets_sites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_site (site)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Tabla para rate limiting de recuperación de contraseña
    $conn_clientes->query("CREATE TABLE IF NOT EXISTS password_reset_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        attempted_at DATETIME NOT NULL,
        INDEX idx_email_ip (email, ip),
        INDEX idx_attempted (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS password_reset_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        attempted_at DATETIME NOT NULL,
        INDEX idx_email_ip (email, ip),
        INDEX idx_attempted (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
crearTablasSistema();

// Cargar productos por defecto si es necesario
function cargarProductosPorDefecto() {
    global $conn;
    $result = $conn->query("SELECT COUNT(*) as total FROM Productos");
    if ($result && ($row = $result->fetch_assoc()) && $row['total'] == 0) {
        $productos_reales = [1=>'SUPER',2=>'NAFTA PREMIUM',3=>'DIESEL 500',4=>'INFINIA NAFTA',5=>'GNC',6=>'INFINIA DIESEL',7=>'ULTRA DIESEL',8=>'DIESEL 500'];
        $petroleras = obtenerPetroleras();
        foreach ($petroleras as $pet) {
            $pet_id = $pet['id'];
            foreach ($productos_reales as $idprod => $nombre) {
                $stmt = $conn->prepare("INSERT IGNORE INTO Productos (idproducto, petrolera_id, nombre) VALUES (?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("iis", $idprod, $pet_id, $nombre);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}
cargarProductosPorDefecto();

// ==================== FUNCIONES DE AUTENTICACIÓN MEJORADAS ====================
function autenticarUsuario($identificador, $password) {
    global $conn_clientes;
    // Buscar por Usuario, DNI o Email
    $sql = "SELECT id, Nombre, Usuario, Email, Password, rol, Petrolera, email_verified, DNI 
            FROM Usuarios 
            WHERE Usuario = ? OR DNI = ? OR Email = ?";
    $stmt = $conn_clientes->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("sss", $identificador, $identificador, $identificador);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['Password'])) {
            if ($row['email_verified'] == 0) {
                $_SESSION['login_error'] = 'Debe verificar su cuenta mediante el enlace enviado a su correo.';
                return false;
            }
            // Regenerar ID de sesión para evitar fijación
            session_regenerate_id(true);
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['user_nombre'] = $row['Nombre'];
            $_SESSION['user_usuario'] = $row['Usuario'];
            $_SESSION['user_rol'] = $row['rol'];
            $_SESSION['user_petrolera'] = $row['Petrolera'];
            $_SESSION['user_dni'] = $row['DNI'];
            guardarCookieAutenticacion([
                'tipo' => 'usuario',
                'user_id' => (int) $row['id'],
                'user_nombre' => $row['Nombre'],
                'user_usuario' => $row['Usuario'],
                'user_rol' => (int) $row['rol'],
                'user_petrolera' => $row['Petrolera'],
                'user_dni' => $row['DNI'],
            ]);
            return true;
        }
    }
    return false;
}

function autenticarEstacion($site, $password) {
    global $conn;
    $sql = "SELECT id, site, nombre, password FROM sites WHERE site = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("i", $site);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['site_id'] = $row['id'];
            $_SESSION['site_numero'] = $row['site'];
            $_SESSION['site_nombre'] = $row['nombre'];
            guardarCookieAutenticacion([
                'tipo' => 'estacion',
                'site_id' => (int) $row['id'],
                'site_numero' => (int) $row['site'],
                'site_nombre' => $row['nombre'],
            ]);
            return true;
        }
    }
    return false;
}

// Funciones CSRF
function configurarCookieCSRF($token) {
    if (headers_sent()) {
        return;
    }

    setcookie('csrf_token', $token, [
        'expires' => 0,
        'path' => '/',
        'secure' => defined('APP_IS_HTTPS') && APP_IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['csrf_token'] = $token;
}

function generarTokenCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        if (!empty($_COOKIE['csrf_token']) && preg_match('/^[a-f0-9]{64}$/', $_COOKIE['csrf_token'])) {
            $_SESSION['csrf_token'] = $_COOKIE['csrf_token'];
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    configurarCookieCSRF($_SESSION['csrf_token']);
    return $_SESSION['csrf_token'];
}

function verificarTokenCSRF($token) {
    if (!is_string($token) || $token === '') {
        return false;
    }

    $session_valida = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    $cookie_valida = isset($_COOKIE['csrf_token']) && hash_equals($_COOKIE['csrf_token'], $token);

    return $session_valida || $cookie_valida;
}

// Respaldo de autenticación para hostings donde PHPSESSID no persiste correctamente.
function obtenerClaveCookieAutenticacion() {
    return hash('sha256', DB_NAME . '|' . DB_USER . '|' . CLIENTES_DB_NAME . '|' . CLIENTES_DB_PASS . '|' . __DIR__);
}

function codificarBase64Url($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function decodificarBase64Url($value) {
    return base64_decode(strtr($value, '-_', '+/'));
}

function configurarCookieAutenticacion($value, $expires = 0) {
    if (headers_sent()) {
        return;
    }

    setcookie('carteldigital_auth', $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => defined('APP_IS_HTTPS') && APP_IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($value === '') {
        unset($_COOKIE['carteldigital_auth']);
    } else {
        $_COOKIE['carteldigital_auth'] = $value;
    }
}

function guardarCookieAutenticacion($payload) {
    $payload['exp'] = time() + 8 * 60 * 60;
    $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $payload_b64 = codificarBase64Url($payload_json);
    $firma = hash_hmac('sha256', $payload_b64, obtenerClaveCookieAutenticacion());

    configurarCookieAutenticacion($payload_b64 . '.' . $firma, $payload['exp']);
}

function borrarCookieAutenticacion() {
    configurarCookieAutenticacion('', time() - 3600);
}

function restaurarSesionDesdeCookieAutenticacion() {
    if (estaLogueado() || empty($_COOKIE['carteldigital_auth'])) {
        return;
    }

    $parts = explode('.', $_COOKIE['carteldigital_auth'], 2);
    if (count($parts) !== 2) {
        borrarCookieAutenticacion();
        return;
    }

    [$payload_b64, $firma] = $parts;
    $firma_esperada = hash_hmac('sha256', $payload_b64, obtenerClaveCookieAutenticacion());
    if (!hash_equals($firma_esperada, $firma)) {
        borrarCookieAutenticacion();
        return;
    }

    $payload = json_decode(decodificarBase64Url($payload_b64), true);
    if (!is_array($payload) || empty($payload['exp']) || $payload['exp'] < time()) {
        borrarCookieAutenticacion();
        return;
    }

    if (($payload['tipo'] ?? '') === 'usuario' && !empty($payload['user_id'])) {
        $_SESSION['user_id'] = $payload['user_id'];
        $_SESSION['user_nombre'] = $payload['user_nombre'] ?? '';
        $_SESSION['user_usuario'] = $payload['user_usuario'] ?? '';
        $_SESSION['user_rol'] = $payload['user_rol'] ?? 0;
        $_SESSION['user_petrolera'] = $payload['user_petrolera'] ?? null;
        $_SESSION['user_dni'] = $payload['user_dni'] ?? '';
    } elseif (($payload['tipo'] ?? '') === 'estacion' && !empty($payload['site_id'])) {
        $_SESSION['site_id'] = $payload['site_id'];
        $_SESSION['site_numero'] = $payload['site_numero'] ?? '';
        $_SESSION['site_nombre'] = $payload['site_nombre'] ?? '';
    }
}

restaurarSesionDesdeCookieAutenticacion();

// Resto de funciones de autenticación
function estaLogueado() { return isset($_SESSION['user_id']) || isset($_SESSION['site_id']); }
function esUsuario() { return isset($_SESSION['user_id']); }
function esEstacion() { return isset($_SESSION['site_id']); }
function redirigir($url) { header("Location: $url"); exit; }

function redirigirSegunRol() {
    if (!esUsuario()) redirigir('login.php');
    $rol = $_SESSION['user_rol'];
    switch ($rol) {
        case 1: redirigir('user_stations.php'); break;
        case 4: redirigir('dashboard_user_petrolera.php'); break;
        case 5: redirigir('dashboard_user_tech.php'); break;
        case 9: redirigir('dashboard_user_sop.php'); break;
        default: redirigir('dashboard_user.php');
    }
}

function generarTokenVerificacion() { return bin2hex(random_bytes(32)); }
?>