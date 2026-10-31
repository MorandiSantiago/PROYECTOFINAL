"""Pruebas HTTP contra una demo LOCAL y una base de datos descartable."""
import http.cookiejar
import os
import re
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.environ.get("DEMO_BASE_URL", "http://127.0.0.1:8088").rstrip("/")
parsed = urllib.parse.urlparse(BASE)
if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost"):
    raise RuntimeError("Estas pruebas solo se permiten contra una demo local.")

class Client:
    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path="/index.php", data=None):
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        request = urllib.request.Request(BASE + path, data=payload)
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        try:
            return response.status, response.read().decode("utf-8", errors="replace"), response.geturl(), response.headers
        finally:
            response.close()

    def csrf(self, view="catalogo"):
        status, text, _, _ = self.request("/index.php?vista=" + view)
        if status != 200:
            raise AssertionError("La página del formulario no respondió 200.")
        match = re.search(r'name="csrf" value="([a-f0-9]+)"', text)
        if not match:
            raise AssertionError("Falta el token del formulario.")
        return match.group(1)

    def post(self, action, view="catalogo", **fields):
        return self.request("/index.php?vista=" + view, {
            "csrf": self.csrf(view), "accion": action, **fields
        })

    def register(self):
        email = "prueba-" + uuid.uuid4().hex + "@example.test"
        result = self.post("registro", "registro", nombre="Cliente ficticio", email=email,
                           password=os.environ["TEST_PASSWORD"], rol="admin")
        return email, result

    def login_admin(self):
        return self.post("login", "login", email=os.environ["ADMIN_EMAIL"], password=os.environ["ADMIN_PASSWORD"])


class DemoTests(unittest.TestCase):
    def test_01_catalogo_e_imagenes(self):
        client = Client()
        status, text, _, headers = client.request()
        self.assertEqual(status, 200)
        self.assertIn("Catálogo de lámparas", text)
        self.assertIn("no se realizan cobros", text)
        for image in re.findall(r'<img src="([^"]+)"', text):
            self.assertEqual(client.request("/" + urllib.parse.quote(image, safe="/"))[0], 200)
        self.assertIn("nosniff", headers.get("X-Content-Type-Options", ""))

    def test_02_admin_sin_autenticar_y_csrf(self):
        client = Client()
        status, text, url, headers = client.request("/index.php?vista=admin")
        self.assertEqual(status, 200)
        self.assertIn("vista=login", url)
        cookies = " ".join(headers.get_all("Set-Cookie", []))
        # La cookie puede haberse establecido durante la redirección.
        status, _, _, headers = Client().request("/index.php?vista=login")
        cookies += " ".join(headers.get_all("Set-Cookie", []))
        self.assertIn("HttpOnly", cookies)
        self.assertIn("SameSite=Lax", cookies)
        self.assertEqual(client.request("/index.php", {"accion": "crear_producto", "csrf": "incorrecto"})[0], 403)

    def test_03_registro_no_permite_elegir_rol(self):
        client = Client()
        _, result = client.register()
        self.assertEqual(result[0], 200)
        self.assertNotIn('href="?vista=admin"', result[1])
        self.assertEqual(client.request("/index.php?vista=admin")[0], 403)
        self.assertEqual(client.post("crear_producto", nombre_producto="No autorizado")[0], 403)

    def test_04_login_real_y_salida(self):
        client = Client()
        email, _ = client.register()
        client.post("logout")
        wrong = client.post("login", "login", email=email, password="incorrecta")
        self.assertEqual(wrong[0], 401)
        correct = client.post("login", "login", email=email, password=os.environ["TEST_PASSWORD"])
        self.assertEqual(correct[0], 200)
        self.assertIn("Mis pedidos", correct[1])
        client.post("logout")
        self.assertIn("vista=login", client.request("/index.php?vista=carrito")[2])

    def test_05_inyeccion_no_autentica(self):
        client = Client()
        response = client.post("login", "login", email="' OR 1=1 --", password="cualquier-clave")
        self.assertEqual(response[0], 401)
        self.assertNotIn("Administración de productos", response[1])

    def test_06_crud_escapado_y_validaciones(self):
        client = Client()
        self.assertEqual(client.login_admin()[0], 200)
        marker = "Lámpara d'ejemplo " + uuid.uuid4().hex[:8] + " <script>alert(1)</script>"
        created = client.post("crear_producto", "admin", nombre_producto=marker,
                              precio_producto="12.34", descripcion_producto="Descripción <b>literal</b>",
                              imagen_producto="cards/img/images.jpg")
        self.assertEqual(created[0], 200)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", created[1])
        self.assertNotIn("<script>alert(1)</script>", created[1])
        rows = re.findall(r'<tr data-product-id="(\d+)">(.*?)</tr>', created[1], re.S)
        product_id = next(int(i) for i, body in rows if uuid_marker(marker) in body)
        failed = client.post("editar_producto", "admin", id_producto=product_id, nombre_producto="Inválido",
                             precio_producto="1 OR 1=1", descripcion_producto="Texto", imagen_producto="cards/img/images.jpg")
        self.assertEqual(failed[0], 422)
        invalid_image = client.post("editar_producto", "admin", id_producto=product_id, nombre_producto="Inválido",
                                   precio_producto="10", descripcion_producto="Texto", imagen_producto="javascript:alert(1)")
        self.assertEqual(invalid_image[0], 422)
        self.assertIn(uuid_marker(marker), client.request("/admin/index.php?deleteProduct=" + str(product_id))[1])
        edited = client.post("editar_producto", "admin", id_producto=product_id, nombre_producto="Editado & seguro",
                             precio_producto="15.60", descripcion_producto="Texto actualizado", imagen_producto="cards/img/images.jpg")
        self.assertEqual(edited[0], 200)
        self.assertIn("Editado &amp; seguro", edited[1])
        self.assertEqual(client.post("eliminar_producto", "admin", id_producto="1 OR 1=1")[0], 422)
        deleted = client.post("eliminar_producto", "admin", id_producto=product_id)
        self.assertEqual(deleted[0], 200)
        self.assertNotIn('data-product-id="' + str(product_id) + '"', deleted[1])

    def test_07_pedido_calculado_en_servidor_y_repeticion(self):
        client = Client()
        client.register()
        cart = client.post("agregar_carrito", id_producto=1, cantidad=2, precio_producto="0.01")
        self.assertEqual(cart[0], 200)
        self.assertIn("$30.000,00", cart[1])
        csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', cart[1]).group(1)
        token = re.search(r'name="pedido_token" value="([a-f0-9]+)"', cart[1]).group(1)
        payload = {"accion": "confirmar_pedido", "csrf": csrf, "pedido_token": token}
        order = client.request("/index.php?vista=carrito", payload)
        self.assertEqual(order[0], 200)
        self.assertIn("Pedido simulado #", order[1])
        self.assertIn("$30.000,00", order[1])
        repeated = client.request("/index.php?vista=carrito", payload)
        self.assertEqual(repeated[2], order[2])
        history = client.request("/index.php?vista=pedidos")[1]
        self.assertEqual(len(re.findall(r'vista=pedido&amp;id=', history)), 1)
        other = Client()
        other.register()
        path = urllib.parse.urlsplit(order[2])
        self.assertEqual(other.request(path.path + "?" + path.query)[0], 404)

    def test_08_carrito_validaciones_y_quitar(self):
        client = Client()
        client.register()
        self.assertEqual(client.post("agregar_carrito", id_producto=1, cantidad=0)[0], 422)
        self.assertEqual(client.post("agregar_carrito", id_producto=1, cantidad=100)[0], 422)
        self.assertEqual(client.post("agregar_carrito", id_producto=9999999, cantidad=1)[0], 422)
        client.post("agregar_carrito", id_producto=1, cantidad=1)
        result = client.post("quitar_carrito", "carrito", id_producto=1)
        self.assertEqual(result[0], 200)
        self.assertIn("El carrito está vacío", result[1])

    def test_09_limite_intentos_de_login(self):
        client = Client()
        for _ in range(5):
            self.assertEqual(client.post("login", "login", email="noexiste@example.test", password="incorrecta")[0], 401)
        self.assertEqual(client.post("login", "login", email="noexiste@example.test", password="incorrecta")[0], 429)

    def test_10_rutas_anteriores_y_aislamiento(self):
        client = Client()
        self.assertEqual(client.request("/index.php?vista=desconocida")[0], 404)
        self.assertEqual(client.request("/app/create-admin.php")[0], 404)
        self.assertEqual(client.request("/database/schema.sql")[0], 404)
        self.assertEqual(client.request("/.env")[0], 404)
        self.assertEqual(client.request("/Login/index.html")[0], 200)


    def test_11_producto_eliminado_no_bloquea_el_carrito(self):
        admin = Client()
        admin.login_admin()
        marker = "Temporal " + uuid.uuid4().hex
        result = admin.post("crear_producto", "admin", nombre_producto=marker,
                            precio_producto="5", descripcion_producto="Ficticio",
                            imagen_producto="cards/img/images.jpg")
        rows = re.findall(r'<tr data-product-id="(\d+)">(.*?)</tr>', result[1], re.S)
        product_id = next(int(i) for i, body in rows if marker in body)
        client = Client()
        client.register()
        client.post("agregar_carrito", id_producto=product_id, cantidad=1)
        client.post("agregar_carrito", id_producto=1, cantidad=1)
        admin.post("eliminar_producto", "admin", id_producto=product_id)
        cart = client.request("/index.php?vista=carrito")
        self.assertNotIn(marker, cart[1])
        token = re.search(r'name="pedido_token" value="([a-f0-9]+)"', cart[1]).group(1)
        order = client.post("confirmar_pedido", "carrito", pedido_token=token)
        self.assertEqual(order[0], 200)
        self.assertIn("Pedido simulado #", order[1])
        self.assertIn("$15.000,00", order[1])


def uuid_marker(name):
    return name.split(" ")[2]


if __name__ == "__main__":
    for required in ["ADMIN_EMAIL", "ADMIN_PASSWORD", "TEST_PASSWORD"]:
        if not os.environ.get(required):
            raise RuntimeError("Definir variables privadas de prueba antes de ejecutar.")
    unittest.main(verbosity=2)
