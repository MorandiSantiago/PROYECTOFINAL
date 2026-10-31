<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
$email = strtolower(trim(getenv('ADMIN_EMAIL') ?: ''));
$password = getenv('ADMIN_PASSWORD') ?: '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "Definir ADMIN_EMAIL y ADMIN_PASSWORD (12 a 72 bytes) de forma privada.\n");
    exit(1);
}
try {
    query('INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?, ?, ?, ?)',
        ['Administrador de demostración', $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
    echo "Administrador creado. No hay credenciales predeterminadas.\n";
} catch (PDOException $error) {
    fwrite(STDERR, "No se pudo crear el administrador. Revisar conexión o correo ya registrado.\n");
    exit(1);
}
