<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["addProduct"])) {
        // Agregar producto
        $nombre_producto = $_POST['nombre_producto'];
        $precio_producto = $_POST['precio_producto'];
        $descripcion_producto = $_POST['descripcion_producto'];
        $imagen_producto = $_POST['imagen_producto'];

        insertarProducto($nombre_producto, $precio_producto, $descripcion_producto, $imagen_producto);

       
        header("Location: {$_SERVER['PHP_SELF']}");
        exit();
    }
}

function insertarProducto($nombre, $precio, $descripcion, $imagen) {
    $conexion = mysqli_connect("localhost:3306", "root", "", "proyecto_integrador");

    $nombre = mysqli_real_escape_string($conexion, $nombre);
    $descripcion = mysqli_real_escape_string($conexion, $descripcion);

    $insertQuery = "INSERT INTO productos (nombre_producto, precio_producto, descripcion_producto, imagen_producto) VALUES ('$nombre', $precio, '$descripcion', '$imagen')";
    $result = mysqli_query($conexion, $insertQuery);

    if (!$result) {
        echo "Error al agregar el producto: " . mysqli_error($conexion);
    }

    mysqli_close($conexion);
}function loadProducts() {
    $conexion = mysqli_connect("localhost:3306", "root", "", "proyecto_integrador");

    $resultado = mysqli_query($conexion, "SELECT * FROM productos");

    while ($fila = mysqli_fetch_assoc($resultado)) {
        echo "<tr>";
        echo "<td>" . $fila['id_producto'] . "</td>";
        echo "<td><img src='" . $fila['imagen_producto'] . "' alt='" . $fila['nombre_producto'] . " Image'></td>";
        echo "<td>" . $fila['nombre_producto'] . "</td>";
        echo "<td>$" . $fila['precio_producto'] . "</td>";
        echo "<td>" . $fila['descripcion_producto'] . "</td>";
        echo "<td><a href='#' class='editar' onclick='abrirModal(" . json_encode($fila) . ")'>Editar</a></td>"; //json porque es un objeto
        echo "<td><a href='#' class='eliminar' onclick='confirmarEliminar(" . $fila['id_producto'] . ")'>Eliminar</a></td>";
        
        echo "</tr>";
    }

    mysqli_close($conexion);
}

if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET["deleteProduct"])) {
    $id_producto = $_GET['deleteProduct'];
    eliminarProducto($id_producto);

    
    header("Location: {$_SERVER['PHP_SELF']}");
    exit(); 
}

function eliminarProducto($id) {
    $conexion = mysqli_connect("localhost:3306", "root", "", "proyecto_integrador");

    $deleteQuery = "DELETE FROM productos WHERE id_producto = $id";
    $result = mysqli_query($conexion, $deleteQuery);

    if (!$result) {
        echo "Error al eliminar el producto: " . mysqli_error($conexion);
    }

    mysqli_close($conexion);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce</title>
    <link rel="stylesheet" href="styles.css">
    
</head>
<body>
<header>
        <h2 class="logo">Logo</h2>
        <nav class="navigation">
            <a href="../Login/index.html">Volver</a>
            
        </nav>
    </header>
   
    <h1>Panel de administrador</h1>
    <form id="productForm" method="post" action="index.php">
        <label for="nombre_producto">Nombre del producto:</label>
        <input type="text" id="nombre_producto" name="nombre_producto" required><br>

        <label for="precio_producto">Precio del producto:</label>
        <input type="number" id="precio_producto" name="precio_producto" required><br>

        <label for="descripcion_producto">Descripción del producto:</label>
        <textarea id="descripcion_producto" name="descripcion_producto" required></textarea><br>

        <label for="imagen_producto">URL de la imagen:</label>
        <input type="text" id="imagen_producto" name="imagen_producto"><br>

        <button type="submit" name="addProduct">Agregar producto</button>
    </form>

   
    <h1>Tabla de productos</h1>
    <table id="products">
        <thead>
            <tr>
                <th>ID</th>
                <th>Imagen</th>
                <th>Nombre</th>
                <th>Precio</th>
                <th>Descripción</th>
                <th>Editar</th>
                <th>Eliminar</th>
            </tr>
        </thead>
        <tbody>
            <?php
            loadProducts();
            ?>

        </tbody>
    </table>

    <!-- Modal para editar producto -->
    <div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="cerrarModal()">&times;</span>
            <h2>Edit Product</h2>
            <!-- Formulario de edicion -->
            <form id="editProductForm" onsubmit="return actualizarProducto()">

                <input type="hidden" id="edit_id_producto" name="edit_id_producto" value="">
                <label for="edit_nombre_producto">Nombre del producto:</label>
                <input type="text" id="edit_nombre_producto" name="edit_nombre_producto" required><br>

                <label for="edit_precio_producto">Precio del producto:</label>
                <input type="number" id="edit_precio_producto" name="edit_precio_producto" required><br>

                <label for="edit_descripcion_producto">Descripción del producto:</label>
                <textarea id="edit_descripcion_producto" name="edit_descripcion_producto" required></textarea><br>

                <label for="edit_imagen_producto">URL de la imagen:</label>
                <input type="text" id="edit_imagen_producto" name="edit_imagen_producto"><br>

                <button class="close-btn" type="submit" name="updateProduct">Actualizar producto</button>
                <button class="close-btn" onclick="cerrarModal()">Cerrar</button>
            </form>
        </div>
    </div>

    <!-- Js para el modal y la edicion -->
    <script>
       function abrirModal(producto) {
    llenarFormulario(producto);
    document.getElementById('editModal').style.display = 'block';
}


function llenarFormulario(producto) {
    document.getElementById('edit_id_producto').value = producto.id_producto;
    document.getElementById('edit_nombre_producto').value = producto.nombre_producto;
    document.getElementById('edit_precio_producto').value = producto.precio_producto;
    document.getElementById('edit_descripcion_producto').value = producto.descripcion_producto;
    document.getElementById('edit_imagen_producto').value = producto.imagen_producto;
}


        function cerrarModal() {
            
            document.getElementById('editModal').style.display = 'none';
        }
      
       function actualizarProducto() {
    // Datos del formulario
    var id = document.getElementById('edit_id_producto').value;
    var nombre = document.getElementById('edit_nombre_producto').value;
    var precio = document.getElementById('edit_precio_producto').value;
    var descripcion = document.getElementById('edit_descripcion_producto').value;
    var imagen = document.getElementById('edit_imagen_producto').value;

    // AJAX para actualizar el producto
    var xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState == 4) {
            if (this.status == 200) {
                // Si se modifico correctamente, mostrar mensaje, no anda :(
                alert("Se modificó correctamente");
                cerrarModal();
                location.reload();
            }
        }
    };

    xhttp.open("POST", "index.php?action=actualizar_producto", true);
    xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhttp.send("edit_id_producto=" + id + "&edit_nombre_producto=" + nombre + "&edit_precio_producto=" + precio + "&edit_descripcion_producto=" + descripcion + "&edit_imagen_producto=" + imagen);

    return false;
}


    </script>

    <?php
    // Acciones AJAX
    if (isset($_GET["action"])) {
        if ($_GET["action"] == "obtener_producto") {
            $producto_id = $_GET['id'];

            $conexion = mysqli_connect("localhost:3306", "root", "", "proyecto_integrador");
            $producto_id = mysqli_real_escape_string($conexion, $producto_id);

            $query = "SELECT * FROM productos WHERE id_producto = $producto_id";
            $resultado = mysqli_query($conexion, $query);

            if ($resultado && $fila = mysqli_fetch_assoc($resultado)) {
                echo json_encode($fila);
            } else {
                echo json_encode(["error" => "No se pudo obtener el producto"]);
            }

            mysqli_close($conexion);
        } elseif ($_GET["action"] == "actualizar_producto") {
            // Actualizacion del producto
            if ($_SERVER["REQUEST_METHOD"] == "POST") {
                $id_producto = $_POST['edit_id_producto'];
                $nombre_producto = $_POST['edit_nombre_producto'];
                $precio_producto = $_POST['edit_precio_producto'];
                $descripcion_producto = $_POST['edit_descripcion_producto'];
                $imagen_producto = $_POST['edit_imagen_producto'];

                $conexion = mysqli_connect("localhost:3306", "root", "", "proyecto_integrador");

                $nombre_producto = mysqli_real_escape_string($conexion, $nombre_producto);
                $descripcion_producto = mysqli_real_escape_string($conexion, $descripcion_producto);

                $updateQuery = "UPDATE productos SET 
                                nombre_producto = '$nombre_producto', 
                                precio_producto = $precio_producto, 
                                descripcion_producto = '$descripcion_producto', 
                                imagen_producto = '$imagen_producto' 
                                WHERE id_producto = $id_producto";

                $result = mysqli_query($conexion, $updateQuery);

                if ($result) {
                    echo "Producto actualizado correctamente";
                } else {
                    echo "Error al actualizar el producto: " . mysqli_error($conexion);
                }

                mysqli_close($conexion);
            } else {
                echo "Acceso no autorizado";
            }
        }
    }
    ?>

    <?php
    echo "<script>
          function confirmarEliminar(id) {
            console.log('Función confirmarEliminar llamada con ID: ' + id);
            var confirmacion = confirm('¿Está seguro que desea eliminar este producto?');
            if (confirmacion) {
                window.location.href = 'index.php?deleteProduct=' + id;
            }
          }
    </script>";
    ?>

</body>
</html>