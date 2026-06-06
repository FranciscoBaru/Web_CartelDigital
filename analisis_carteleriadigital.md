# Auditoría Técnica Exhaustiva — CarteleriaDigital (PHP)

> **Fecha:** 2026-05-05 | **Archivos analizados:** 32 PHP + 1 CSS + 1 SQLite + logs

---

## 1. RESUMEN EJECUTIVO

El proyecto CarteleriaDigital es una aplicación web PHP que gestiona carteles digitales de precios para estaciones de servicio. Conecta 4 bases de datos MySQL (CARTELES, CLIENTES, IOT, MercadoPago), utiliza PHPMailer para correos y Bootstrap 5 para la UI. Si bien tiene bases de seguridad (CSRF, prepared statements, `password_hash`), presenta **vulnerabilidades críticas**, **deuda técnica severa** y **problemas arquitectónicos** que requieren atención inmediata.

### Calificación por Área

| Área | Nota | Estado |
|------|------|--------|
| Seguridad | 3/10 | 🔴 CRÍTICO |
| Arquitectura | 3/10 | 🔴 CRÍTICO |
| Mantenibilidad | 3/10 | 🔴 CRÍTICO |
| Robustez | 4/10 | 🟠 DEFICIENTE |
| Escalabilidad | 3/10 | 🔴 CRÍTICO |
| Funcionalidad | 7/10 | 🟢 ACEPTABLE |
| UI/UX | 5/10 | 🟡 MEJORABLE |

---

## 2. HALLAZGOS CRÍTICOS DE SEGURIDAD

### 2.1 🔴 Credenciales en texto plano en el repositorio
- **Archivo:** `credenciales.php`
- **Problema:** Contraseñas de 4 bases de datos y SMTP hardcodeadas en código fuente. Incluye hosts, usuarios y passwords reales (`adminsys-srl`, `sopsys495`).
- **Impacto:** Compromiso total de todas las bases de datos si el repositorio se filtra.
- **Solución:** Variables de entorno (`.env`) + `.gitignore`.

### 2.2 🔴 Inyección SQL directa en register_user.php (línea 52)
```php
$conn_clientes->query("DELETE FROM Usuarios WHERE email = '$email'");
```
- **Impacto:** Inyección SQL directa. El `$email` viene de `$_POST` sin escapar.
- **Nota:** Hay un prepared statement justo después (líneas 53-56) pero la línea 52 se ejecuta primero.

### 2.3 🔴 Inyección SQL en dashboard_user_sop.php (líneas 270, 273, 276)
```php
$conn->query("UPDATE Cartel SET site = $new_site WHERE site = $old_site");
$conn_iot->query("UPDATE Dispositivos SET site = $new_site WHERE site = $old_site");
$conn_clientes->query("UPDATE pendientes_registro SET site = $new_site WHERE site = $old_site");
```
- Aunque `$new_site` y `$old_site` pasan por `intval()`, el patrón es peligroso y no sigue las buenas prácticas del resto del código.

### 2.4 🔴 API genérica con UPDATE/INSERT/DELETE dinámico (api_sop.php)
- **Línea 154:** `$sql = "UPDATE $table SET " . implode(', ', $fields) . " WHERE id = ?";`
- Aunque hay whitelist de tablas y columnas, el nombre de tabla se interpola directamente en SQL sin escapar. Un atacante que comprometa la sesión de soporte tiene acceso CRUD a todo el sistema.
- No hay rate limiting en la API.

### 2.5 🔴 Token CSRF estático por sesión
- `generarTokenCSRF()` genera un token **una sola vez** por sesión y lo reutiliza indefinidamente. No se rota tras cada uso exitoso.

### 2.6 🟠 Archivos de debug/prueba accesibles
- `prueba.php` y `productos.php` son herramientas de prueba sin autenticación que ejecutan `config.php` (conectan a BD).
- `php_errors.log` accesible públicamente revela rutas del servidor (`/var/www/clients/client1/web9/web/`).

### 2.7 🟠 Logout no invalida cookie de sesión
- `logout.php` destruye la sesión pero no elimina la cookie con `setcookie()` ni establece `SameSite`.

### 2.8 🟠 Falta de headers de seguridad HTTP
- No hay `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Strict-Transport-Security`, `Referrer-Policy`.

### 2.9 🟠 display_errors activado antes del check de entorno
- `config.php` líneas 6-9: `display_errors=1` se activa **antes** de la lógica de entorno que lo desactiva. Race condition al cargar.

### 2.10 🟠 Token de verificación de email sin expiración
- `verify_user.php`: El `verification_token` nunca expira. Un token antiguo sigue siendo válido indefinidamente.

---

## 3. PROBLEMAS ARQUITECTÓNICOS

### 3.1 🔴 Sin separación MVC — Código espagueti
- Cada archivo `.php` mezcla lógica de negocio, consultas SQL, manejo de sesión y HTML en un solo archivo.
- `dashboard_user_sop.php` tiene **817 líneas** con PHP, SQL, HTML, CSS y JavaScript todo junto.

### 3.2 🔴 Duplicación masiva de código
- **Conexión IOT duplicada:** Código idéntico de conexión a `IOT_DB` en `dashboard_user_sop.php`, `dashboard_user_petrolera.php`, `dashboard_user_tech.php`, `complete_site_registration.php`.
- **Consultas de estaciones duplicadas:** La misma query SQL de ~15 líneas para obtener estaciones se repite en 3 dashboards.
- **Configuración SMTP duplicada:** La configuración de PHPMailer (12 líneas) se repite en 5 funciones de email.
- **Estilos CSS inline duplicados:** Los mismos ~40 estilos se repiten en cada dashboard (`<style>` embebido).
- **Métricas/filtros duplicados:** El mismo bloque de cálculo de porcentajes se repite en 3 archivos.

### 3.3 🔴 Acoplamiento fuerte con variables globales
- Todas las funciones usan `global $conn, $conn_clientes` — imposible testear unitariamente.

### 3.4 🔴 Sin autoloader ni gestión de dependencias
- PHPMailer se incluye manualmente sin Composer. Sin PSR-4 autoloading.

### 3.5 🟠 Conexiones a BD siempre activas
- `config.php` abre **2 conexiones MySQL** en cada request, incluso para páginas que no las necesitan. Las conexiones a IOT y MercadoPago se abren ad-hoc sin pooling.

### 3.6 🟠 `crearTablasSistema()` y `cargarProductosPorDefecto()` se ejecutan en CADA request
- Líneas 114 y 136 de `config.php`: Queries DDL innecesarias en cada carga de página.

### 3.7 🟠 `functions.php` se incluye DOS veces
- `config.php` línea 4: `require_once 'functions.php'`
- `config.php` línea 66: `require_once 'functions.php'` (redundante)

### 3.8 🟠 Dashboard de estación tiene su propio `<html>` completo
- `dashboard_site.php` genera `<!DOCTYPE html>` propio pero también incluye `header.php` que genera otro `<!DOCTYPE html>`. HTML malformado con doble `<html>`, `<head>`, `<body>`.

---

## 4. PROBLEMAS DE ROBUSTEZ

### 4.1 🔴 Sin manejo de errores estructurado
- Se usa `die()` para errores fatales (no genera respuesta HTTP útil).
- No hay try-catch global ni manejador de excepciones.
- Los errores de BD se muestran al usuario en producción (`$stmt->error`).

### 4.2 🟠 Statements no cerrados en flujos de error
- En `dashboard_user.php` y otros: si las validaciones de unicidad fallan en los `if/else` anidados, los `$check_*` statements no siempre se cierran.

### 4.3 🟠 Falta de validación de datos en la API
- `api_sop.php`: Todos los valores se tratan como strings (`str_repeat('s', ...)`), sin validación de tipos ni rangos.

### 4.4 🟠 HTML malformado en dashboard_user_tech.php
- Línea 252: `</table>` antes de `</thead>`, rompiendo la estructura de la tabla.

### 4.5 🟠 user_stations.php: N+1 queries
- Para cada estación en la tabla, se llama a `obtenerEstadoWFT()` individualmente (línea 106) en lugar de usar `obtenerEstadosWFTMultiples()`.

### 4.6 🟠 Reportes sin paginación
- `reportes.php` carga todos los registros del rango de fechas sin `LIMIT`. Para estaciones activas esto puede ser miles de filas.

---

## 5. PROBLEMAS DE ESCALABILIDAD

### 5.1 🔴 Sin caché
- Cada request ejecuta múltiples queries a 2-4 bases de datos sin ningún tipo de caché.

### 5.2 🔴 Consultas no optimizadas
- `obtenerEstadoWFT()`: `ORDER BY fecha DESC, hora DESC` sin índice compuesto probable.
- `dashboard_user_sop.php`: Subconsulta `MAX(id)` por grupo sin CTE ni materialización.

### 5.3 🟠 Sin CDN local para assets
- Bootstrap y Bootstrap Icons se cargan desde CDN externo (`cdn.jsdelivr.net`). Sin fallback local.

### 5.4 🟠 Auto-refresh con meta tag
- Las páginas usan `<meta http-equiv="refresh">` cada 30s, recargando toda la página. Debería ser AJAX/WebSocket.

---

## 6. PROBLEMAS DE UI/UX

### 6.1 🟠 Estilos inconsistentes
- `header.php` define estilos globales en `<style>` inline que conflictúan con `style.css`.
- Cada dashboard redefine los mismos estilos.

### 6.2 🟠 Sin feedback visual de carga
- No hay spinners ni indicadores de loading para operaciones asíncronas.

### 6.3 🟠 Contraseña mínima de solo 6 caracteres
- No se exige mayúsculas, números ni caracteres especiales.

### 6.4 🟡 Falta de accesibilidad (a11y)
- Sin atributos `aria-*`, sin `alt` en elementos interactivos, sin manejo de foco en modales.

---

## 7. OTROS PROBLEMAS

| # | Problema | Archivo | Línea |
|---|----------|---------|-------|
| 1 | `DB.sqlite` huérfano sin uso | raíz | — |
| 2 | Carpeta `stats/` con AWStats público | stats/ | — |
| 3 | Sin `.htaccess` en raíz para proteger archivos sensibles | raíz | — |
| 4 | `$_POST = []` para "limpiar" formulario | complete_site_registration.php | 165 |
| 5 | Mensajes vía GET en redirect (URL visibles) | dashboard_user_sop.php | 123,326 |
| 6 | XSS potencial en JS con template literals | dashboard_user_sop.php | 712 |
| 7 | `sanitizarTexto()` es ad-hoc y no previene XSS | functions.php | 366-370 |
| 8 | `verify_email.php` redirige sin verificar expiración del token | verify_email.php | 17-19 |
| 9 | Falta `forgot_password_site` referenciado pero no existe | — | — |

---
