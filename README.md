# Catálogo de lámparas · Proyecto integrador PHP

Aplicación académica de catálogo y administración de productos con PHP y MariaDB. El trabajo original es de 2023; en octubre de 2026 se revisó para convertir las pantallas incompletas en una demo local reproducible.

**Estado:** demostración funcional. Permite registrar clientes, iniciar sesión, administrar productos según el rol, preparar un carrito y guardar pedidos **simulados**. No procesa pagos ni envía correos.

## Qué se puede probar

- Catálogo con tres productos ficticios e imágenes locales.
- Registro e inicio/cierre de sesión; contraseñas almacenadas como hashes.
- Rol de cliente y administrador. El registro público siempre crea clientes.
- Alta, edición y baja de productos: nombre, descripción, imagen y precio.
- Carrito por sesión, cantidades validadas y total calculado en el servidor.
- Confirmación y consulta de pedidos propios, con detalle y precios históricos.
- Protección ante reenvíos del mismo pedido mediante un token único.

Los precios de las semillas son ilustrativos. Las fotografías son recursos heredados del ejercicio y no se presentan como diseños propios.

## Ejecutar en Windows / PowerShell

Requisitos: Git y Docker Desktop con Compose y contenedores Linux. No hace falta instalar PHP ni MariaDB en Windows.

1. Clonar el repositorio y entrar en su carpeta.
2. Copiar [.env.example](./.env.example) a una ubicación **privada fuera del repositorio**, por ejemplo `$HOME/.config/catalogo-demo/runtime.env`. Completar dos contraseñas diferentes y aleatorias en `DB_PASSWORD` y `DB_ROOT_PASSWORD`.
3. Iniciar los servicios desde la raíz del repositorio:

```powershell
$runtime = "$HOME/.config/catalogo-demo/runtime.env"
docker compose --env-file $runtime up --build -d
```

4. Abrir [http://127.0.0.1:8088](http://127.0.0.1:8088). La base se crea con [database/schema.sql](./database/schema.sql) la primera vez que el volumen está vacío. El puerto solo escucha en la máquina local y la base no publica puertos.

No hay usuarios o contraseñas predeterminados. El registro de la web crea un cliente. Para crear un administrador, definir temporalmente dos variables privadas:

```powershell
$env:ADMIN_EMAIL = Read-Host "Correo ficticio del administrador"
$privatePassword = Read-Host "Contraseña de 12 a 72 bytes" -AsSecureString
$env:ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $privatePassword).Password
docker compose --env-file $runtime exec -e ADMIN_EMAIL -e ADMIN_PASSWORD app php /var/www/app/create-admin.php
Remove-Item Env:ADMIN_EMAIL, Env:ADMIN_PASSWORD
```

El comando se ejecuta solo por CLI y no convierte una cuenta existente en administrador. Para otro administrador se necesita otro correo.

Para detener la demo conservando los datos ficticios:

```powershell
docker compose --env-file $runtime stop
```

## Recorrido sugerido

1. Crear una cuenta con datos ficticios y agregar productos al carrito.
2. Confirmar el pedido; revisar el total y el historial.
3. Iniciar sesión como administrador y crear, editar y eliminar un producto de prueba.
4. Volver como cliente y comprobar que no se puede acceder a la administración.

## Estructura

- [proyecto integrador/index.php](./proyecto%20integrador/index.php): rutas, formularios y vistas.
- [app/bootstrap.php](./app/bootstrap.php): sesiones, consultas preparadas, validación, permisos y pedidos.
- [app/create-admin.php](./app/create-admin.php): creación administrativa por CLI, fuera de la carpeta pública.
- [database/schema.sql](./database/schema.sql): modelo y semillas sintéticas.
- [tests/test_demo.py](./tests/test_demo.py): pruebas HTTP de integración.
- [docs/VALIDACION.md](./docs/VALIDACION.md): alcance y resultados de la revisión.

Las rutas estáticas originales de login, catálogo y administración redirigen a la aplicación. Las credenciales de base y el código de soporte quedan fuera de la raíz pública del contenedor.

## Validación

Las pruebas usan Python 3 (biblioteca estándar) y una instancia **local de pruebas**. Crean usuarios y pedidos ficticios: no ejecutarlas contra una base con datos que se quieran conservar.

Mantener `ADMIN_EMAIL`, `ADMIN_PASSWORD` y `TEST_PASSWORD` en el entorno privado; la última debe cumplir la política de 12 a 72 bytes. Con el administrador ya creado y los servicios activos:

```powershell
python tests/test_demo.py
```

La URL predeterminada es `http://127.0.0.1:8088`; se puede cambiar con `DEMO_BASE_URL`, siempre en localhost. No se incluyen credenciales de pruebas en Git.

## Límites de la demo

No es una tienda preparada para producción: no tiene stock, cobros, envío, recuperación/verificación de correo, gestión de imágenes, auditoría operativa ni protección distribuida contra ataques. El límite de intentos de login es por sesión y no reemplaza un control de infraestructura. Los pedidos son demostrativos y no constituyen comprobantes comerciales. Para publicarla harían falta HTTPS, controles operativos y revisión específica del entorno.

## Seguridad

Consultas PDO preparadas, escape de salida, autorización por rol, CSRF en operaciones POST, regeneración de sesión y cookies HttpOnly/SameSite. La cookie Secure se activa con HTTPS. El administrador configura imágenes locales o HTTPS; no hay subida de archivos.

No subir claves, archivos privados de entorno ni datos personales. Los cambios de 2026 conservan la historia del ejercicio y no implican que la versión original tuviera estas funcionalidades.
