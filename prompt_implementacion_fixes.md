# Prompt de Implementación — Correcciones CarteleriaDigital

> Usa este documento como prompt para implementar todas las correcciones identificadas en la auditoría.

---

## CONTEXTO DEL PROYECTO

CarteleriaDigital es una app PHP que gestiona carteles digitales de precios para estaciones de servicio. Conecta 4 BD MySQL (CARTELES, CLIENTES, IOT, MercadoPago), usa PHPMailer y Bootstrap 5. Los archivos están en la raíz sin estructura MVC.

---

## FASE 1: CORRECCIONES CRÍTICAS DE SEGURIDAD (Prioridad Inmediata)

### 1.1 Mover credenciales a variables de entorno

**Qué hacer:**
1. Crear archivo `.env` en la raíz (fuera de document root en producción):
```env
DB_HOST=vm-test.sys-srl.com.ar
DB_USER=admin_sys
DB_PASS=adminsys-srl
DB_NAME=CARTELES
CLIENTES_DB_HOST=carteldigital.sys-srl.com.ar
CLIENTES_DB_USER=admin_sys
CLIENTES_DB_PASS=adminsys-srl
CLIENTES_DB_NAME=CLIENTES
IOT_DB_HOST=epagos.sys-srl.com.ar
IOT_DB_USER=admin
IOT_DB_PASS=adminsys-srl
IOT_DB_NAME=IOT
MP_DB_HOST=epagos.sys-srl.com.ar
MP_DB_USER=admin
MP_DB_PASS=adminsys-srl
MP_DB_NAME=MercadoPago
SMTP_HOST=smtp10.allytech.com
SMTP_PORT=587
SMTP_USER=cartel
SMTP_PASS=sopsys495
SMTP_SECURE=tls
FROM_EMAIL=cartel@sys-srl.com.ar
FROM_NAME=Sistema de Carteles WFT02
REPLY_TO_EMAIL=cartel@sys-srl.com.ar
REPLY_TO_NAME=Registro
APP_NAME=Interface WFT 02 - Cartel de precios digital
ENVIRONMENT=production
```

2. Crear función `loadEnv()` en `credenciales.php` que parsee el `.env` y defina las constantes con `getenv()` o parse manual.
3. Agregar `.env` a `.gitignore`.
4. Crear `.env.example` con valores placeholder.
5. Reescribir `credenciales.php` para usar `getenv()`:
```php
<?php
// credenciales.php
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}
define('APP_NAME', getenv('APP_NAME') ?: 'CarteleriaDigital');
define('DB_HOST', getenv('DB_HOST'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('DB_NAME', getenv('DB_NAME'));
// ... repetir para todas las constantes
```

### 1.2 Eliminar inyección SQL en register_user.php

**Archivo:** `register_user.php`, **línea 52**
**Qué hacer:** Eliminar la línea con query directa. Ya existe el prepared statement correcto en las líneas 53-56.

```diff
- $conn_clientes->query("DELETE FROM Usuarios WHERE email = '$email'");
  $del = $conn_clientes->prepare("DELETE FROM Usuarios WHERE email = ?");
  $del->bind_param("s", $email);
  $del->execute();
  $del->close();
```

### 1.3 Corregir queries directas en dashboard_user_sop.php

**Archivo:** `dashboard_user_sop.php`, **líneas 270, 273, 276**
**Qué hacer:** Reemplazar `->query()` directas con prepared statements:

```php
// Línea 270: Reemplazar
$stmt_c = $conn->prepare("UPDATE Cartel SET site = ? WHERE site = ?");
$stmt_c->bind_param("ii", $new_site, $old_site);
$stmt_c->execute();
$stmt_c->close();

// Línea 273: Reemplazar
$stmt_iot = $conn_iot->prepare("UPDATE Dispositivos SET site = ? WHERE site = ?");
$stmt_iot->bind_param("ii", $new_site, $old_site);
$stmt_iot->execute();
$stmt_iot->close();

// Línea 276: Reemplazar
$stmt_cl = $conn_clientes->prepare("UPDATE pendientes_registro SET site = ? WHERE site = ?");
$stmt_cl->bind_param("ii", $new_site, $old_site);
$stmt_cl->execute();
$stmt_cl->close();
```

### 1.4 Proteger nombre de tabla en api_sop.php

**Archivo:** `api_sop.php`, **líneas 154, 187**
**Qué hacer:** Crear mapa de tablas reales y usar lookup en vez de interpolación directa:

```php
$tableMap = [
    'sites' => 'sites',
    'usuarios' => 'Usuarios',
    'cartel' => 'Cartel',
    'dispositivos' => 'Dispositivos',
    'logs' => 'log_cambios',
    'roles' => 'roles',
    'petroleras' => 'Petroleras'
];
$realTable = $tableMap[$table] ?? null;
if (!$realTable) { echo json_encode(['error' => 'Tabla no válida']); exit; }
// Usar $realTable (que viene del mapa, no del usuario) en las queries
$sql = "UPDATE `$realTable` SET " . implode(', ', $fields) . " WHERE id = ?";
```

### 1.5 Rotar token CSRF tras cada uso

**Archivo:** `config.php`, funciones `generarTokenCSRF()` y `verificarTokenCSRF()`
**Qué hacer:**

```php
function generarTokenCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verificarTokenCSRF($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    // Rotar tras uso exitoso
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return true;
}
```

### 1.6 Eliminar archivos de debug/prueba

**Qué hacer:** Eliminar o proteger estos archivos:
- Eliminar `prueba.php`
- Eliminar `productos.php`
- Agregar `.htaccess` para bloquear acceso a `php_errors.log`
- Evaluar si `DB.sqlite` es necesario; si no, eliminar.

### 1.7 Crear .htaccess de protección en raíz

```apache
# Bloquear acceso a archivos sensibles
<FilesMatch "\.(log|sqlite|env)$">
    Require all denied
</FilesMatch>

# Bloquear acceso a credenciales
<Files "credenciales.php">
    Require all denied
</Files>
```

### 1.8 Agregar headers de seguridad HTTP

**Archivo:** `header.php`, al inicio antes del HTML:

```php
<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
?>
```

### 1.9 Mejorar logout con invalidación completa

**Archivo:** `logout.php`:

```php
<?php
session_start();
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header('Location: index.php');
exit;
?>
```

### 1.10 Corregir orden de display_errors en config.php

**Archivo:** `config.php`, **líneas 6-9**
**Qué hacer:** Mover las líneas 6-9 (`ini_set('display_errors', 1)`) DESPUÉS del bloque de ENVIRONMENT (línea 20-29), o eliminarlas por completo ya que el bloque de entorno las gestiona.

```diff
- ini_set('display_errors', 1);
- ini_set('display_startup_errors', 1);
- error_reporting(E_ALL);
  // Iniciar sesión con configuración segura
```

---

## FASE 2: CORRECCIONES DE ARQUITECTURA (Prioridad Alta)

### 2.1 Crear clase Database para gestión de conexiones

Crear `includes/Database.php`:

```php
<?php
class Database {
    private static $instances = [];

    public static function getConnection(string $name = 'carteles'): mysqli {
        if (!isset(self::$instances[$name])) {
            $config = self::getConfig($name);
            $conn = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
            if ($conn->connect_error) {
                error_log("Error conexión $name: " . $conn->connect_error);
                throw new RuntimeException("Error de conexión a BD.");
            }
            $conn->set_charset("utf8mb4");
            self::$instances[$name] = $conn;
        }
        return self::$instances[$name];
    }

    private static function getConfig(string $name): array {
        $configs = [
            'carteles' => ['host' => DB_HOST, 'user' => DB_USER, 'pass' => DB_PASS, 'name' => DB_NAME],
            'clientes' => ['host' => CLIENTES_DB_HOST, 'user' => CLIENTES_DB_USER, 'pass' => CLIENTES_DB_PASS, 'name' => CLIENTES_DB_NAME],
            'iot'      => ['host' => IOT_DB_HOST, 'user' => IOT_DB_USER, 'pass' => IOT_DB_PASS, 'name' => IOT_DB_NAME],
            'mp'       => ['host' => MP_DB_HOST, 'user' => MP_DB_USER, 'pass' => MP_DB_PASS, 'name' => MP_DB_NAME],
        ];
        return $configs[$name] ?? throw new InvalidArgumentException("BD desconocida: $name");
    }

    public static function closeAll(): void {
        foreach (self::$instances as $conn) { $conn->close(); }
        self::$instances = [];
    }
}
```

### 2.2 Crear función helper de email reutilizable

**Archivo:** `functions.php` — Reemplazar las 5 funciones de email duplicadas con una sola función base:

```php
function crearMailer(): PHPMailer\PHPMailer\PHPMailer {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
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
    $mail->isHTML(false);
    return $mail;
}

function obtenerBaseUrl(): string {
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
    $script_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $base_url . $script_path;
}
```

Luego simplificar cada función de email para usar `crearMailer()` y `obtenerBaseUrl()`.

### 2.3 Eliminar require_once duplicado

**Archivo:** `config.php`, **línea 66**: Eliminar `require_once __DIR__ . '/functions.php';` (ya está en línea 4).

### 2.4 Mover creación de tablas a script de setup

**Archivo:** `config.php`, **líneas 69-136**
**Qué hacer:** Extraer `crearTablasSistema()` y `cargarProductosPorDefecto()` a un archivo `setup/install.php` que se ejecute una sola vez. Eliminar las llamadas en líneas 114 y 136.

### 2.5 Extraer estilos CSS inline a style.css

**Qué hacer:** Mover todos los bloques `<style>` embebidos en `dashboard_user_sop.php`, `dashboard_user_petrolera.php`, `dashboard_user_tech.php`, `dashboard_site.php`, `login.php`, `dashboard_user.php` y `perfil_estacion.php` al archivo `css/style.css`. Crear clases reutilizables.

### 2.6 Corregir HTML doble en dashboard_site.php

**Archivo:** `dashboard_site.php`
**Qué hacer:** Eliminar el bloque `<!DOCTYPE html>...<body>` propio (líneas 52-78) y usar solo `header.php` como hacen los demás archivos. Mover los estilos al CSS.

### 2.7 Corregir HTML malformado en dashboard_user_tech.php

**Archivo:** `dashboard_user_tech.php`, **línea 252**
**Qué hacer:** Corregir el cierre de `</table>` que está antes de `</thead>`:

```diff
- <thead><tr class="text-center"><th>APIES</th>...<th>Estado WFT</th><th>Últ. actualización</th></table></thead>
+ <thead><tr class="text-center"><th>APIES</th>...<th>Estado WFT</th><th>Últ. actualización</th></tr></thead>
```

---

## FASE 3: CORRECCIONES DE ROBUSTEZ (Prioridad Media)

### 3.1 Crear manejador de errores global

**Archivo:** Crear `includes/error_handler.php`:

```php
<?php
set_exception_handler(function (Throwable $e) {
    error_log($e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        echo '<pre>' . htmlspecialchars($e) . '</pre>';
    } else {
        http_response_code(500);
        include __DIR__ . '/../error_500.html';
    }
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
```

### 3.2 Reemplazar die() con respuestas HTTP adecuadas

En todos los archivos, reemplazar `die('mensaje')` con:
```php
http_response_code(400); // o 500 según el caso
include 'header.php';
echo '<div class="alert alert-danger">Mensaje de error</div>';
include 'footer.php';
exit;
```

### 3.3 Cerrar statements en todos los flujos

Revisar `dashboard_user.php` y asegurar que todos los `$check_user`, `$check_email`, `$check_dni` se cierren tanto en el flujo exitoso como en el de error (usar try/finally o restructurar el if/else anidado).

### 3.4 Corregir N+1 en user_stations.php

**Archivo:** `user_stations.php`, **línea 106**
**Qué hacer:** Recopilar todas las MACs primero y usar `obtenerEstadosWFTMultiples()`:

```php
$macs_list = array_column($stations, 'mac');
$estados_wft = obtenerEstadosWFTMultiples(array_filter($macs_list));

// En el foreach:
$wft = $estados_wft[$s['mac']] ?? 'Error';
```

### 3.5 Agregar paginación a reportes

**Archivo:** `reportes.php`
**Qué hacer:** Agregar `LIMIT` y `OFFSET` a las consultas, con controles de paginación en la UI. Ejemplo:

```php
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
// Agregar a cada query: " LIMIT $perPage OFFSET $offset"
```

### 3.6 Agregar expiración a tokens de verificación de usuario

**Archivo:** `verify_user.php`
**Qué hacer:** Agregar columna `token_expires_at` a tabla Usuarios y verificar:

```php
$sql = "SELECT id, email_verified FROM Usuarios 
        WHERE verification_token = ? AND email = ? 
        AND (token_expires_at IS NULL OR token_expires_at > NOW())";
```

### 3.7 Validar expiración en verify_email.php

**Archivo:** `verify_email.php`, **línea 10**
**Qué hacer:** Agregar verificación de expiración:

```php
$sql = "SELECT id, email FROM pendientes_registro WHERE token = ? AND MAC = ? AND expira > NOW()";
```

---

## FASE 4: MEJORAS DE ESCALABILIDAD (Prioridad Media-Baja)

### 4.1 Implementar caché básico para datos estáticos

Crear `includes/Cache.php` para cachear datos que no cambian frecuentemente:

```php
<?php
class Cache {
    private static $cache = [];

    public static function remember(string $key, int $ttl, callable $callback) {
        if (isset(self::$cache[$key]) && self::$cache[$key]['expires'] > time()) {
            return self::$cache[$key]['data'];
        }
        $data = $callback();
        self::$cache[$key] = ['data' => $data, 'expires' => time() + $ttl];
        return $data;
    }
}
```

Usar para `obtenerPetroleras()`, `obtenerProvincias()`, `obtenerProductosPorPetrolera()`.

### 4.2 Reemplazar meta-refresh con AJAX

En los dashboards, reemplazar `<meta http-equiv="refresh" content="30">` con JavaScript:

```javascript
setInterval(() => {
    fetch(window.location.href, { headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(r => r.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            // Actualizar solo las secciones dinámicas
            document.querySelector('.stations-table tbody').innerHTML =
                doc.querySelector('.stations-table tbody').innerHTML;
        });
}, 30000);
```

### 4.3 Agregar fallback local para Bootstrap

```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet" id="bs-fallback" disabled>
<script>
if (!document.querySelector('link[href*="cdn.jsdelivr"]').sheet) {
    document.getElementById('bs-fallback').disabled = false;
}
</script>
```

---

## FASE 5: MEJORAS DE UI/UX (Prioridad Baja)

### 5.1 Fortalecer política de contraseñas

En todas las validaciones de contraseña (al menos 5 archivos), cambiar:

```php
// De:
if (strlen($password) < 6) { ... }

// A:
if (strlen($password) < 8 ||
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[0-9]/', $password)) {
    $error = 'La contraseña debe tener al menos 8 caracteres, incluir mayúsculas, minúsculas y números.';
}
```

### 5.2 Consolidar estilos CSS

Mover TODOS los estilos inline al archivo `css/style.css` con clases semánticas:
- `.dashboard-container`
- `.stations-table`, `.stations-table th`, `.stations-table tr.active`
- `.metric-circle`, `.circle`, `.circle-value`, `.circle-svg`
- `.filter-card`
- `.price-list`, `.price-item`, `.product-name`, `.product-price`
- `.text-ok`, `.text-error`
- `.modal-content` (dark theme)
- `.form-control-custom`
- `.info-card`, `.info-icon`, `.info-value`

### 5.3 Mejorar sanitización

**Archivo:** `functions.php`, función `sanitizarTexto()`
**Qué hacer:** Reemplazar con `htmlspecialchars()` + `trim()` para output, y validaciones específicas por campo para input:

```php
function sanitizarTexto($texto) {
    return htmlspecialchars(trim($texto), ENT_QUOTES, 'UTF-8');
}

function validarNombre($nombre) {
    $nombre = trim($nombre);
    if (strlen($nombre) < 2 || strlen($nombre) > 100) return false;
    if (preg_match('/[<>"\']/', $nombre)) return false;
    return $nombre;
}
```

---

## FASE 6: PROTECCIÓN DE ARCHIVOS Y LIMPIEZA

### 6.1 Crear .gitignore

```gitignore
.env
php_errors.log
DB.sqlite
stats/
vendor/
*.log
```

### 6.2 Proteger directorio stats/

Mover las estadísticas AWStats fuera del document root o agregar autenticación HTTP.

### 6.3 Limpiar archivos innecesarios

- Eliminar `prueba.php`
- Eliminar `productos.php`
- Eliminar `DB.sqlite` si no se usa
- Evaluar si `stats/index.php` debe ser público

---

## ORDEN DE IMPLEMENTACIÓN RECOMENDADO

| Orden | Fase | Descripción | Esfuerzo |
|-------|------|-------------|----------|
| 1 | 1.1 | Credenciales a .env | 1h |
| 2 | 1.2 | Eliminar SQL injection register_user | 5min |
| 3 | 1.3 | Fix queries directas sop dashboard | 15min |
| 4 | 1.6-1.7 | Eliminar debug + .htaccess | 15min |
| 5 | 1.10 | Fix display_errors orden | 5min |
| 6 | 1.4 | Proteger tabla en api_sop | 30min |
| 7 | 1.5 | Rotar CSRF | 10min |
| 8 | 1.8-1.9 | Headers seguridad + logout | 15min |
| 9 | 2.3 | Eliminar require duplicado | 2min |
| 10 | 2.4 | Mover setup de tablas | 20min |
| 11 | 2.6-2.7 | Fix HTML malformado | 20min |
| 12 | 3.4 | Fix N+1 user_stations | 15min |
| 13 | 3.6-3.7 | Expiración tokens | 30min |
| 14 | 2.1 | Clase Database | 1h |
| 15 | 2.2 | Helper de email | 45min |
| 16 | 2.5 | Extraer CSS inline | 1h |
| 17 | 3.1-3.2 | Manejo de errores | 1h |
| 18 | 3.5 | Paginación reportes | 45min |
| 19 | 5.1 | Política contraseñas | 30min |
| 20 | 5.3 | Mejorar sanitización | 30min |
| 21 | 4.1-4.3 | Caché + AJAX + fallback | 2h |
| 22 | 6.1-6.3 | Gitignore + limpieza | 15min |

**Tiempo total estimado: ~11 horas de desarrollo**
