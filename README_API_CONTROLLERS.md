# 🔌 MaryClean API REST — Documentación de Controladores
### Capa de Controladores API (`app/Controllers/Api/`) — Backend PHP (CodeIgniter 4)

---

## Tabla de Contenidos

1. [Visión General](#1-visión-general)
2. [Diagrama de Flujo de Arquitectura](#2-diagrama-de-flujo-de-arquitectura)
3. [Formato de Respuesta Unificado](#3-formato-de-respuesta-unificado)
4. [Autenticación JWT](#4-autenticación-jwt)
5. [Filtros de Seguridad API](#5-filtros-de-seguridad-api)
6. [Mapa de Rutas Completo](#6-mapa-de-rutas-completo)
7. [Referencia de Controladores](#7-referencia-de-controladores)
   - 7.1 [BaseApiController](#71-baseapicontroller)
   - 7.2 [AuthController](#72-authcontroller)
   - 7.3 [ClientesController](#73-clientescontroller)
   - 7.4 [PedidosController](#74-pedidoscontroller)
   - 7.5 [PagosController](#75-pagoscontroller)
   - 7.6 [ReportesController](#76-reportescontroller)
8. [RBAC — Control de Acceso por Roles](#8-rbac--control-de-acceso-por-roles)
9. [Manejo de Errores y Excepciones](#9-manejo-de-errores-y-excepciones)
10. [CORS — Integración con Node.js y Expo Go](#10-cors--integración-con-nodejs-y-expo-go)
11. [Guía de Integración para Clientes](#11-guía-de-integración-para-clientes)
12. [Configuración del Entorno](#12-configuración-del-entorno)
13. [Guía de Extensión](#13-guía-de-extensión)

---

## 1. Visión General

La capa de Controladores API de MaryClean implementa una **arquitectura API-First desacoplada**.
El backend expone una API REST que sirve simultáneamente a dos tipos de clientes:

| Cliente | Tecnología | Almacenamiento del Token |
|---|---|---|
| **Frontend Web** | Node.js | Cookie HttpOnly o localStorage |
| **App Móvil** | Expo Go (React Native) | `expo-secure-store` |

### Principio Fundamental: Thin Controller

```
REGLA DE ORO: Los controladores NO contienen SQL ni lógica de negocio.
              Solo coordinan: Petición HTTP → Modelo → Respuesta JSON.
```

Cada endpoint sigue exactamente esta secuencia:

```
1. Validar campos del request (rules declarativas de CI4)
2. Llamar al método correspondiente del Modelo
3. Retornar respondSuccess() o respondError() con el resultado
```

---

## 2. Diagrama de Flujo de Arquitectura

```
[Node.js Frontend]          [Expo Go App]
       |                          |
       +----------+---------------+
                  | Authorization: Bearer <JWT>
                  | Content-Type: application/json
                  v
       +----------------------+
       |     CorsFilter       |  Agrega Access-Control-* headers
       |  OPTIONS → HTTP 204  |  Responde preflight sin tocar el controlador
       +----------+-----------+
                  |
       +----------v-----------+
       |      JwtFilter       |  Extrae y valida el Bearer token
       |  inyecta jwtPayload  |  Verifica firma HS256 + expiración
       |  RBAC por args       |  401/403 si inválido (nunca redirect)
       +----------+-----------+
                  |
       +----------v---------------------------+
       |   Controlador Thin (Api/)            |
       |  1. getJsonBody()                    |
       |  2. validateData($body, $rules)      |
       |  3. $modelo->metodo(...)             |
       |  4. respondSuccess() / respondError()|
       +----------+---------------------------+
                  |
       +----------v---------------------------+
       |   Modelos (app/Models/)              |
       |   Query Builder / SP / Vistas        |
       |   Triggers automáticos MySQL         |
       +----------+---------------------------+
                  |
       +----------v---------------------------+
       |   MySQL (BD: lavanderia)             |
       |   Triggers, Stored Procedures        |
       |   Vistas (v_pedidos_activos, etc.)   |
       +--------------------------------------+
```

---

## 3. Formato de Respuesta Unificado

**Todos** los endpoints devuelven exactamente esta estructura JSON.
Los clientes Node.js y Expo Go deben esperar siempre este formato.

```json
{
  "success": true,
  "status":  200,
  "message": "Descripción legible del resultado.",
  "data":    { },
  "errors":  null
}
```

### Campos

| Campo | Tipo | Descripción |
|---|---|---|
| `success` | `boolean` | `true` si la operación fue exitosa |
| `status` | `int` | Código HTTP repetido en el body (facilita lectura en logs) |
| `message` | `string` | Mensaje en español, legible para el usuario final |
| `data` | `object\|array\|null` | Payload principal. `null` en errores |
| `errors` | `object\|null` | Mapa de errores. `null` en éxitos |

### Códigos HTTP Utilizados

| Código | Cuándo se usa |
|---|---|
| `200 OK` | Consultas exitosas (GET) |
| `201 Created` | Recursos creados exitosamente (POST) |
| `204 No Content` | Preflight OPTIONS (CorsFilter) |
| `400 Bad Request` | Error de validación genérico, FK violada |
| `401 Unauthorized` | Token ausente, expirado o inválido |
| `403 Forbidden` | Token válido pero rol insuficiente |
| `404 Not Found` | Recurso no existe |
| `409 Conflict` | Duplicado (documento o username ya existe) |
| `422 Unprocessable Entity` | Sobrepago (SQLSTATE 45000) / Datos inválidos |
| `500 Internal Server Error` | Error interno no clasificado |

---

## 4. Autenticación JWT

### Librería

**`firebase/php-jwt v7.1.0`** — algoritmo HS256.
Ubicación: `vendor/firebase/php-jwt/` | Clase principal: `app/Libraries/JwtHelper.php`

### Estructura del Token (Payload)

```json
{
  "iss":     "maryclean-api",
  "iat":     1725218000,
  "exp":     1725221600,
  "id":      1,
  "rol":     "admin",
  "nombres": "María Torres Quispe",
  "sucursal": 1
}
```

| Campo | Descripción |
|---|---|
| `iss` | Issuer. Configurado en `.env` → `jwt.issuer` |
| `iat` | Timestamp de emisión (Unix) |
| `exp` | Timestamp de expiración. TTL dinámico en `.env` → `jwt.ttl` (default: 28800s / 8 horas) |
| `id` | `idEmpleado` — usado para refrescar datos del empleado en `/me` |
| `rol` | Rol del empleado — usado por `JwtFilter` para RBAC |
| `nombres` | Nombre completo del empleado |
| `sucursal` | `idSucursal` — usado para filtrar datos por sucursal |

### Flujo de Autenticación Completo

```
1. POST /api/v1/auth/login  { "username": "admin001", "password": "Admin123!" }
   ├── ThrottleFilter                ← Limita intentos por IP (previene fuerza bruta HTTP 429)
   └── AuthController::login()
       └── EmpleadoModel::autenticar()   ← password_verify() + Verifica activo = 1
           └── JwtHelper::generarToken() ← firma HS256 con secret del .env
               └── { "token": "eyJ...", "expires_in": 3600 }

2. Cliente almacena el token:
   ├── Expo Go:   SecureStore.setItemAsync('token', token)
   └── Node.js:   Cookie HttpOnly / localStorage

3. Cada petición protegida:
   GET /api/v1/pedidos
   Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
   └── JwtFilter::before()
       └── JwtHelper::validarToken()     ← verifica firma y expiración
           └── $request->jwtPayload = { id, rol, nombres, sucursal }
               └── Controlador recibe la petición autenticada
```

### Acceso al Payload en los Controladores

```php
// Disponible en cualquier controlador que extienda BaseApiController:
$payload = $this->getAuthPayload();

$idEmpleado = (int) $payload['id'];
$rol        = $payload['rol'];       // 'admin' | 'cajero' | 'recepcionista'
$sucursal   = (int) $payload['sucursal'];
```

---

## 5. Filtros de Seguridad API

### JwtFilter (`app/Filters/JwtFilter.php`)

Registrado como alias `'jwt'` en `app/Config/Filters.php`.

**Uso básico** (solo validar token):
```php
['filter' => 'jwt']
```

**Uso con RBAC** (validar token + rol mínimo):
```php
['filter' => 'jwt:admin']          // Solo admin
['filter' => 'jwt:cajero,admin']   // Cajero o admin
```

**Flujo interno:**

```
1. Extraer Bearer del header Authorization
2. Si no hay header → HTTP 401 { errors: { auth: "TOKEN_MISSING" } }
3. JwtHelper::validarToken($token)
4. Si firma inválida → HTTP 401 { errors: { auth: "TOKEN_INVALID" } }
5. Si expirado → HTTP 401 (mensaje específico de expiración)
6. Si hay $arguments (roles requeridos) → verificar jerarquía RBAC
7. Si rol insuficiente → HTTP 403 { rol_actual, roles_requeridos }
8. Si todo OK → inyectar payload en $request->jwtPayload → null (continuar)
```

---

### CorsFilter (`app/Filters/CorsFilter.php`)

Registrado como alias `'cors'` en `app/Config/Filters.php`.

**Siempre se aplica antes que jwt** en el grupo de rutas API.

**Headers que agrega:**
```
Access-Control-Allow-Origin:      <origen verificado>
Access-Control-Allow-Methods:     GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers:     Authorization, Content-Type, Accept, ...
Access-Control-Allow-Credentials: true
Vary:                             Origin
```

**Configuración en `.env`:**
```env
cors.allowedOrigins = http://localhost:3000,http://localhost:8081,exp://192.168.1.x:8081
cors.allowedMethods = GET, POST, PUT, PATCH, DELETE, OPTIONS
```

> Para Expo Go en dispositivo físico: agregar la IP del dispositivo en `cors.allowedOrigins`.

---

### ThrottleFilter (`app/Filters/ThrottleFilter.php`)

Registrado como alias `'throttler'` en `app/Config/Filters.php`.

Filtro nativo que implementa el servicio `Throttler` de CI4 para **prevenir ataques de fuerza bruta (Rate Limiting)**.
*   **Aplicación actual:** Exclusivamente en la ruta `POST /api/v1/auth/login`.
*   **Límite por defecto:** 4 intentos por minuto por IP.
*   **Respuesta al exceder:** `HTTP 429 Too Many Requests` (Detiene el procesamiento inmediatamente).

---

## 6. Mapa de Rutas Completo

| Método | URI | Controlador::Método | Filtros | Rol Mínimo |
|---|---|---|---|---|
| `POST` | `/api/v1/auth/login` | `AuthController::login` | cors, throttler | Público |
| `GET` | `/api/v1/auth/me` | `AuthController::me` | cors, jwt | Cualquier rol |
| `GET` | `/api/v1/clientes` | `ClientesController::index` | cors, jwt | Cualquier rol |
| `GET` | `/api/v1/clientes/documento/:doc` | `ClientesController::buscarPorDocumento` | cors, jwt | Cualquier rol |
| `GET` | `/api/v1/clientes/:id` | `ClientesController::show` | cors, jwt | Cualquier rol |
| `POST` | `/api/v1/clientes` | `ClientesController::create` | cors, jwt | Cualquier rol |
| `PUT` | `/api/v1/clientes/:id` | `ClientesController::update` | cors, jwt | Cualquier rol |
| `GET` | `/api/v1/pedidos` | `PedidosController::index` | cors, jwt | Cualquier rol |
| `GET` | `/api/v1/pedidos/:id` | `PedidosController::show` | cors, jwt | Cualquier rol |
| `POST` | `/api/v1/pedidos` | `PedidosController::crearRecepcion` | cors, jwt | Cualquier rol |
| `GET` | `/api/v1/pedidos/:id/ticket` | `PedidosController::obtenerTicket` | cors, jwt | Cualquier rol |
| `PATCH` | `/api/v1/pedidos/:id/estado` | `PedidosController::cambiarEstado` | cors, jwt | Cualquier rol* |
| `POST` | `/api/v1/pagos` | `PagosController::registrarPago` | cors, jwt | cajero/admin |
| `GET` | `/api/v1/pagos/pedido/:id` | `PagosController::obtenerHistorial` | cors, jwt | Cualquier rol |
| `GET` | `/api/v1/pagos/:id/recibo` | `PagosController::obtenerRecibo` | cors, jwt | cajero/admin |
| `GET` | `/api/v1/reportes/dashboard` | `ReportesController::dashboard` | cors, jwt | Cualquier rol |
| `GET` | `/api/v1/reportes/diario` | `ReportesController::reporteDiario` | cors, jwt | cajero/admin |
| `GET` | `/api/v1/reportes/mensual` | `ReportesController::reporteMensual` | cors, jwt | admin |
| `GET` | `/api/v1/reportes/cierre-caja` | `ReportesController::cierreCaja` | cors, jwt | cajero/admin |
| `GET` | `/api/v1/reportes/servicios` | `ReportesController::serviciosMasSolicitados` | cors, jwt | admin |

> `*` El PATCH de estado verifica `Cancelado` internamente: solo admin puede cancelar.

---

## 7. Referencia de Controladores

### 7.1 BaseApiController

**Archivo:** [`app/Controllers/Api/BaseApiController.php`](file:///c:/Users/ALEX/Downloads/Proyecto_codeIgniter/proyecto_prueba/app/Controllers/Api/BaseApiController.php)
**Extiende:** `CodeIgniter\RESTful\ResourceController`
**Usa Trait:** `DbExceptionHandler`

Todos los controladores API extienden esta clase. Provee los siguientes helpers:

#### Métodos de Respuesta

```php
// Respuesta exitosa genérica (HTTP 200)
$this->respondSuccess($data, $message, $code);

// Respuesta de error (HTTP 400/401/403/404/500)
$this->respondError($message, $code, $errors);

// HTTP 201 Created
$this->respondCreated($data, $message);

// HTTP 404 Not Found
$this->respondNotFound($mensaje);

// HTTP 422 con mapa de errores (validación de CI4)
$this->respondValidationError($errors, $message);
```

#### Manejo de Excepciones de BD

```php
// Convierte DatabaseException en respuesta HTTP correcta según el tipo de error
$this->handleDbException(DatabaseException $e, int $idPedido = 0);
```

Tabla de conversión interna:

| `codigo_error` | HTTP | Cuándo ocurre |
|---|---|---|
| `SOBREPAGO_TRIGGER_45000` | 422 | Monto > saldo en `trg_pago_antes_insertar_validar` |
| `CHECK_CONSTRAINT_VIOLATION` | 400 | Valor ≤ 0 (precio, monto, cantidad) |
| `FOREIGN_KEY_VIOLATION` | 400 | FK inexistente |
| `DUPLICATE_ENTRY` | 409 | username o documento duplicado |
| `DATABASE_ERROR` | 500 | Error genérico de BD |

#### Helpers de Petición y Autenticación

```php
// Obtiene el body JSON como array (compatible con fetch/axios/Expo)
$body = $this->getJsonBody();

// Obtiene el payload del JWT inyectado por JwtFilter
$payload = $this->getAuthPayload();
// Devuelve: ['id' => 1, 'rol' => 'admin', 'nombres' => '...', 'sucursal' => 1]

// Verifica rol con jerarquía (admin puede todo)
$this->tieneRol('cajero', 'admin');  // true si el rol actual es cajero O admin
```

---

### 7.2 AuthController

**Archivo:** [`app/Controllers/Api/AuthController.php`](file:///c:/Users/ALEX/Downloads/Proyecto_codeIgniter/proyecto_prueba/app/Controllers/Api/AuthController.php)

#### `POST /api/v1/auth/login`

**Request:**
```json
{
  "username": "admin001",
  "password": "Admin123!"
}
```

**Response 200:**
```json
{
  "success":    true,
  "status":     200,
  "message":    "Bienvenido, María Torres Quispe.",
  "data": {
    "token":       "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type":  "Bearer",
    "expires_in":  3600,
    "empleado": {
      "id":       1,
      "nombres":  "María Torres Quispe",
      "rol":      "admin",
      "sucursal": "MaryClean Centro"
    }
  },
  "errors": null
}
```

**Response 401 (credenciales inválidas):**
```json
{
  "success": false,
  "status":  401,
  "message": "Credenciales incorrectas. Verifique su usuario y contraseña.",
  "data":    null,
  "errors":  null
}
```

---

#### `GET /api/v1/auth/me` `[jwt]`

Devuelve los datos frescos del empleado autenticado, consultando la BD.
Útil para que los clientes rehidraten el estado de sesión sin re-autenticar.

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id":         1,
    "nombres":    "María Torres Quispe",
    "username":   "admin001",
    "rol":        "admin",
    "sucursal":   "MaryClean Centro",
    "idSucursal": 1
  }
}
```

---

### 7.3 ClientesController

**Archivo:** [`app/Controllers/Api/ClientesController.php`](file:///c:/Users/ALEX/Downloads/Proyecto_codeIgniter/proyecto_prueba/app/Controllers/Api/ClientesController.php)

#### `GET /api/v1/clientes` `[jwt]`

**Query params:**

| Parámetro | Tipo | Default | Descripción |
|---|---|---|---|
| `q` | string | — | Búsqueda por nombre, documento o teléfono |
| `page` | int | 1 | Página actual |
| `per_page` | int | 15 | Registros por página (máx. 50) |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "clientes": [ { "idCliente": 1, "documento": "12345678", "nombres": "..." } ],
    "paginacion": {
      "total":       120,
      "page":        1,
      "per_page":    15,
      "total_pages": 8
    }
  }
}
```

---

#### `GET /api/v1/clientes/documento/:doc` `[jwt]`

Búsqueda exacta por número de documento (DNI/RUC).
**Uso principal:** Panel de recepción — buscar cliente antes de crear un pedido.

**Response 200:** Datos completos del cliente.
**Response 404:** Si no existe ningún cliente con ese documento.

---

#### `POST /api/v1/clientes` `[jwt]`

**Request:**
```json
{
  "documento": "99887766",
  "nombres":   "Nuevo Cliente",
  "telefono":  "999123456",
  "direccion": "Av. Nueva 123"
}
```

**Response 201:** Datos completos del cliente recién creado.
**Response 422:** Si hay errores de validación (documento duplicado, campos requeridos).

---

### 7.4 PedidosController

**Archivo:** [`app/Controllers/Api/PedidosController.php`](file:///c:/Users/ALEX/Downloads/Proyecto_codeIgniter/proyecto_prueba/app/Controllers/Api/PedidosController.php)

#### `GET /api/v1/pedidos` `[jwt]`

Consulta la vista `v_pedidos_activos` (excluye Entregado y Cancelado).

**Query params:**

| Parámetro | Descripción |
|---|---|
| `estado` | Filtrar por estado específico |
| `sucursal` | Filtrar por idSucursal (admin puede ver todas) |

> **Restricción de sucursal:** Si el rol no es `admin`, el filtro de sucursal
> se aplica automáticamente con la sucursal del token JWT.

---

#### `POST /api/v1/pedidos` `[jwt]`

Registra la recepción de un pedido nuevo. Ejecuta el SP `sp_registrar_recepcion`
e inserta los detalles. Los triggers recalculan el total automáticamente.

**Request:**
```json
{
  "idCliente": 1,
  "detalles": [
    { "idPrenda": 1, "cantidad": 3, "descripcion": "Camisas blancas" },
    { "idPrenda": 2, "cantidad": 2, "descripcion": "Pantalón oscuro"  }
  ]
}
```

> El `idEmpleado` se obtiene automáticamente del payload del JWT.
> El `codigoTicket` es generado por `PedidoModel::generarCodigoTicket()`.

**Response 201:**
```json
{
  "success": true,
  "message": "Pedido registrado exitosamente.",
  "data": {
    "idPedido":     10,
    "codigoTicket": "MC-20260901-A3F7B",
    "ticket": {
      "encabezado": { "empresa": "LAVANDERÍA MARYCLEAN", ... },
      "cliente":    { "documento": "12345678", "nombres": "..." },
      "detalles":   [ { "servicio": "Lavado Simple", "prenda": "Camisa", ... } ],
      "total":           "27.00",
      "saldo_pendiente": "27.00",
      "estado":          "Recibido"
    }
  }
}
```

---

#### `GET /api/v1/pedidos/:id/ticket` `[jwt]`

Genera la estructura JSON del ticket imprimible en cualquier momento.
Puede ser llamado por la impresora de tickets conectada al frontend web.

**Response 200:** Estructura completa del ticket (igual al campo `ticket` del POST anterior).

---

#### `PATCH /api/v1/pedidos/:id/estado` `[jwt]`

**Request:**
```json
{ "estado": "En Proceso" }
```

**Triggers disparados automáticamente por MySQL:**
- Si `estado = 'Entregado'` → `trg_pedido_antes_actualizar` asigna `fechaEntrega = NOW()`
- En cualquier cambio → `trg_pedido_despues_actualizar_auditoria` registra el cambio en `AuditoriaEstado`

**Estados válidos:** `Recibido`, `En Proceso`, `Listo`, `Entregado`, `Pagado`, `Cancelado`

> Solo `admin` puede cambiar a estado `Cancelado`.

**Response 200:**
```json
{
  "success": true,
  "message": "Estado del pedido #1 actualizado a 'En Proceso'.",
  "data": {
    "idPedido":    1,
    "estado":      "En Proceso",
    "fechaEntrega": null,
    "total":       "27.00"
  }
}
```

---

### 7.5 PagosController

**Archivo:** [`app/Controllers/Api/PagosController.php`](file:///c:/Users/ALEX/Downloads/Proyecto_codeIgniter/proyecto_prueba/app/Controllers/Api/PagosController.php)

> **COBRO PRESENCIAL ÚNICAMENTE.**
> Solo se aceptan los métodos: `Efectivo`, `Tarjeta`, `Yape/Plin`.
> No hay integración con pasarelas de pago externas ni APIs de cobro online.

#### `POST /api/v1/pagos` `[jwt]` — Rol mínimo: cajero

**Request:**
```json
{
  "idPedido": 2,
  "monto":    33.00,
  "metodo":   "Efectivo"
}
```

**Flujo interno:**
```
1. Verificar rol (cajero/admin)
2. Validar campos (monto > 0, metodo válido, idPedido existe)
3. Verificar que el pedido no esté Pagado ni Cancelado
4. PagoModel::registrarPago() → INSERT INTO Pago
   └── trg_pago_antes_insertar_validar:
       ├── OK → continúa
       └── monto > saldo → SIGNAL SQLSTATE '45000' → DatabaseException
5. PagoModel devuelve { success, codigo_error, idPago, mensaje }
6. Controlador mapea resultado → respondCreated() o HTTP 422
```

**Response 201 (pago exitoso):**
```json
{
  "success": true,
  "message": "Pago de S/ 33.00 registrado exitosamente.",
  "data": {
    "idPago": 5,
    "recibo": {
      "empresa":       "LAVANDERÍA MARYCLEAN",
      "recibo_n":      "00000005",
      "fecha_pago":    "01/09/2026 14:30:00",
      "ticket":        "MC-20260901-TEST2",
      "cliente":       "ROSA ELENA PAREDES VIDAL",
      "documento":     "87654321",
      "monto_abonado": "33.00",
      "metodo":        "Efectivo",
      "total_pedido":  "53.00",
      "estado_pedido": "Pagado"
    },
    "pedidoEstado": "Pagado"
  }
}
```

**Response 422 (sobrepago — SQLSTATE 45000):**
```json
{
  "success": false,
  "status":  422,
  "message": "El monto (S/ 50.00) supera el saldo pendiente (S/ 33.00).",
  "data":    null,
  "errors":  { "codigo_error": "SOBREPAGO_TRIGGER_45000" }
}
```

---

#### `GET /api/v1/pagos/pedido/:idPedido` `[jwt]`

Consulta el historial de pagos y el saldo pendiente de un pedido.

**Response 200:**
```json
{
  "success": true,
  "data": {
    "idPedido":        2,
    "total":           53.00,
    "total_pagado":    20.00,
    "saldo_pendiente": 33.00,
    "estado":          "En Proceso",
    "pagos": [
      { "idPago": 1, "monto": 20.00, "metodo": "Efectivo", "fechaPago": "..." }
    ]
  }
}
```

---

### 7.6 ReportesController

**Archivo:** [`app/Controllers/Api/ReportesController.php`](file:///c:/Users/ALEX/Downloads/Proyecto_codeIgniter/proyecto_prueba/app/Controllers/Api/ReportesController.php)

#### `GET /api/v1/reportes/dashboard` `[jwt]`

Resumen del día para la pantalla de inicio de la app y el panel web.
Accesible para todos los roles autenticados.

**Response 200:**
```json
{
  "success": true,
  "data": {
    "pedidos_hoy":     8,
    "ingresos_hoy":    "275.00",
    "pedidos_activos": 5,
    "pendientes_cobro": 2,
    "fecha":           "01/09/2026"
  }
}
```

---

#### `GET /api/v1/reportes/diario?fecha=YYYY-MM-DD` `[jwt]` — cajero/admin

Consume la vista `v_reporte_diario`. Agrupa ingresos del día por método de pago.

**Response 200:**
```json
{
  "success": true,
  "data": {
    "fecha": "2026-09-01",
    "filas": [
      { "metodo": "Efectivo",  "totalIngresos": 150.00, "cantidadTransacciones": 5 },
      { "metodo": "Tarjeta",   "totalIngresos": 80.00,  "cantidadTransacciones": 2 },
      { "metodo": "Yape/Plin", "totalIngresos": 45.00,  "cantidadTransacciones": 3 }
    ],
    "total_dia": 275.00
  }
}
```

---

#### `GET /api/v1/reportes/cierre-caja?fecha=YYYY-MM-DD` `[jwt]` — cajero/admin

Ejecuta el Stored Procedure `sp_cierre_caja` con `GROUP BY metodo WITH ROLLUP`.
La fila `metodo = NULL` del SP es el total general — el controlador la separa.

**Response 200:**
```json
{
  "success": true,
  "message": "Cierre de caja del 2026-09-01 generado exitosamente.",
  "data": {
    "fecha": "2026-09-01",
    "desglose": [
      { "metodo": "Efectivo",  "cantidadTransacciones": 5,  "total_ingresos": 150.00 },
      { "metodo": "Tarjeta",   "cantidadTransacciones": 2,  "total_ingresos": 80.00  },
      { "metodo": "Yape/Plin", "cantidadTransacciones": 3,  "total_ingresos": 45.00  }
    ],
    "total_general":     275.00,
    "total_operaciones": 10
  }
}
```

---

## 8. RBAC — Control de Acceso por Roles

### Jerarquía

```
admin  ──> puede acceder a rutas de:  [admin] + [cajero] + [recepcionista]
cajero ──> puede acceder a rutas de:  [cajero] + [recepcionista]
recepcionista ──> solo rutas de:      [recepcionista]
```

### Implementación por Capa

| Capa | Implementación |
|---|---|
| **Filtro (JwtFilter)** | `['filter' => 'jwt:admin']` — bloquea antes de llegar al controlador |
| **Controlador** | `$this->tieneRol('cajero', 'admin')` — lógica interna para permisos granulares |

### Tabla de Permisos por Endpoint

| Endpoint | admin | cajero | recepcionista |
|---|---|---|---|
| `POST /auth/login` | ✅ | ✅ | ✅ |
| `GET /clientes` | ✅ | ✅ | ✅ |
| `POST /pedidos` | ✅ | ✅ | ✅ |
| `PATCH /pedidos/:id/estado` | ✅ | ✅ | ✅ |
| **Cancelar pedido** | ✅ | ❌ | ❌ |
| `POST /pagos` | ✅ | ✅ | ❌ |
| `GET /pagos/:id/recibo` | ✅ | ✅ | ❌ |
| `GET /reportes/diario` | ✅ | ✅ | ❌ |
| `GET /reportes/cierre-caja` | ✅ | ✅ | ❌ |
| `GET /reportes/mensual` | ✅ | ❌ | ❌ |
| `GET /reportes/servicios` | ✅ | ❌ | ❌ |

---

## 9. Manejo de Errores y Excepciones

### Validación de CI4 (antes de llegar a la BD)

```php
if (! $this->validateData($body, $rules)) {
    return $this->respondValidationError($this->validator->getErrors());
}
// Respuesta: HTTP 422, errors: { "campo": "mensaje de error" }
```

### Excepciones de Base de Datos

El método `handleDbException()` del `BaseApiController` clasifica automáticamente
los errores usando el `Trait DbExceptionHandler`:

```
DatabaseException capturada
       |
       +-- ¿contiene SQLSTATE '45000'? ──> HTTP 422, SOBREPAGO_TRIGGER_45000
       +-- ¿contiene 'Duplicate entry'? ──> HTTP 409, DUPLICATE_ENTRY
       +-- ¿contiene 'foreign key'? ──────> HTTP 400, FOREIGN_KEY_VIOLATION
       +-- ¿contiene 'check constraint'? ─> HTTP 400, CHECK_CONSTRAINT_VIOLATION
       +-- Default ───────────────────────> HTTP 500, DATABASE_ERROR
```

### Errores de Autenticación

| Código | Causa | Error en `errors` |
|---|---|---|
| 401 | No hay header Authorization | `{ auth: "TOKEN_MISSING" }` |
| 401 | Token expirado | `{ auth: "TOKEN_INVALID" }` |
| 401 | Firma inválida | `{ auth: "TOKEN_INVALID" }` |
| 403 | Rol insuficiente | `{ auth: "FORBIDDEN", rol_actual, roles_requeridos }` |

---

## 10. CORS — Integración con Node.js y Expo Go

### Qué hace el CorsFilter

1. **Peticiones preflight** (`OPTIONS`): responde `HTTP 204` con los headers CORS
   **sin** pasar al controlador. Esto evita que CI4 requiera autenticación para OPTIONS.

2. **Todas las demás peticiones**: agrega los headers `Access-Control-*` en `after()`.

### Configuración por Entorno

```env
# .env (desarrollo local)
cors.allowedOrigins = http://localhost:3000,http://localhost:8081

# .env (producción)
cors.allowedOrigins = https://app.maryclean.com,https://admin.maryclean.com
```

### Expo Go en Dispositivo Físico

Expo Go en un dispositivo físico genera URLs del tipo `exp://192.168.1.x:8081`.
Agregar la IP de la máquina de desarrollo al `.env`:

```env
cors.allowedOrigins = http://localhost:3000,http://localhost:8081,exp://192.168.1.10:8081
```

---

## 11. Guía de Integración para Clientes

### Node.js (Fetch / Axios)

```javascript
// Configuración base
const API_BASE = 'http://localhost:8080/api/v1';
const token    = localStorage.getItem('maryclean_token');

// Login
const login = async (username, password) => {
  const res = await fetch(`${API_BASE}/auth/login`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ username, password }),
  });
  const json = await res.json();
  if (json.success) localStorage.setItem('maryclean_token', json.data.token);
  return json;
};

// Petición autenticada
const getPedidos = async () => {
  const res = await fetch(`${API_BASE}/pedidos`, {
    headers: { 'Authorization': `Bearer ${token}` },
  });
  return res.json(); // { success, data: { pedidos, total } }
};

// Registrar pago (manejar error de sobrepago)
const registrarPago = async (idPedido, monto, metodo) => {
  const res = await fetch(`${API_BASE}/pagos`, {
    method:  'POST',
    headers: {
      'Authorization':  `Bearer ${token}`,
      'Content-Type':   'application/json',
    },
    body: JSON.stringify({ idPedido, monto, metodo }),
  });
  const json = await res.json();
  if (!json.success && json.errors?.codigo_error === 'SOBREPAGO_TRIGGER_45000') {
    console.error('Monto excede el saldo pendiente:', json.message);
  }
  return json;
};
```

---

### Expo Go (React Native)

```javascript
import * as SecureStore from 'expo-secure-store';

const API_BASE = 'http://192.168.1.10:8080/api/v1'; // IP del servidor en la red local

// Login y almacenamiento seguro del token
const login = async (username, password) => {
  const res  = await fetch(`${API_BASE}/auth/login`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ username, password }),
  });
  const json = await res.json();
  if (json.success) {
    await SecureStore.setItemAsync('jwt_token', json.data.token);
  }
  return json;
};

// Obtener el token almacenado
const getToken = () => SecureStore.getItemAsync('jwt_token');

// Búsqueda de cliente por documento (módulo recepción)
const buscarClientePorDocumento = async (documento) => {
  const token = await getToken();
  const res   = await fetch(`${API_BASE}/clientes/documento/${documento}`, {
    headers: { 'Authorization': `Bearer ${token}` },
  });
  return res.json();
};

// Crear pedido
const crearPedido = async (idCliente, detalles) => {
  const token = await getToken();
  const res   = await fetch(`${API_BASE}/pedidos`, {
    method:  'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type':  'application/json',
    },
    body: JSON.stringify({ idCliente, detalles }),
  });
  return res.json();
};
```

---

## 12. Configuración del Entorno

### Variables requeridas en `.env`

```env
# Base de datos
database.default.hostname = localhost
database.default.database = lavanderia
database.default.username = maryclean_app
database.default.password = password_seguro
database.default.DBDriver = MySQLi

# JWT
jwt.secret  = CAMBIAR_ESTO_A_32_CARACTERES_ALEATORIOS_EN_PRODUCCION
jwt.ttl     = 28800
jwt.issuer  = maryclean-api

# CORS
cors.allowedOrigins = http://localhost:3000,http://localhost:8081
cors.allowedMethods = GET, POST, PUT, PATCH, DELETE, OPTIONS
```

> **Seguridad crítica en producción:**
> El `jwt.secret` debe ser una cadena aleatoria de al menos 32 caracteres.
> Generar con: `openssl rand -base64 48`

### Credenciales de Prueba (solo `lavanderia_test`)

| Username | Password | Rol |
|---|---|---|
| `admin001` | `Admin123!` | admin |
| `cajero001` | `Cajero123!` | cajero |
| `recep001` | `Recep123!` | recepcionista |
| `cajero002` | `Cajero123!` | cajero |
| `recep002` | `Recep123!` | recepcionista |

---

## 13. Guía de Extensión

### Agregar un Nuevo Endpoint

```php
// 1. Agregar el método en el controlador correspondiente
public function miNuevoEndpoint(): ResponseInterface {
    $body = $this->getJsonBody();

    if (! $this->validateData($body, ['campo' => 'required'])) {
        return $this->respondValidationError($this->validator->getErrors());
    }

    $resultado = $this->miModelo->miMetodo($body['campo']);
    return $this->respondSuccess($resultado, 'Operación completada.');
}

// 2. Registrar la ruta en app/Config/Routes.php
$routes->get('mi-recurso', 'Api\MiController::miNuevoEndpoint');
```

### Agregar un Nuevo Controlador API

```php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Models\MiModelo;
use CodeIgniter\HTTP\ResponseInterface;

class MiController extends BaseApiController {
    private MiModelo $modelo;

    public function __construct() {
        $this->modelo = new MiModelo();
    }

    public function index(): ResponseInterface {
        $data = $this->modelo->findAll();
        return $this->respondSuccess($data);
    }
}
```

### Cambiar el TTL del JWT

```env
# .env — 8 horas de sesión
jwt.ttl = 28800
```

### Agregar un Origen CORS para Producción

```env
cors.allowedOrigins = https://app.maryclean.com,https://admin.maryclean.com
```

### NUNCA hacer esto en un controlador API

```php
// NUNCA SQL en el controlador
$this->db->query("SELECT * FROM Pedido WHERE ...");

// NUNCA lógica de negocio en el controlador
if ($monto > $total - $pagado) { ... }  // Esto es del trigger y el modelo

// NUNCA retornar HTML desde un controlador API
return view('alguna_vista');

// NUNCA exponer el hash de contraseña
$empleado['password']; // EmpleadoModel::autenticar() hace unset() automáticamente
```

---

*MaryClean API REST v1.0 — Controladores CI4 API-First*
*Documentación técnica generada: 2026-09-01*
