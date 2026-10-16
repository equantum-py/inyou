<?php
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');

    if (strlen($password) < 12) {
        exit('La contraseña debe tener al menos 12 caracteres.');
    }

    echo '<p>Copiá este hash en phpMyAdmin:</p>';
    echo '<textarea rows="5" cols="85" readonly>';
    echo htmlspecialchars(
        password_hash($password, PASSWORD_DEFAULT),
        ENT_QUOTES,
        'UTF-8'
    );
    echo '</textarea>';
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Generar contraseña InYou</title>
</head>
<body>
<h2>Generar contraseña del administrador</h2>
<form method="POST">
    <input type="password" name="password" minlength="12" required>
    <button type="submit">Generar hash</button>
</form>
</body>
</html>