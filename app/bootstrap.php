<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' https:; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

class ValidationException extends RuntimeException {}

function h(mixed $value): string {
    return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO {
    static $connection = null;
    if ($connection === null) {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $name = getenv('DB_NAME') ?: 'catalogo_demo';
        $user = getenv('DB_USER') ?: 'catalogo';
        $password = getenv('DB_PASSWORD');
        if (!$password) {
            throw new RuntimeException('Falta configurar el acceso privado a la base.');
        }
        $connection = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $connection;
}

function query(string $sql, array $params = []): PDOStatement {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function campo(string $name, int $max = 200): string {
    $value = $_POST[$name] ?? '';
    if (!is_string($value) || strlen(trim($value)) > $max) {
        throw new ValidationException('Revisá el formato o la longitud de los datos.');
    }
    return trim($value);
}

function entero(string $name, int $max = PHP_INT_MAX): int {
    $value = filter_var($_POST[$name] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => $max]
    ]);
    if ($value === false) throw new ValidationException('El identificador o la cantidad no es válido.');
    return $value;
}

function tokenCsrf(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function camposCsrf(): void {
    echo '<input type="hidden" name="csrf" value="' . h(tokenCsrf()) . '">';
}

function fallo(int $status, string $message): never {
    http_response_code($status);
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Catálogo demo</title><p>' .
        h($message) . '</p><a href="index.php">Volver al catálogo</a></html>';
    exit;
}

function verificarCsrf(): void {
    $value = $_POST['csrf'] ?? null;
    if (!is_string($value) || !hash_equals(tokenCsrf(), $value)) {
        fallo(403, 'El formulario venció o no es válido. Volvé a cargar la página.');
    }
}

function ir(string $view = 'catalogo', array $params = []): never {
    header('Location: index.php?' . http_build_query(['vista' => $view] + $params), true, 303);
    exit;
}

function usuario(): ?array {
    if (empty($_SESSION['usuario_id'])) return null;
    return query('SELECT id, nombre, email, rol FROM usuarios WHERE id = ?', [$_SESSION['usuario_id']])->fetch() ?: null;
}

function exigirUsuario(): array {
    $user = usuario();
    if (!$user) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') fallo(403, 'Iniciá sesión para continuar.');
        ir('login');
    }
    return $user;
}

function exigirAdmin(): array {
    $user = exigirUsuario();
    if ($user['rol'] !== 'admin') fallo(403, 'Esta sección requiere permisos de administrador.');
    return $user;
}

function precioValidado(string $value): string {
    $value = str_replace(',', '.', $value);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value) || (float)$value <= 0) {
        throw new ValidationException('El precio debe ser positivo y tener hasta dos decimales.');
    }
    return number_format((float)$value, 2, '.', '');
}

function productoPost(): array {
    $name = campo('nombre_producto', 120);
    $description = campo('descripcion_producto', 2000);
    $image = campo('imagen_producto', 500) ?: 'cards/img/images.jpg';
    if (!$name || !$description) throw new ValidationException('Completá el nombre y la descripción.');
    $local = preg_match('#^cards/img/[a-zA-Z0-9 .()_-]+\.(?:png|jpe?g)$#D', $image);
    $https = filter_var($image, FILTER_VALIDATE_URL) && parse_url($image, PHP_URL_SCHEME) === 'https';
    if (!$local && !$https) throw new ValidationException('Usá una imagen local del catálogo o una URL HTTPS.');
    if ($local && !is_file(dirname(__DIR__) . '/proyecto integrador/' . $image) &&
        !is_file('/var/www/html/' . $image)) {
        throw new ValidationException('No se encontró la imagen local.');
    }
    return [$name, precioValidado(campo('precio_producto', 20)), $description, $image];
}

function centavos(string $price): int {
    [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '0');
    return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
}

function dinero(int $cents): string {
    return '$' . number_format($cents / 100, 2, ',', '.');
}

function carrito(): array {
    $items = [];
    foreach ($_SESSION['carrito'] ?? [] as $id => $quantity) {
        $product = query('SELECT * FROM productos WHERE id_producto = ?', [$id])->fetch();
        if (!$product) {
            unset($_SESSION['carrito'][$id]);
            continue;
        }
        $product['cantidad'] = $quantity;
        $product['subtotal'] = centavos((string)$product['precio_producto']) * $quantity;
        $items[] = $product;
    }
    return $items;
}

function crearPedido(array $user, string $token): int {
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) throw new ValidationException('Confirmación de pedido inválida.');
    $existing = query('SELECT id FROM pedidos WHERE token_pedido = ? AND usuario_id = ?', [$token, $user['id']])->fetch();
    if ($existing) return (int)$existing['id'];
    if (!hash_equals($_SESSION['pedido_token'] ?? '', $token) || empty($_SESSION['carrito'])) {
        throw new ValidationException('El carrito está vacío o la confirmación venció.');
    }
    db()->beginTransaction();
    try {
        $items = [];
        $total = 0;
        $cart = $_SESSION['carrito'];
        ksort($cart);
        foreach ($cart as $id => $quantity) {
            $product = query('SELECT * FROM productos WHERE id_producto = ? FOR UPDATE', [$id])->fetch();
            if (!$product) throw new ValidationException('Un producto ya no está disponible. Revisá el carrito.');
            $total += centavos((string)$product['precio_producto']) * $quantity;
            $items[] = [$product, $quantity];
        }
        if ($total > 999999999999) throw new ValidationException('El total supera el límite de la demostración.');
        query('INSERT INTO pedidos (usuario_id, token_pedido, total) VALUES (?, ?, ?)',
            [$user['id'], $token, number_format($total / 100, 2, '.', '')]);
        $orderId = (int)db()->lastInsertId();
        foreach ($items as [$product, $quantity]) {
            query('INSERT INTO pedido_items (pedido_id, producto_id, nombre, cantidad, precio_unitario) VALUES (?, ?, ?, ?, ?)',
                [$orderId, $product['id_producto'], $product['nombre_producto'], $quantity, $product['precio_producto']]);
        }
        db()->commit();
        $_SESSION['carrito'] = [];
        unset($_SESSION['pedido_token']);
        return $orderId;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
}
