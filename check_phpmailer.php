<?php
require_once __DIR__ . '/credenciales.php';

header('Content-Type: text/plain; charset=utf-8');

$paths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/vendor/src/Exception.php',
    __DIR__ . '/vendor/src/PHPMailer.php',
    __DIR__ . '/vendor/src/SMTP.php',
    __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php',
    __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php',
    __DIR__ . '/vendor/PHPMailer/src/Exception.php',
    __DIR__ . '/vendor/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/vendor/PHPMailer/src/SMTP.php',
    __DIR__ . '/PHPMailer/src/Exception.php',
    __DIR__ . '/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/PHPMailer/src/SMTP.php',
    __DIR__ . '/PHPMailer-master/src/Exception.php',
    __DIR__ . '/PHPMailer-master/src/PHPMailer.php',
    __DIR__ . '/PHPMailer-master/src/SMTP.php',
];

echo "Directorio base: " . __DIR__ . PHP_EOL . PHP_EOL;

foreach ($paths as $path) {
    echo (file_exists($path) ? '[OK] ' : '[NO] ') . $path . PHP_EOL;
}

echo PHP_EOL . "Busqueda de archivos PHPMailer dentro del sitio:" . PHP_EOL;
$found = [];
$root = new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($root);
$required = ['exception.php', 'phpmailer.php', 'smtp.php'];

foreach ($iterator as $file) {
    if ($file->isFile() && in_array(strtolower($file->getFilename()), $required, true)) {
        $found[] = $file->getPathname();
    }
}

if (empty($found)) {
    echo "No se encontro ningun archivo de PHPMailer dentro de " . __DIR__ . PHP_EOL;
} else {
    foreach ($found as $path) {
        echo "[FOUND] " . $path . PHP_EOL;
    }
}
