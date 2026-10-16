
<?php

// INYOU - CONFIGURACION ADMINISTRATIVA
// Compatible con PHP 8.3 y Hostinger

// Configuracion de errores
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Cabeceras de seguridad
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');

// Exigir conexion HTTPS
if (
    empty($_SERVER['HTTPS']) ||
    $_SERVER['HTTPS'] === 'off'
) {
    http_response_code(403);
    exit('Se requiere HTTPS.');
}

// Configuracion segura de sesiones
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_name('inyou_admin');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

// Iniciar sesion
if (session_status() === PHP_SESSION_NONE) {
    if (!session_start()) {
        error_log('InYou: No se pudo iniciar la sesion');
        http_response_code(503);
        exit('Servicio temporalmente no disponible.');
    }
}

// Escapar contenido HTML
function h(?string $s): string
{
    return htmlspecialchars(
        (string) $s,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Conexion segura a MySQL
function db(): PDO
{
    // Estructura Hostinger:
    //
    // dominio/
    //   inyou-private/
    //     db.php
    //   public_html/
    //     admin/
    //       bootstrap.php

    $path = dirname(__DIR__, 2)
          . '/inyou-private/db.php';

    if (!is_file($path)) {
        error_log('InYou: Archivo privado no encontrado');

        throw new RuntimeException(
            'Configuracion privada no disponible.'
        );
    }

    $config = require $path;

    if (
        !is_array($config) ||
        !isset(
            $config['host'],
            $config['database'],
            $config['username'],
            $config['password']
        )
    ) {
        throw new RuntimeException(
            'Configuracion de base de datos invalida.'
        );
    }

    return new PDO(
        'mysql:host=' . $config['host']
        . ';dbname=' . $config['database']
        . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false
        ]
    );
}

// Generar token CSRF
function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf'];
}

// Validar token CSRF
function validCsrf(): bool
{
    if (
        !isset($_POST['csrf']) ||
        !is_string($_POST['csrf'])
    ) {
        return false;
    }

    return hash_equals(
        csrf(),
        $_POST['csrf']
    );
}

// Verificar autenticacion administrativa
function requireAuth(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: /admin/login.php');
        exit;
    }
}
