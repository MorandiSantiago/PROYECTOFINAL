# Validación de la demo · 30 de octubre de 2026

Entorno local: PHP 8.4 con Apache, extensión PDO MySQL y MariaDB 11.4 mediante Docker Compose. Credenciales aleatorias fuera de Git. Solo productos, usuarios y pedidos ficticios.

Resultado: **11 pruebas HTTP aprobadas** y **4 archivos PHP sin errores de sintaxis**.

| Caso | Resultado |
| --- | --- |
| Catálogo, imágenes locales y cabeceras | Aprobado |
| Acceso anónimo a administración y rechazo CSRF | Aprobado |
| Registro no permite elegir rol administrador | Aprobado |
| Login incorrecto/correcto y cierre de sesión | Aprobado |
| Intento de inyección SQL no autentica | Aprobado |
| CRUD, apóstrofes, escape HTML, validación de precios e imágenes y rechazo de borrado por GET | Aprobado |
| Pedido con total de servidor, reintento sin duplicado y aislamiento entre clientes | Aprobado |
| Validación de cantidades y eliminación del carrito | Aprobado |
| Límite de intentos por sesión | Aprobado |
| Rutas históricas y aislamiento de archivos internos | Aprobado |
| Producto eliminado no bloquea el resto del carrito | Aprobado |

El catálogo se comprobó también en el navegador. Estas pruebas verifican los flujos de la demo; no constituyen una auditoría de seguridad exhaustiva ni validan un despliegue de producción.

## Reproducibilidad

Consultar el README para iniciar una base local y crear el administrador. Ejecutar `python tests/test_demo.py` con las variables privadas requeridas. Las pruebas conservan usuarios/pedidos sintéticos en la base local; el producto de CRUD se elimina al finalizar ese caso.
