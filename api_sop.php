<?php
// api_sop.php - API para el panel de soporte (Rol 9)
require_once 'config.php';

// Deshabilitar errores para no romper JSON
ini_set('display_errors', 0);
error_reporting(0);

// Verificar autenticación y rol
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] != 9) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$table = $_REQUEST['table'] ?? '';
$id = $_REQUEST['id'] ?? 0;

// Tablas permitidas ('dispositivos'/IOT retirado)
$allowedTables = ['sites', 'usuarios', 'cartel', 'logs', 'roles', 'petroleras'];

// Traduce los nombres de columna del "cartel" (nombres históricos) a los de
// sign_prices. Las columnas sin equivalente directo (fecha/hora) se descartan.
function traducirColumnasCartel(array $data) {
    $map = [
        'precio1' => 'price1', 'precio2' => 'price2', 'precio3' => 'price3', 'precio4' => 'price4', 'precio5' => 'price5',
        'lama1'   => 'linea1', 'lama2'   => 'linea2', 'lama3'   => 'linea3', 'lama4'   => 'linea4', 'lama5'   => 'linea5',
        'estado485' => 'est_485', 'estadovox' => 'est_cont',
        'MAC' => 'mac', 'IP_LAN' => 'ip_lan',
    ];
    $out = [];
    foreach ($data as $k => $v) {
        if (isset($map[$k])) {
            $out[$map[$k]] = $v;
        } elseif (in_array($k, ['fecha', 'hora'], true)) {
            // sin equivalente directo en sign_prices (updated_at se maneja aparte)
            continue;
        } else {
            $out[$k] = $v; // site, idproducto1..5, etc. quedan igual
        }
    }
    return $out;
}
if (!in_array($table, $allowedTables)) {
    echo json_encode(['error' => 'Tabla no permitida']);
    exit;
}

// Verificar token CSRF para operaciones de escritura
if (in_array($action, ['update', 'insert', 'delete'])) {
    $csrf_token = $_REQUEST['csrf_token'] ?? '';
    if (!verificarTokenCSRF($csrf_token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }
}

$usuario_actual = $_SESSION['user_nombre'] ?? 'soporte';

// Definir columnas permitidas para cada tabla (whitelist)
$allowedColumns = [
    'sites' => ['site', 'nombre', 'petrolera_id', 'domicilio', 'localidad', 'provincia', 'telefono', 'email', 'cuit', 'password', 'MAC', 'email_verified'],
    'usuarios' => ['Nombre', 'Usuario', 'Email', 'Password', 'rol', 'Petrolera', 'email_verified', 'DNI', 'telefono', 'provincia'],
    'cartel' => ['site', 'lama1', 'idproducto1', 'precio1', 'lama2', 'idproducto2', 'precio2', 'lama3', 'idproducto3', 'precio3', 'lama4', 'idproducto4', 'precio4', 'lama5', 'idproducto5', 'precio5', 'estado485', 'estadovox', 'MAC', 'IP_LAN', 'fecha', 'hora'],
    'dispositivos' => ['site', 'MAC', 'Producto', 'Descripcion', 'Dispositivo', 'firmware', 'PreUni', 'Tiempo'],
    'logs' => ['usuario', 'tabla', 'campo', 'valor_anterior', 'valor_nuevo'],
    'roles' => ['id', 'rol'],
    'petroleras' => ['id', 'nombre']
];

try {
    switch ($action) {
        case 'list':
            // Listado según la tabla solicitada
            if ($table === 'sites') {
                $result = $conn->query("SELECT s.*, p.nombre as petrolera_nombre FROM sites s LEFT JOIN Petroleras p ON s.petrolera_id = p.id");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
            } elseif ($table === 'usuarios') {
                // Se aliasan las columnas al nombre (con mayúsculas) que espera el
                // JS del panel (u.Nombre, u.Usuario, u.Email, u.DNI, u.Petrolera),
                // porque PostgreSQL las devuelve en minúscula y el JSON iría con esas claves.
                $result = $conn_clientes->query("SELECT u.id, u.nombre AS \"Nombre\", u.usuario AS \"Usuario\", u.email AS \"Email\", u.dni AS \"DNI\", u.rol, u.petrolera AS \"Petrolera\", u.email_verified, u.telefono, u.provincia, r.rol AS rol_nombre FROM Usuarios u LEFT JOIN roles r ON u.rol = r.id");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
            } elseif ($table === 'cartel') {
                $result = $conn->query("SELECT *,
                        price1 AS precio1, price2 AS precio2, price3 AS precio3, price4 AS precio4, price5 AS precio5,
                        linea1 AS lama1, linea2 AS lama2, linea3 AS lama3, linea4 AS lama4, linea5 AS lama5,
                        est_485 AS estado485, est_cont AS estadovox,
                        to_char(updated_at, 'YYYY-MM-DD') AS fecha, to_char(updated_at, 'HH24:MI:SS') AS hora
                    FROM sign_prices ORDER BY id DESC");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
            } elseif ($table === 'logs') {
                $result = $conn_clientes->query("SELECT * FROM log_cambios ORDER BY id DESC LIMIT 500");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
            } elseif ($table === 'roles') {
                $result = $conn_clientes->query("SELECT * FROM roles ORDER BY id");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
            } elseif ($table === 'petroleras') {
                $result = $conn->query("SELECT * FROM Petroleras ORDER BY id");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
            } else {
                echo json_encode(['error' => 'Listado no implementado para esta tabla']);
            }
            break;

        case 'get':
            // Obtener un registro por ID
            if ($table === 'usuarios') {
                $stmt = $conn_clientes->prepare("SELECT * FROM Usuarios WHERE id = ?");
            } elseif ($table === 'sites') {
                $stmt = $conn->prepare("SELECT * FROM sites WHERE id = ?");
            } else {
                echo json_encode(['error' => 'Operación get no soportada para esta tabla']);
                exit;
            }
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $data = $stmt->get_result()->fetch_assoc();
                echo json_encode($data ?: []);
                $stmt->close();
            } else {
                echo json_encode(['error' => 'Error preparando consulta']);
            }
            break;

        case 'update':
        case 'insert':
            $data = $_POST;
            unset($data['action'], $data['table'], $data['id'], $data['csrf_token']);
            
            // Filtrar solo columnas permitidas
            $allowed = $allowedColumns[$table] ?? [];
            $data = array_intersect_key($data, array_flip($allowed));
            
            if (empty($data)) {
                echo json_encode(['error' => 'No se enviaron datos válidos']);
                exit;
            }

            // El "cartel" del panel se persiste en la tabla sign_prices.
            $sqlTable = $table;
            if ($table === 'cartel') {
                $sqlTable = 'sign_prices';
                $data = traducirColumnasCartel($data);
                if (empty($data)) {
                    echo json_encode(['error' => 'No se enviaron datos válidos']);
                    exit;
                }
            }

            if ($action === 'update') {
                // Obtener datos antiguos para log
                $oldData = [];
                if ($table === 'usuarios') {
                    $oldStmt = $conn_clientes->prepare("SELECT * FROM Usuarios WHERE id = ?");
                    $oldStmt->bind_param("i", $id);
                    $oldStmt->execute();
                    $oldData = $oldStmt->get_result()->fetch_assoc();
                    $oldStmt->close();
                } elseif ($table === 'sites') {
                    $oldStmt = $conn->prepare("SELECT * FROM sites WHERE id = ?");
                    $oldStmt->bind_param("i", $id);
                    $oldStmt->execute();
                    $oldData = $oldStmt->get_result()->fetch_assoc();
                    $oldStmt->close();
                }
                
                // Construir UPDATE dinámico con placeholders
                $fields = [];
                $params = [];
                $types = '';
                foreach ($data as $key => $value) {
                    $fields[] = "$key = ?";
                    $params[] = $value;
                    $types .= 's';
                }
                $params[] = $id;
                $types .= 'i';
                $sql = "UPDATE $sqlTable SET " . implode(', ', $fields) . " WHERE id = ?";

                // Todas las tablas viven ahora en la misma base PostgreSQL ($conn).
                $stmt = $conn->prepare($sql);

                if ($stmt) {
                    $stmt->bind_param($types, ...$params);
                    $success = $stmt->execute();
                    if ($success) {
                        foreach ($data as $key => $newValue) {
                            if (isset($oldData[$key]) && (string)$oldData[$key] !== (string)$newValue) {
                                registrarLog($table, $key, $oldData[$key], $newValue, $usuario_actual);
                            }
                        }
                        echo json_encode(['success' => true]);
                    } else {
                        echo json_encode(['success' => false, 'message' => $stmt->error]);
                    }
                    $stmt->close();
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error preparando consulta']);
                }
            } else { // insert
                $fields = array_keys($data);
                $placeholders = implode(',', array_fill(0, count($fields), '?'));
                $sql = "INSERT INTO $sqlTable (" . implode(',', $fields) . ") VALUES ($placeholders)";

                // Todas las tablas viven ahora en la misma base PostgreSQL ($conn).
                $stmt = $conn->prepare($sql);

                if ($stmt) {
                    $types = str_repeat('s', count($fields));
                    $stmt->bind_param($types, ...array_values($data));
                    $success = $stmt->execute();
                    if ($success) {
                        $newId = $conn->insert_id;
                        foreach ($data as $key => $value) {
                            registrarLog($table, $key, null, $value, $usuario_actual);
                        }
                        echo json_encode(['success' => true, 'id' => $newId]);
                    } else {
                        echo json_encode(['success' => false, 'message' => $stmt->error]);
                    }
                    $stmt->close();
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error preparando consulta']);
                }
            }
            break;

        case 'delete':
            // Usar prepared statement
            if ($table === 'usuarios') {
                $stmt = $conn->prepare("DELETE FROM Usuarios WHERE id = ?");
            } elseif ($table === 'sites') {
                $stmt = $conn->prepare("DELETE FROM sites WHERE id = ?");
            } elseif ($table === 'cartel') {
                $stmt = $conn->prepare("DELETE FROM sign_prices WHERE id = ?");
            } else {
                echo json_encode(['error' => 'Eliminación no soportada para esta tabla']);
                exit;
            }
            if ($stmt) {
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    registrarLog($table, 'id', $id, null, $usuario_actual);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => $stmt->error]);
                }
                $stmt->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Error preparando consulta']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno: ' . $e->getMessage()]);
}
?>