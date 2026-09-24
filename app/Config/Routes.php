<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// -----------------------------------------------------------------------
// RUTAS WEB
// -----------------------------------------------------------------------
$routes->get('/', 'DocsController::index');

// -----------------------------------------------------------------------
// SWAGGER UI â€” Ruta pÃºblica (sin JWT, sin CSRF)
// -----------------------------------------------------------------------
$routes->get('api/v1/docs', 'DocsController::index');

// -----------------------------------------------------------------------
// API REST v1 â€” MaryClean
//
// Prefijo:  /api/v1/
// Filtros:  mcCors â†’ CorsFilter custom (CORS headers + preflight OPTIONS)
//           jwt    â†’ JwtFilter  (valida Bearer token)
//
// Clientes: Frontend Node.js | App MÃ³vil Expo Go
// -----------------------------------------------------------------------

// -----------------------------------------------------------------------
// 404 GLOBAL JSON â€” Para cualquier endpoint inexistente de la API
// (Debe estar FUERA del grupo para no interferir con las rutas definidas)
// -----------------------------------------------------------------------
$routes->set404Override(function () {
    throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
});

// -----------------------------------------------------------------------
// AUTH â€” Sin protecciÃ³n JWT (rutas pÃºblicas de la API)
// -----------------------------------------------------------------------
$routes->group('api/v1', ['filter' => 'mcCors'], function (RouteCollection $routes) {

    // POST /api/v1/auth/login
    // Rate Limiting: mÃ¡ximo 4 intentos por minuto por IP
    $routes->post('auth/login', 'Api\AuthController::login', ['filter' => 'throttler']);

    // GET  /api/v1/auth/me   â† Requiere JWT
    $routes->get('auth/me', 'Api\AuthController::me', ['filter' => 'jwt']);

    // -----------------------------------------------------------------------
    // CLIENTES [jwt requerido para todos los roles]
    // -----------------------------------------------------------------------
    $routes->group('clientes', ['filter' => 'jwt'], function (RouteCollection $routes) {
        // GET    /api/v1/clientes                   â†’ listado paginado + bÃºsqueda
        $routes->get('/', 'Api\ClientesController::index');

        // GET    /api/v1/clientes/documento/:doc    â†’ bÃºsqueda rÃ¡pida por documento
        $routes->get('documento/(:segment)', 'Api\ClientesController::buscarPorDocumento/$1');

        // GET    /api/v1/clientes/:id               â†’ detalle de un cliente
        $routes->get('(:num)', 'Api\ClientesController::show/$1');

        // POST   /api/v1/clientes                   â†’ crear cliente
        $routes->post('/', 'Api\ClientesController::create');

        // PUT    /api/v1/clientes/:id               â†’ actualizar cliente
        $routes->put('(:num)', 'Api\ClientesController::update/$1');
    });

    // -----------------------------------------------------------------------
    // PEDIDOS [jwt requerido para todos los roles]
    // -----------------------------------------------------------------------
    $routes->group('pedidos', ['filter' => 'jwt'], function (RouteCollection $routes) {
        // GET    /api/v1/pedidos                    â†’ v_pedidos_activos
        $routes->get('/', 'Api\PedidosController::index');

        // GET    /api/v1/pedidos/:id                â†’ pedido completo con detalles y pagos
        $routes->get('(:num)', 'Api\PedidosController::show/$1');

        // POST   /api/v1/pedidos                    â†’ sp_registrar_recepcion + detalles
        $routes->post('/', 'Api\PedidosController::crearRecepcion');

        // GET    /api/v1/pedidos/:id/ticket         â†’ datos estructurados para ticket imprimible
        $routes->get('(:num)/ticket', 'Api\PedidosController::obtenerTicket/$1');

        // PATCH  /api/v1/pedidos/:id/estado         â†’ cambio de estado (dispara triggers)
        $routes->patch('(:num)/estado', 'Api\PedidosController::cambiarEstado/$1');
    });

    // -----------------------------------------------------------------------
    // PAGOS [jwt requerido â€” cobro solo por cajero/admin]
    // -----------------------------------------------------------------------
    $routes->group('pagos', ['filter' => 'jwt'], function (RouteCollection $routes) {
        // POST   /api/v1/pagos                      â†’ registrar pago presencial (SQLSTATE 45000)
        $routes->post('/', 'Api\PagosController::registrarPago');

        // GET    /api/v1/pagos/pedido/:idPedido     â†’ historial y saldo pendiente
        $routes->get('pedido/(:num)', 'Api\PagosController::obtenerHistorial/$1');

        // GET    /api/v1/pagos/:idPago/recibo       â†’ datos del recibo para re-impresiÃ³n
        $routes->get('(:num)/recibo', 'Api\PagosController::obtenerRecibo/$1');
    });

    // -----------------------------------------------------------------------
    // REPORTES [jwt requerido]
    // -----------------------------------------------------------------------
    $routes->group('reportes', ['filter' => 'jwt'], function (RouteCollection $routes) {
        // GET    /api/v1/reportes/dashboard         â†’ resumen del dÃ­a (todos los roles)
        $routes->get('dashboard', 'Api\ReportesController::dashboard');

        // GET    /api/v1/reportes/diario            â†’ v_reporte_diario (cajero, admin)
        $routes->get('diario', 'Api\ReportesController::reporteDiario');

        // GET    /api/v1/reportes/mensual           â†’ reporte mensual (solo admin)
        $routes->get('mensual', 'Api\ReportesController::reporteMensual');

        // GET    /api/v1/reportes/cierre-caja       → sp_cierre_caja ROLLUP (cajero, admin)
        $routes->get('cierre-caja', 'Api\ReportesController::cierreCaja');

        // GET    /api/v1/reportes/servicios         → ranking servicios (solo admin)
        $routes->get('servicios', 'Api\ReportesController::serviciosMasSolicitados');

        // GET    /api/v1/reportes/clientes-frecuentes → Top 5-10 clientes con más pedidos y dinero (solo admin)
        $routes->get('clientes-frecuentes', 'Api\ReportesController::clientesFrecuentes');

        // GET    /api/v1/reportes/evolucion         → Gráfico de Barras
        $routes->get('evolucion', 'Api\ReportesController::evolucion');

        // GET    /api/v1/reportes/distribucion      → Gráfico Circular
        $routes->get('distribucion', 'Api\ReportesController::distribucion');

        // GET    /api/v1/reportes/entregas-urgentes → Entregas Urgentes
        $routes->get('entregas-urgentes', 'Api\ReportesController::entregasUrgentes');
    // -----------------------------------------------------------------------
    });

    // -----------------------------------------------------------------------
    // SERVICIOS [jwt requerido] — Categorías padre + CRUD de prendas
    // -----------------------------------------------------------------------

    // GET  /api/v1/servicios            → lista de categorías con prendas anidadas
    // GET  /api/v1/servicios/{id}       → detalle de una categoría con sus prendas
    $routes->group('servicios', ['filter' => 'jwt'], function (RouteCollection $routes) {
        $routes->get('/',         'Api\ServiciosController::indexServicios');
        $routes->get('(:num)',    'Api\ServiciosController::showServicio/$1');

        // CRUD de prendas dentro de un servicio
        // GET  /api/v1/servicios/prendas       → catálogo completo (todas las prendas)
        // POST /api/v1/servicios/prendas       → crear nueva prenda
        // PUT  /api/v1/servicios/prendas/{id}  → editar prenda (precio, nombre, estado)
        $routes->get('prendas',          'Api\ServiciosController::indexPrendas');
        $routes->post('prendas',         'Api\ServiciosController::createPrenda');
        $routes->put('prendas/(:num)',   'Api\ServiciosController::updatePrenda/$1');
    });

    // -----------------------------------------------------------------------
    // EMPLEADOS [jwt:admin — solo administradores]
    // -----------------------------------------------------------------------
    $routes->group('empleados', ['filter' => ['jwt:admin', 'throttler:60,60']], function (RouteCollection $routes) {
        $routes->get('/', 'Api\EmpleadosController::index');
        $routes->post('/', 'Api\EmpleadosController::create', ['filter' => 'throttler:10,60']);
        $routes->patch('(:num)/estado', 'Api\EmpleadosController::cambiarEstado/$1');
        $routes->patch('(:num)/password', 'Api\EmpleadosController::cambiarPassword/$1');
        $routes->delete('(:num)', 'Api\EmpleadosController::delete/$1');
    });

    // -----------------------------------------------------------------------
    // SUCURSALES [jwt:admin — solo administradores]
    // -----------------------------------------------------------------------
    $routes->group('sucursales', ['filter' => ['jwt:admin', 'throttler:120,60']], function (RouteCollection $routes) {
        $routes->get('/', 'Api\SucursalesController::index');
        $routes->get('(:num)', 'Api\SucursalesController::show/$1');
        $routes->post('/', 'Api\SucursalesController::create', ['filter' => 'throttler:30,60']);
        $routes->put('(:num)', 'Api\SucursalesController::update/$1');
    });
});
