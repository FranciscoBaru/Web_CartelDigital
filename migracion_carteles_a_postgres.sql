-- ============================================================================
-- Migración CARTELES (MySQL) -> PostgreSQL
-- Ajustes sobre la tabla compartida sign_prices para que el proyecto
-- Web_CartelDigital (PHP) pueda operar sobre ella (antes: tabla "Cartel").
--
-- Mapeo Cartel (PHP) -> sign_prices (PostgreSQL):
--   precio1..5   -> price1..5     (se convierten a NUMERIC en este script)
--   lama1..5     -> linea1..5
--   estado485    -> est_485
--   estadovox    -> est_cont      (sin cambios de esquema, solo en las consultas)
--   idproducto1..5 -> se AGREGAN en este script (no existían)
--   MAC          -> mac
--   IP_LAN       -> ip_lan
--   fecha/hora   -> updated_at
--
-- La tabla "poleo" NO se migra: el estado WFT se calcula desde sign_prices.
-- ============================================================================

BEGIN;

-- 1) Columnas de producto por línea (el PHP las lee y escribe; no existían en sign_prices).
ALTER TABLE sign_prices ADD COLUMN IF NOT EXISTS idproducto1 INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sign_prices ADD COLUMN IF NOT EXISTS idproducto2 INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sign_prices ADD COLUMN IF NOT EXISTS idproducto3 INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sign_prices ADD COLUMN IF NOT EXISTS idproducto4 INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sign_prices ADD COLUMN IF NOT EXISTS idproducto5 INTEGER NOT NULL DEFAULT 0;

-- 2) Precios: de VARCHAR(5) '0000' a NUMERIC (decimal).
--    ATENCIÓN: sign_prices también la usa el proyecto Java (carteles-precio),
--    que hoy lee/escribe price como String. Revisar ese lado tras esta migración
--    (getString sobre NUMERIC devolverá, p.ej., "1250.00").
ALTER TABLE sign_prices ALTER COLUMN price1 DROP DEFAULT;
ALTER TABLE sign_prices ALTER COLUMN price1 TYPE NUMERIC(10,2)
    USING COALESCE(NULLIF(regexp_replace(price1, '[^0-9.\-]', '', 'g'), '')::numeric, 0);
ALTER TABLE sign_prices ALTER COLUMN price1 SET DEFAULT 0;
ALTER TABLE sign_prices ALTER COLUMN price1 SET NOT NULL;

ALTER TABLE sign_prices ALTER COLUMN price2 DROP DEFAULT;
ALTER TABLE sign_prices ALTER COLUMN price2 TYPE NUMERIC(10,2)
    USING COALESCE(NULLIF(regexp_replace(price2, '[^0-9.\-]', '', 'g'), '')::numeric, 0);
ALTER TABLE sign_prices ALTER COLUMN price2 SET DEFAULT 0;
ALTER TABLE sign_prices ALTER COLUMN price2 SET NOT NULL;

ALTER TABLE sign_prices ALTER COLUMN price3 DROP DEFAULT;
ALTER TABLE sign_prices ALTER COLUMN price3 TYPE NUMERIC(10,2)
    USING COALESCE(NULLIF(regexp_replace(price3, '[^0-9.\-]', '', 'g'), '')::numeric, 0);
ALTER TABLE sign_prices ALTER COLUMN price3 SET DEFAULT 0;
ALTER TABLE sign_prices ALTER COLUMN price3 SET NOT NULL;

ALTER TABLE sign_prices ALTER COLUMN price4 DROP DEFAULT;
ALTER TABLE sign_prices ALTER COLUMN price4 TYPE NUMERIC(10,2)
    USING COALESCE(NULLIF(regexp_replace(price4, '[^0-9.\-]', '', 'g'), '')::numeric, 0);
ALTER TABLE sign_prices ALTER COLUMN price4 SET DEFAULT 0;
ALTER TABLE sign_prices ALTER COLUMN price4 SET NOT NULL;

ALTER TABLE sign_prices ALTER COLUMN price5 DROP DEFAULT;
ALTER TABLE sign_prices ALTER COLUMN price5 TYPE NUMERIC(10,2)
    USING COALESCE(NULLIF(regexp_replace(price5, '[^0-9.\-]', '', 'g'), '')::numeric, 0);
ALTER TABLE sign_prices ALTER COLUMN price5 SET DEFAULT 0;
ALTER TABLE sign_prices ALTER COLUMN price5 SET NOT NULL;

COMMIT;

-- Nota: estadovox se mapea a est_cont y estado485 a est_485 en las consultas PHP;
-- no requieren cambios de esquema.


-- ============================================================================
-- Tablas que venían de la base CLIENTES (MySQL) y aún NO estaban en PostgreSQL.
-- Se crean acá porque MySQL se apaga y estas funciones se mantienen.
--
-- IMPORTANTE: este DDL crea la ESTRUCTURA. Los DATOS existentes (usuarios,
-- roles y relaciones) deben MIGRARSE desde MySQL a PostgreSQL ANTES de apagar
-- MySQL; de lo contrario la tabla usuarios queda vacía y nadie podrá iniciar
-- sesión.
--
-- Columnas en minúsculas (consistente con el resto de tablas ya migradas). El
-- código PHP accede con distintas mayúsculas ($row['Nombre'], $row['Email']...):
-- eso lo resuelve la capa de compatibilidad db_pg.php (acceso insensible a
-- mayúsculas/minúsculas).
-- ============================================================================

CREATE TABLE IF NOT EXISTS roles (
    id  SERIAL PRIMARY KEY,
    rol VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS usuarios (
    id                 BIGSERIAL PRIMARY KEY,
    nombre             VARCHAR(150) NOT NULL DEFAULT '',
    usuario            VARCHAR(100) NOT NULL DEFAULT '',
    email              VARCHAR(255) NOT NULL DEFAULT '',
    password           VARCHAR(255) NOT NULL DEFAULT '',
    rol                INTEGER      NOT NULL DEFAULT 1,
    petrolera          VARCHAR(100) NOT NULL DEFAULT '',
    email_verified     SMALLINT     NOT NULL DEFAULT 0,
    dni                VARCHAR(20)  NOT NULL DEFAULT '',
    telefono           VARCHAR(30)  NOT NULL DEFAULT '',
    provincia          VARCHAR(100) NOT NULL DEFAULT '',
    verification_token VARCHAR(64),
    reset_token        VARCHAR(64),
    reset_expires      TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_usuarios_usuario ON usuarios (usuario);
CREATE INDEX IF NOT EXISTS idx_usuarios_email   ON usuarios (email);
CREATE INDEX IF NOT EXISTS idx_usuarios_dni     ON usuarios (dni);

CREATE TABLE IF NOT EXISTS relaciones (
    id        BIGSERIAL PRIMARY KEY,
    apies     INTEGER   NOT NULL DEFAULT 0,
    idusuario BIGINT    NOT NULL DEFAULT 0,
    dni       VARCHAR(20) NOT NULL DEFAULT '',
    estado    SMALLINT  NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_relaciones_apies ON relaciones (apies);
CREATE INDEX IF NOT EXISTS idx_relaciones_dni   ON relaciones (dni);

