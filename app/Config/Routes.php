<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// -----------------------------------------------------------------------
// RUTA WEB PRINCIPAL
// -----------------------------------------------------------------------
$routes->get('/', 'DocsController::index');

// -----------------------------------------------------------------------
// API REST v1 — MaryClean
//
// Prefijo:  /api/v1/
// Filtros:  cors   → CorsFilter (CORS headers + preflight OPTIONS)
//           jwt    → JwtFilter  (valida Bearer token)
//           jwt:admin  → JWT + solo rol 'admin'
//           jwt:cajero → JWT + roles 'cajero' y 'admin' (jerarquía)
//
// Clientes: Frontend Node.js | App Móvil Expo Go
// -----------------------------------------------------------------------

// -----------------------------------------------------------------------
// AUTH — Sin protección JWT (rutas públicas de la API)
// -----------------------------------------------------------------------
$routes->group('api/v1', ['filter' => 'cors'], function (RouteCollection $routes) {

    // Swagger UI y Especificación OpenAPI
    $routes->get('docs', '\App\Controllers\DocsController::index');

    // POST /api/v1/auth/login
    // REGLA 3: Aplicar filtro throttler para prevenir fuerza bruta (máximo 4 intentos por minuto por defecto)
    $routes->post('auth/login', 'Api\AuthController::login', ['filter' => 'throttler']);

    // GET  /api/v1/auth/me   ← Requiere JWT
    $routes->get('auth/me', 'Api\AuthController::me', ['filter' => 'jwt']);

    // -----------------------------------------------------------------------
    // CLIENTES [jwt requerido para todos los roles]
    // -----------------------------------------------------------------------
    $routes->group('clientes', ['filter' => 'jwt'], function (RouteCollection $routes) {
        // GET    /api/v1/clientes                   → listado paginado + búsqueda
        $routes->get('/', 'Api\ClientesController::index');

        // GET    /api/v1/clientes/documento/:doc    → búsqueda rápida por documento
        $routes->get('documento/(:segment)', 'Api\ClientesController::buscarPorDocumento/$1');

        // GET    /api/v1/clientes/:id               → detalle de un cliente
        $routes->get('(:num)', 'Api\ClientesController::show/$1');

        // POST   /api/v1/clientes                   → crear cliente
        $routes->post('/', 'Api\ClientesController::create');

        // PUT    /api/v1/clientes/:id               → actualizar cliente
        $routes->put('(:num)', 'Api\ClientesController::update/$1');
    });

    // -----------------------------------------------------------------------
    // PEDIDOS [jwt requerido para todos los roles]
    // -----------------------------------------------------------------------
    $routes->group('pedidos', ['filter' => 'jwt'], function (RouteCollection $routes) {
        // GET    /api/v1/pedidos                    → v_pedidos_activos
        $routes->get('/', 'Api\PedidosController::index');

        // GET    /api/v1/pedidos/:id                → pedido completo con detalles y pagos
        $routes->get('(:num)', 'Api\PedidosController::show/$1');

        // POST   /api/v1/pedidos                    → sp_registrar_recepcion + detalles
        $routes->post('/', 'Api\PedidosController::crearRecepcion');

        // GET    /api/v1/pedidos/:id/ticket         → datos estructurados para ticket imprimible
        $routes->get('(:num)/ticket', 'Api\PedidosController::obtenerTicket/$1');

        // PATCH  /api/v1/pedidos/:id/estado         → cambio de estado (dispara triggers)
        $routes->patch('(:num)/estado', 'Api\PedidosController::cambiarEstado/$1');
    });

    // -----------------------------------------------------------------------
    // PAGOS [jwt requerido — cobro solo por cajero/admin]
    // -----------------------------------------------------------------------
    $routes->group('pagos', ['filter' => 'jwt'], function (RouteCollection $routes) {
        // POST   /api/v1/pagos                      → registrar pago presencial (SQLSTATE 45000)
        $routes->post('/', 'Api\PagosController::registrarPago');

        // GET    /api/v1/pagos/pedido/:idPedido     → historial y saldo pendiente
        $routes->get('pedido/(:num)', 'Api\PagosController::obtenerHistorial/$1');

        // GET    /api/v1/pagos/:idPago/recibo       → datos del recibo para re-impresión
        $routes->get('(:num)/recibo', 'Api\PagosController::obtenerRecibo/$1');
    });

    // -----------------------------------------------------------------------
    // REPORTES [jwt:cajero — requiere al menos rol cajero]
    // -----------------------------------------------------------------------
    $routes->group('reportes', ['filter' => 'jwt'], function (RouteCollection $routes) {
        // GET    /api/v1/reportes/dashboard         → resumen del día (todos los roles)
        $routes->get('dashboard', 'Api\ReportesController::dashboard');

        // GET    /api/v1/reportes/diario            → v_reporte_diario (cajero, admin)
        $routes->get('diario', 'Api\ReportesController::reporteDiario');

        // GET    /api/v1/reportes/mensual           → reporte mensual (solo admin)
        $routes->get('mensual', 'Api\ReportesController::reporteMensual');

        // GET    /api/v1/reportes/cierre-caja       → sp_cierre_caja ROLLUP (cajero, admin)
        $routes->get('cierre-caja', 'Api\ReportesController::cierreCaja');

        // GET    /api/v1/reportes/servicios         → ranking servicios (solo admin)
        $routes->get('servicios', 'Api\ReportesController::serviciosMasSolicitados');
    });

    // -----------------------------------------------------------------------
    // Ruta catch-all para endpoints inexistentes dentro de /api/v1/
    // -----------------------------------------------------------------------
    $routes->set404Override(function () {
        return service('response')
            ->setStatusCode(404)
            ->setJSON([
                'success' => false,
                'status'  => 404,
                'message' => 'Endpoint no encontrado en la API de MaryClean.',
                'data'    => null,
                'errors'  => null,
            ]);
    });
});
