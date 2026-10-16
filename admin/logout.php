
<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Solo permitir solicitudes POST con CSRF válido.
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !validCsrf()
) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Eliminar los datos de la sesión.
$_SESSION = [];

// Eliminar la cookie de sesión.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite']
    ]);
}

// Destruir la sesión.
session_destroy();

// Volver al login.
header('Location: /admin/login.php', true, 303);
exit;
