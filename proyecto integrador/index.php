<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$vista = is_string($_GET['vista'] ?? null) ? $_GET['vista'] : 'catalogo';
if (!in_array($vista, ['catalogo','login','registro','carrito','pedido','pedidos','admin'], true)) {
    fallo(404, 'No se encontró esa página.');
}
$error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verificarCsrf();
        try {
            $action = campo('accion', 40);
            switch ($action) {
                case 'registro':
                    $name = campo('nombre', 120);
                    $email = strtolower(campo('email', 254));
                    $password = $_POST['password'] ?? '';
                    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                        !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
                        throw new ValidationException('Completá nombre, correo válido y contraseña de 12 a 72 bytes.');
                    }
                    if (query('SELECT id FROM usuarios WHERE email = ?', [$email])->fetch()) {
                        throw new ValidationException('Ese correo ya está registrado.');
                    }
                    query('INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?, ?, ?, ?)',
                        [$name, $email, password_hash($password, PASSWORD_DEFAULT), 'cliente']);
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = (int)db()->lastInsertId();
                    unset($_SESSION['csrf']);
                    ir();
                case 'login':
                    $attempt = $_SESSION['login_fallos'] ?? ['cantidad' => 0, 'inicio' => time()];
                    if (time() - $attempt['inicio'] > 60) $attempt = ['cantidad' => 0, 'inicio' => time()];
                    if ($attempt['cantidad'] >= 5) throw new ValidationException('Esperá un minuto antes de volver a intentar.', 429);
                    $email = strtolower(campo('email', 254));
                    $password = $_POST['password'] ?? '';
                    $account = query('SELECT * FROM usuarios WHERE email = ?', [$email])->fetch();
                    if (!$account || !is_string($password) || strlen($password) > 72 ||
                        !password_verify($password, $account['password_hash'])) {
                        $attempt['cantidad']++;
                        $_SESSION['login_fallos'] = $attempt;
                        throw new ValidationException('Correo o contraseña incorrectos.', 401);
                    }
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = (int)$account['id'];
                    unset($_SESSION['csrf'], $_SESSION['login_fallos']);
                    ir($account['rol'] === 'admin' ? 'admin' : 'catalogo');
                case 'logout':
                    $_SESSION = [];
                    session_regenerate_id(true);
                    ir();
                case 'crear_producto':
                case 'editar_producto':
                    exigirAdmin();
                    $values = productoPost();
                    if ($action === 'crear_producto') {
                        query('INSERT INTO productos (nombre_producto, precio_producto, descripcion_producto, imagen_producto) VALUES (?, ?, ?, ?)', $values);
                    } else {
                        $id = entero('id_producto');
                        if (!query('SELECT id_producto FROM productos WHERE id_producto = ?', [$id])->fetch()) {
                            throw new ValidationException('No se encontró el producto.');
                        }
                        $values[] = $id;
                        query('UPDATE productos SET nombre_producto = ?, precio_producto = ?, descripcion_producto = ?, imagen_producto = ? WHERE id_producto = ?', $values);
                    }
                    ir('admin');
                case 'eliminar_producto':
                    exigirAdmin();
                    query('DELETE FROM productos WHERE id_producto = ?', [entero('id_producto')]);
                    ir('admin');
                case 'agregar_carrito':
                    exigirUsuario();
                    $id = entero('id_producto');
                    $quantity = entero('cantidad', 99);
                    if (!query('SELECT id_producto FROM productos WHERE id_producto = ?', [$id])->fetch()) {
                        throw new ValidationException('Ese producto ya no está disponible.');
                    }
                    $quantity += $_SESSION['carrito'][$id] ?? 0;
                    if ($quantity > 99 || count($_SESSION['carrito'] ?? []) >= 20 && !isset($_SESSION['carrito'][$id])) {
                        throw new ValidationException('Límite de la demo: 20 productos diferentes y 99 unidades por producto.');
                    }
                    $_SESSION['carrito'][$id] = $quantity;
                    ir('carrito');
                case 'quitar_carrito':
                    exigirUsuario();
                    unset($_SESSION['carrito'][entero('id_producto')]);
                    ir('carrito');
                case 'confirmar_pedido':
                    $user = exigirUsuario();
                    $id = crearPedido($user, campo('pedido_token', 32));
                    ir('pedido', ['id' => $id]);
                default:
                    throw new ValidationException('Acción no válida.');
            }
        } catch (ValidationException $exception) {
            http_response_code($exception->getCode() ?: 422);
            $error = $exception->getMessage();
        }
    }
    $user = usuario();
    if (in_array($vista, ['carrito','pedido','pedidos'], true)) $user = exigirUsuario();
    if ($vista === 'admin') $user = exigirAdmin();
    $products = query('SELECT * FROM productos ORDER BY id_producto')->fetchAll();
    $items = $vista === 'carrito' ? carrito() : [];
    $total = array_sum(array_column($items, 'subtotal'));
    $edit = null;
    if ($vista === 'admin' && isset($_GET['editar'])) {
        $id = filter_var($_GET['editar'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) fallo(404, 'Producto no encontrado.');
        $edit = query('SELECT * FROM productos WHERE id_producto = ?', [$id])->fetch();
        if (!$edit) fallo(404, 'Producto no encontrado.');
    }
    $order = null;
    $orderItems = [];
    if ($vista === 'pedido') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) fallo(404, 'Pedido no encontrado.');
        $order = query('SELECT * FROM pedidos WHERE id = ? AND usuario_id = ?', [$id, $user['id']])->fetch();
        if (!$order) fallo(404, 'Pedido no encontrado.');
        $orderItems = query('SELECT * FROM pedido_items WHERE pedido_id = ?', [$id])->fetchAll();
    }
    $orders = $vista === 'pedidos' ? query('SELECT id, total, creado_en FROM pedidos WHERE usuario_id = ? ORDER BY id DESC', [$user['id']])->fetchAll() : [];
} catch (Throwable $exception) {
    fallo(503, 'No fue posible completar la operación. Revisá el servicio y la configuración local.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catálogo de lámparas | Demo académica</title>
    <link rel="stylesheet" href="app.css">
</head>
<body>
<header>
    <a class="marca" href="index.php">Lámparas · Demo</a>
    <nav aria-label="Principal">
        <a href="index.php">Catálogo</a>
        <?php if ($user): ?>
            <a href="?vista=carrito">Carrito</a>
            <a href="?vista=pedidos">Mis pedidos</a>
            <?php if ($user['rol'] === 'admin'): ?><a href="?vista=admin">Administrar</a><?php endif; ?>
            <form method="post">
                <?php camposCsrf(); ?><input type="hidden" name="accion" value="logout">
                <button type="submit">Cerrar sesión</button>
            </form>
        <?php else: ?>
            <a href="?vista=login">Ingresar</a>
            <a href="?vista=registro">Crear cuenta</a>
        <?php endif; ?>
    </nav>
</header>
<main>
    <p class="aviso">Demostración académica con productos ficticios. Los pedidos son simulados y no se realizan cobros.</p>
    <?php if ($error): ?><p role="alert" class="error"><?= h($error) ?></p><?php endif; ?>

    <?php if ($vista === 'login' || $vista === 'registro'): ?>
        <section class="panel estrecho">
            <h1><?= $vista === 'registro' ? 'Crear cuenta de cliente' : 'Iniciar sesión' ?></h1>
            <form method="post">
                <?php camposCsrf(); ?><input type="hidden" name="accion" value="<?= h($vista) ?>">
                <?php if ($vista === 'registro'): ?>
                    <label>Nombre <input name="nombre" maxlength="120" autocomplete="name" required></label>
                <?php endif; ?>
                <label>Correo <input type="email" name="email" maxlength="254" autocomplete="username" required></label>
                <label>Contraseña <input type="password" name="password" maxlength="72"
                    <?= $vista === 'registro' ? 'minlength="12" autocomplete="new-password"' : 'autocomplete="current-password"' ?> required></label>
                <?php if ($vista === 'registro'): ?><p>Usá entre 12 y 72 bytes; una frase larga sin datos personales.</p><?php endif; ?>
                <button type="submit"><?= $vista === 'registro' ? 'Registrarme' : 'Ingresar' ?></button>
            </form>
        </section>

    <?php elseif ($vista === 'admin'): ?>
        <h1>Administración de productos</h1>
        <section class="panel">
            <h2><?= $edit ? 'Editar producto' : 'Agregar producto' ?></h2>
            <form method="post" action="?vista=admin">
                <?php camposCsrf(); ?>
                <input type="hidden" name="accion" value="<?= $edit ? 'editar_producto' : 'crear_producto' ?>">
                <?php if ($edit): ?><input type="hidden" name="id_producto" value="<?= (int)$edit['id_producto'] ?>"><?php endif; ?>
                <label>Nombre <input name="nombre_producto" maxlength="120" value="<?= h($edit['nombre_producto'] ?? '') ?>" required></label>
                <label>Precio de demostración <input name="precio_producto" type="number" min="0.01" max="99999999.99" step="0.01" value="<?= h($edit['precio_producto'] ?? '') ?>" required></label>
                <label>Descripción <textarea name="descripcion_producto" maxlength="2000" required><?= h($edit['descripcion_producto'] ?? '') ?></textarea></label>
                <label>Imagen local o URL HTTPS <input name="imagen_producto" maxlength="500" value="<?= h($edit['imagen_producto'] ?? 'cards/img/images.jpg') ?>"></label>
                <button type="submit">Guardar producto</button>
                <?php if ($edit): ?><a href="?vista=admin">Cancelar edición</a><?php endif; ?>
            </form>
        </section>
        <div class="tabla"><table><thead><tr><th>ID</th><th>Producto</th><th>Precio</th><th>Acciones</th></tr></thead><tbody>
        <?php foreach ($products as $product): ?>
            <tr data-product-id="<?= (int)$product['id_producto'] ?>">
                <td><?= (int)$product['id_producto'] ?></td>
                <td><?= h($product['nombre_producto']) ?></td>
                <td><?= dinero(centavos((string)$product['precio_producto'])) ?></td>
                <td><a href="?vista=admin&amp;editar=<?= (int)$product['id_producto'] ?>">Editar</a>
                    <form method="post" action="?vista=admin" class="en-linea">
                        <?php camposCsrf(); ?><input type="hidden" name="accion" value="eliminar_producto">
                        <input type="hidden" name="id_producto" value="<?= (int)$product['id_producto'] ?>">
                        <button type="submit" class="peligro">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>

    <?php elseif ($vista === 'carrito'): ?>
        <h1>Tu carrito</h1>
        <?php if (!$items): ?><p>El carrito está vacío. <a href="index.php">Ver productos</a></p>
        <?php else: ?>
            <div class="tabla"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Subtotal</th><th>Acción</th></tr></thead><tbody>
                <?php foreach ($items as $item): ?>
                    <tr><td><?= h($item['nombre_producto']) ?></td><td><?= (int)$item['cantidad'] ?></td><td><?= dinero($item['subtotal']) ?></td>
                    <td><form method="post">
                        <?php camposCsrf(); ?><input type="hidden" name="accion" value="quitar_carrito">
                        <input type="hidden" name="id_producto" value="<?= (int)$item['id_producto'] ?>">
                        <button type="submit">Quitar</button>
                    </form></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <p class="total">Total: <?= dinero($total) ?></p>
            <form method="post">
                <?php camposCsrf(); ?><input type="hidden" name="accion" value="confirmar_pedido">
                <input type="hidden" name="pedido_token" value="<?= h($_SESSION['pedido_token'] ??= bin2hex(random_bytes(16))) ?>">
                <button type="submit">Confirmar pedido simulado</button>
            </form>
        <?php endif; ?>

    <?php elseif ($vista === 'pedido'): ?>
        <h1>Pedido simulado #<?= (int)$order['id'] ?></h1>
        <p>Guardado el <?= h($order['creado_en']) ?>. No se realizó ningún cobro.</p>
        <ul><?php foreach ($orderItems as $item): ?>
            <li><?= h($item['nombre']) ?> × <?= (int)$item['cantidad'] ?> · <?= dinero(centavos((string)$item['precio_unitario']) * (int)$item['cantidad']) ?></li>
        <?php endforeach; ?></ul>
        <p class="total">Total: <?= dinero(centavos((string)$order['total'])) ?></p>
        <a href="?vista=pedidos">Mis pedidos</a>

    <?php elseif ($vista === 'pedidos'): ?>
        <h1>Mis pedidos simulados</h1>
        <?php if (!$orders): ?><p>Todavía no registraste pedidos.</p><?php endif; ?>
        <ul><?php foreach ($orders as $entry): ?>
            <li><a href="?vista=pedido&amp;id=<?= (int)$entry['id'] ?>">Pedido #<?= (int)$entry['id'] ?></a> · <?= dinero(centavos((string)$entry['total'])) ?> · <?= h($entry['creado_en']) ?></li>
        <?php endforeach; ?></ul>

    <?php else: ?>
        <h1>Catálogo de lámparas</h1>
        <?php if (!$products): ?><p>No hay productos disponibles.</p><?php endif; ?>
        <div class="productos">
        <?php foreach ($products as $product): ?>
            <article class="panel producto">
                <img src="<?= h($product['imagen_producto']) ?>" alt="<?= h($product['nombre_producto']) ?>" loading="lazy">
                <h2><?= h($product['nombre_producto']) ?></h2>
                <p><?= h($product['descripcion_producto']) ?></p>
                <p class="precio"><?= dinero(centavos((string)$product['precio_producto'])) ?></p>
                <?php if ($user): ?>
                    <form method="post">
                        <?php camposCsrf(); ?><input type="hidden" name="accion" value="agregar_carrito">
                        <input type="hidden" name="id_producto" value="<?= (int)$product['id_producto'] ?>">
                        <label>Cantidad <input type="number" name="cantidad" min="1" max="99" value="1" required></label>
                        <button type="submit">Agregar al carrito</button>
                    </form>
                <?php else: ?><a href="?vista=login">Ingresar para agregar al carrito</a><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<footer>Proyecto académico de PHP y SQL · Inicio en 2023, revisión funcional en 2026.</footer>
</body>
</html>
