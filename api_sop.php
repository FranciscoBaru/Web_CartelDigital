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

// Tablas permitidas
$allowedTables = ['sites', 'usuarios', 'cartel', 'dispositivos', 'logs', 'roles', 'petroleras'];
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
                $result = $conn_clientes->query("SELECT u.*, r.rol as rol_nombre FROM Usuarios u LEFT JOIN roles r ON u.rol = r.id");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
            } elseif ($table === 'cartel') {
                $result = $conn->query("SELECT * FROM Cartel ORDER BY id DESC");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
            } elseif ($table === 'dispositivos') {
                $conn_iot = new mysqli(IOT_DB_HOST, IOT_DB_USER, IOT_DB_PASS, IOT_DB_NAME);
                $result = $conn_iot->query("SELECT * FROM Dispositivos ORDER BY id DESC");
                $data = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['data' => $data]);
                $conn_iot->close();
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
                $sql = "UPDATE $table SET " . implode(', ', $fields) . " WHERE id = ?";
                
                // Seleccionar conexión correcta
                if ($table === 'usuarios' || $table === 'logs' || $table === 'roles') {
                    $stmt = $conn_clientes->prepare($sql);
                } elseif ($table === 'dispositivos') {
                    $conn_iot = new mysqli(IOT_DB_HOST, IOT_DB_USER, IOT_DB_PASS, IOT_DB_NAME);
                    $stmt = $conn_iot->prepare($sql);
                } else {
                    $stmt = $conn->prepare($sql);
                }
                
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
                if ($table === 'dispositivos') $conn_iot->close();
            } else { // insert
                $fields = array_keys($data);
                $placeholders = implode(',', array_fill(0, count($fields), '?'));
                $sql = "INSERT INTO $table (" . implode(',', $fields) . ") VALUES ($placeholders)";
                
                // Seleccionar conexión
                if ($table === 'usuarios' || $table === 'logs' || $table === 'roles') {
                    $stmt = $conn_clientes->prepare($sql);
                } elseif ($table === 'dispositivos') {
                    $conn_iot = new mysqli(IOT_DB_HOST, IOT_DB_USER, IOT_DB_PASS, IOT_DB_NAME);
                    $stmt = $conn_iot->prepare($sql);
                } else {
                    $stmt = $conn->prepare($sql);
                }
                
                if ($stmt) {
                    $types = str_repeat('s', count($fields));
                    $stmt->bind_param($types, ...array_values($data));
                    $success = $stmt->execute();
                    if ($success) {
                        $newId = ($table === 'usuarios') ? $conn_clientes->insert_id : $conn->insert_id;
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
                if ($table === 'dispositivos') $conn_iot->close();
            }
            break;

        case 'delete':
            // Usar prepared statement
            if ($table === 'usuarios') {
                $stmt = $conn_clientes->prepare("DELETE FROM Usuarios WHERE id = ?");
            } elseif ($table === 'sites') {
                $stmt = $conn->prepare("DELETE FROM sites WHERE id = ?");
            } elseif ($table === 'cartel') {
                $stmt = $conn->prepare("DELETE FROM Cartel WHERE id = ?");
            } elseif ($table === 'dispositivos') {
                $conn_iot = new mysqli(IOT_DB_HOST, IOT_DB_USER, IOT_DB_PASS, IOT_DB_NAME);
                $stmt = $conn_iot->prepare("DELETE FROM Dispositivos WHERE id = ?");
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
            if ($table === 'dispositivos') $conn_iot->close();
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