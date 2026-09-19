<?php
/**
 * db_pg.php - Capa de compatibilidad mysqli -> PostgreSQL (PDO_pgsql)
 *
 * Permite que el código existente que usa la API de mysqli (prepare / bind_param /
 * execute / get_result / fetch_assoc / fetch_all / num_rows / query / insert_id)
 * funcione contra PostgreSQL SIN reescribir cada sentencia.
 *
 * Toda la aplicación usa una única conexión PostgreSQL ($conn), y $conn_clientes
 * es un alias de $conn (definido en config.php). La antigua conexión IOT
 * ($conn_iot, MySQL) se retiró junto con el módulo de Dispositivos.
 *
 * Detalles importantes:
 *  - Los placeholders "?" posicionales de mysqli son compatibles con PDO.
 *  - bind_param($types, ...) recibe las variables POR REFERENCIA (igual que mysqli):
 *    los valores se leen recién en execute().
 *  - Las filas devueltas son objetos PgRow con acceso a claves INSENSIBLE a
 *    mayúsculas/minúsculas, para que $row['MAC'] funcione aunque PostgreSQL
 *    devuelva la columna como 'mac'. Se comportan como array en foreach,
 *    json_encode(), isset() y acceso por índice.
 *
 * Requiere la extensión pdo_pgsql habilitada en PHP.
 */

if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}
if (!defined('MYSQLI_NUM')) {
    define('MYSQLI_NUM', 2);
}
if (!defined('MYSQLI_BOTH')) {
    define('MYSQLI_BOTH', 3);
}

/**
 * Fila con acceso a claves insensible a mayúsculas/minúsculas.
 * Se comporta como un array asociativo para las operaciones habituales.
 */
class PgRow implements ArrayAccess, IteratorAggregate, JsonSerializable, Countable
{
    /** @var array<string,mixed> Datos con las claves tal cual las devuelve la BD. */
    private $data;
    /** @var array<string,string> Mapa clave-en-minúscula => clave-real. */
    private $lower;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->lower = [];
        foreach ($data as $k => $_) {
            $this->lower[strtolower((string) $k)] = $k;
        }
    }

    private function realKey($offset)
    {
        if (array_key_exists($offset, $this->data)) {
            return $offset;
        }
        $lk = strtolower((string) $offset);
        return $this->lower[$lk] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        // Semántica de isset()/?? igual que un array asociativo: un valor NULL
        // se considera "no seteado" (así $row['col'] ?? 'x' devuelve 'x' si es NULL).
        $k = $this->realKey($offset);
        return $k !== null && $this->data[$k] !== null;
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $k = $this->realKey($offset);
        return $k === null ? null : $this->data[$k];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->data[] = $value;
            return;
        }
        $k = $this->realKey($offset);
        if ($k === null) {
            $k = $offset;
            $this->lower[strtolower((string) $offset)] = $offset;
        }
        $this->data[$k] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        $k = $this->realKey($offset);
        if ($k !== null) {
            unset($this->data[$k]);
            unset($this->lower[strtolower((string) $offset)]);
        }
    }

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->data);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->data;
    }

    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->data);
    }

    /** Devuelve el array plano (claves originales de la BD). */
    public function toArray()
    {
        return $this->data;
    }
}

/**
 * Resultado de una consulta (equivalente a mysqli_result).
 */
class PgResult
{
    /** @var array<int,array> Filas crudas ya obtenidas. */
    private $rows;
    /** @var int Puntero para fetch_assoc() secuencial. */
    private $cursor = 0;
    /** @var int */
    public $num_rows = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    /** Devuelve la siguiente fila como PgRow (o null si no hay más). */
    public function fetch_assoc()
    {
        if ($this->cursor >= count($this->rows)) {
            return null;
        }
        return new PgRow($this->rows[$this->cursor++]);
    }

    /** Devuelve todas las filas como array de PgRow. */
    public function fetch_all($mode = MYSQLI_ASSOC)
    {
        $out = [];
        foreach ($this->rows as $r) {
            $out[] = new PgRow($r);
        }
        return $out;
    }

    /** Devuelve la siguiente fila como array numérico. */
    public function fetch_row()
    {
        if ($this->cursor >= count($this->rows)) {
            return null;
        }
        return array_values($this->rows[$this->cursor++]);
    }

    public function free() { $this->rows = []; }
    public function close() { $this->rows = []; }
    public function data_seek($n) { $this->cursor = (int) $n; }
}

/**
 * Sentencia preparada (equivalente a mysqli_stmt).
 */
class PgStatement
{
    /** @var PDOStatement */
    private $stmt;
    /** @var PgConnection */
    private $conn;
    /** @var string Cadena de tipos de bind_param (ej. "iss"). */
    private $types = '';
    /** @var array Referencias a las variables enlazadas. */
    private $params = [];
    /** @var array|null Filas obtenidas tras execute (para store_result/num_rows). */
    private $buffered = null;
    /** @var bool Si la sentencia es un INSERT (para refrescar insert_id solo entonces). */
    private $isInsert = false;
    /** @var string SQL de la sentencia (para incluirlo en los logs de error). */
    private $sql = '';

    public $error = '';
    public $errno = 0;
    public $num_rows = 0;
    public $affected_rows = 0;
    public $insert_id = 0;

    public function __construct(PDOStatement $stmt, PgConnection $conn, $sql = '')
    {
        $this->stmt = $stmt;
        $this->conn = $conn;
        $this->sql = (string) $sql;
        $this->isInsert = (bool) preg_match('/^\s*INSERT\b/i', (string) $sql);
    }

    /**
     * Enlaza parámetros por referencia, igual que mysqli::bind_param.
     * Los valores se leen en execute().
     */
    public function bind_param($types, &...$params)
    {
        $this->types = $types;
        $this->params = [];
        foreach ($params as $i => &$p) {
            $this->params[$i] = &$p;
        }
        unset($p);
        return true;
    }

    public function execute()
    {
        try {
            foreach ($this->params as $i => $value) {
                $type = $this->types[$i] ?? 's';
                $pdoType = PDO::PARAM_STR;
                if ($type === 'i') {
                    $value = ($value === null) ? null : (int) $value;
                    $pdoType = ($value === null) ? PDO::PARAM_NULL : PDO::PARAM_INT;
                } elseif ($type === 'd') {
                    // Decimal/float: se envía como literal numérico (string sin comillas lógicas).
                    $value = ($value === null) ? null : (float) $value;
                    $pdoType = ($value === null) ? PDO::PARAM_NULL : PDO::PARAM_STR;
                } else { // 's' y 'b'
                    $value = ($value === null) ? null : (string) $value;
                    $pdoType = ($value === null) ? PDO::PARAM_NULL : PDO::PARAM_STR;
                }
                // PDO usa índices 1-based para "?".
                $this->stmt->bindValue($i + 1, $value, $pdoType);
            }
            $ok = $this->stmt->execute();
            $this->affected_rows = $this->stmt->rowCount();
            // insert_id solo tiene sentido tras un INSERT (evita el error
            // "lastval is not yet defined in this session" en SELECT/UPDATE/DELETE).
            if ($this->isInsert) {
                $this->conn->refreshInsertId();
                $this->insert_id = $this->conn->insert_id;
            }
            $this->buffered = null; // se llena bajo demanda
            $this->error = '';
            $this->errno = 0;
            return $ok;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->errno = (int) $e->getCode();
            $this->conn->error = $this->error;
            error_log('[db_pg] execute error: ' . $e->getMessage() . ' | SQL: ' . $this->sql);
            return false;
        }
    }

    /** Devuelve un PgResult con las filas del SELECT ejecutado. */
    public function get_result()
    {
        if ($this->buffered !== null) {
            // Ya se bufferizó (p.ej. con store_result()): reutilizar.
            $rows = $this->buffered;
        } else {
            try {
                $rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $rows = [];
            }
        }
        $this->buffered = $rows;
        $this->num_rows = count($rows);
        return new PgResult($rows);
    }

    /** Bufferiza el resultado para poder leer num_rows (equivalente a mysqli). */
    public function store_result()
    {
        if ($this->buffered === null) {
            try {
                $this->buffered = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $this->buffered = [];
            }
        }
        $this->num_rows = count($this->buffered);
        return true;
    }

    public function close()
    {
        $this->stmt = null;
        $this->buffered = null;
        return true;
    }
}

/**
 * Conexión (equivalente a mysqli).
 */
class PgConnection
{
    /** @var PDO|null */
    private $pdo;
    public $connect_error = '';
    public $error = '';
    public $errno = 0;
    public $insert_id = 0;
    public $affected_rows = 0;

    public function __construct($host, $user, $pass, $dbname, $port = 5432)
    {
        try {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Emular prepares evita errores de tipo "operator does not exist: integer = text"
                // y hace que los "?" posicionales de mysqli funcionen de forma transparente.
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            $this->pdo->exec("SET client_encoding TO 'UTF8'");
        } catch (PDOException $e) {
            $this->connect_error = $e->getMessage();
            $this->pdo = null;
        }
    }

    public function prepare($sql)
    {
        if (!$this->pdo) {
            $this->error = 'Sin conexión';
            return false;
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            return new PgStatement($stmt, $this, $sql);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->errno = (int) $e->getCode();
            error_log('[db_pg] prepare error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return false;
        }
    }

    /**
     * Ejecuta una consulta directa.
     * Para SELECT devuelve PgResult; para el resto, true/false.
     */
    public function query($sql)
    {
        if (!$this->pdo) {
            $this->error = 'Sin conexión';
            return false;
        }
        try {
            $stmt = $this->pdo->query($sql);
            $this->error = '';
            $this->errno = 0;
            if (preg_match('/^\s*(SELECT|WITH|SHOW|VALUES|TABLE)\b/i', $sql)) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return new PgResult($rows);
            }
            $this->affected_rows = $stmt->rowCount();
            if (preg_match('/^\s*INSERT\b/i', $sql)) {
                $this->refreshInsertId();
            }
            return true;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->errno = (int) $e->getCode();
            error_log('[db_pg] query error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return false;
        }
    }

    /** Actualiza insert_id con el último valor de secuencia generado. */
    public function refreshInsertId()
    {
        if (!$this->pdo) {
            $this->insert_id = 0;
            return;
        }
        try {
            $this->insert_id = (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->insert_id = 0;
        }
    }

    /** No-op: en PostgreSQL el charset se fija en la conexión. */
    public function set_charset($charset) { return true; }

    public function begin_transaction()
    {
        return $this->pdo ? $this->pdo->beginTransaction() : false;
    }

    public function commit()
    {
        return $this->pdo ? $this->pdo->commit() : false;
    }

    public function rollback()
    {
        return $this->pdo ? $this->pdo->rollBack() : false;
    }

    public function real_escape_string($value)
    {
        if (!$this->pdo) {
            return addslashes((string) $value);
        }
        $quoted = $this->pdo->quote((string) $value);
        // pdo->quote agrega comillas alrededor; las quitamos para imitar real_escape_string.
        return substr($quoted, 1, -1);
    }

    public function close()
    {
        $this->pdo = null;
        return true;
    }

    /** Acceso directo al PDO subyacente si hiciera falta. */
    public function pdo()
    {
        return $this->pdo;
    }
}

/**
 * Equivalente a array_column() pero compatible con filas PgRow (que son objetos
 * con acceso ArrayAccess, no arrays planos). Extrae los valores de una "columna".
 *
 * @param array $rows Array de PgRow o de arrays asociativos.
 * @param string $key Nombre de la columna (insensible a mayúsculas en PgRow).
 * @return array
 */
function db_column($rows, $key)
{
    $out = [];
    foreach ($rows as $r) {
        if ($r instanceof PgRow) {
            $out[] = $r[$key];
        } elseif (is_array($r)) {
            $out[] = $r[$key] ?? null;
        }
    }
    return $out;
}
