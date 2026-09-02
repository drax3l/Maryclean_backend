# 📚 Manual Operativo: Swagger UI y Documentación API

La API REST de MaryClean cuenta con documentación interactiva impulsada por **Swagger UI** (OpenAPI 3.0), implementada utilizando una estrategia de archivo estático (YAML) y renderizado vía CDN. 

Este diseño garantiza un acoplamiento nulo (preservando el patrón de *Thin Controllers* en CodeIgniter 4) y ofrece una plataforma robusta para pruebas en tiempo real.

---

## 1. Acceso a la Documentación Interactiva

La interfaz Swagger UI está disponible públicamente (protegida por CORS) en la ruta designada del entorno de backend:

🔗 **URL Local:** [http://localhost:8080/api/v1/docs](http://localhost:8080/api/v1/docs)

> **Nota:** La ruta `/api/v1/docs` no requiere el JWT en los *headers* de la petición inicial, permitiendo que cualquier desarrollador frontend acceda al panel de especificaciones fácilmente.

---

## 2. Instrucciones para la Autenticación (El Candado 🔒)

El backend utiliza JWT y restringe los endpoints mediante un modelo de Control de Acceso Basado en Roles (RBAC). Sigue estos pasos para interactuar con los endpoints bloqueados:

1. Despliega la pestaña del endpoint **`POST /api/v1/auth/login`**.
2. Haz clic en **Try it out** e ingresa las credenciales de prueba en el Request Body (ej. `admin001` y `Admin123!`).
3. Haz clic en **Execute** y localiza el campo `data.token` en la Respuesta (Código 200). Copia ese string largo (`eyJ0eX...`).
4. Haz clic en el botón verde global **Authorize 🔒** (Ubicado en la parte superior derecha de la página o al lado de cada endpoint).
5. Pega el token copiado en el campo de texto de `bearerAuth`.
6. Haz clic en **Authorize** y luego en **Close**.

¡Listo! Swagger UI inyectará automáticamente el header `Authorization: Bearer <TOKEN>` en cada petición subsecuente.

---

## 3. Catálogo de Errores HTTP Documentados

El sistema implementa respuestas unificadas. Todos los errores devuelven JSON bajo la misma estructura: `{ success: false, status, message, data: null, errors: {} }`.

| Código HTTP | Escenario Documentado | Simulación Interactiva |
| :--- | :--- | :--- |
| **`401 Unauthorized`** | Token ausente, alterado o caducado. | Intenta usar cualquier endpoint `GET` (como `/clientes`) sin inyectar el token en el 🔒. |
| **`403 Forbidden`** | El usuario tiene un token válido, pero su rol no posee los privilegios necesarios. | Inicia sesión como recepcionista (`recep001`) e intenta acceder a `GET /reportes/mensual` (requiere Admin). |
| **`422 Unprocessable`** | Lógica Crítica de BD: Intento de pagar un monto superior al saldo restante del pedido. | Ve a `POST /pagos`, usa el payload por defecto (`monto: 9999`) y observarás el error `SQLSTATE 45000` convertido a JSON. |
| **`429 Too Many Req.`** | Filtro de CodeIgniter (`throttler`) limitando ataques de fuerza bruta. | Ejecuta `POST /login` con credenciales erróneas rápidamente 5 veces seguidas. |

---

## 4. Pruebas de Paginación

Para todos los endpoints `GET` que manejan volumen de datos masivos (ej. `GET /api/v1/clientes`):
*   Haz clic en **Try it out**.
*   Swagger generará inputs nativos para los parámetros definidos:
    *   `page`: Define el número de la página (ej. `1`).
    *   `per_page`: Controla el tamaño de la fracción (ej. `5`).
    *   `q`: Término de búsqueda opcional.
*   Al ejecutar, la respuesta contendrá un objeto `paginacion` con información meta (`total_pages`, `page`, etc.).

---

## 5. Mantenimiento del Archivo OpenAPI YAML

La especificación OpenAPI de este proyecto reside de forma estática en:
📄 `public/docs/swagger.yaml`

**¿Por qué una especificación estática en YAML?**
Se decidió NO utilizar librerías basadas en atributos/anotaciones de PHP (`zircote/swagger-php`) para preservar el patrón **Thin Controller**. 

Si añades nuevos endpoints a tu backend en el futuro:
1. Abre `public/docs/swagger.yaml`.
2. Añade la nueva ruta en el bloque `paths:` respetando la sintaxis YAML de OpenAPI 3.0.
3. Puedes hacer referencia a esquemas globales (como `$ref: '#/components/schemas/SuccessResponse'`) para no repetir la estructura de respuesta JSON.
4. Los cambios se reflejarán instantáneamente al recargar la página `/api/v1/docs`.
