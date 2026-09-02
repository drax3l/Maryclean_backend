<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ReporteModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * ReportesController
 *
 * API REST para el módulo de reportes y cierre de caja de MaryClean.
 * Consume las vistas SQL y el Stored Procedure de cierre de caja.
 *
 * ACCESO: Roles 'cajero' y 'admin' únicamente.
 *
 * ENDPOINTS:
 *   GET /api/v1/reportes/diario?fecha=YYYY-MM-DD  → reporteDiario()   [jwt: cajero, admin]
 *   GET /api/v1/reportes/mensual?año=YYYY&mes=MM  → reporteMensual()  [jwt: admin]
 *   GET /api/v1/reportes/cierre-caja?fecha=YYYY-MM-DD → cierreCaja()  [jwt: cajero, admin]
 *   GET /api/v1/reportes/dashboard                → dashboard()       [jwt]
 *   GET /api/v1/reportes/servicios?desde=&hasta=  → serviciosMasSolicitados() [jwt: admin]
 *
 * ELEMENTOS DE BD CONSUMIDOS:
 *   reporteDiario() → vista `v_reporte_diario`
 *   cierreCaja()    → SP `sp_cierre_caja` (GROUP BY metodo WITH ROLLUP)
 *   dashboard()     → múltiples consultas agregadas en ReporteModel
 *
 * THIN CONTROLLER:
 * No contiene SQL. Delega completamente a ReporteModel.
 *
 * @package App\Controllers\Api
 */
class ReportesController extends BaseApiController
{
    private ReporteModel $reporteModel;

    public function __construct()
    {
        $this->reporteModel = new ReporteModel();
    }

    // ---------------------------------------------------------------
    // GET /api/v1/reportes/diario?fecha=YYYY-MM-DD
    // ---------------------------------------------------------------

    /**
     * Retorna el reporte de ingresos de un día específico desde `v_reporte_diario`.
     * Agrupa por método de pago (Efectivo, Tarjeta, Yape/Plin).
     *
     * ACCESO: cajero y admin.
     *
     * QUERY PARAMS:
     *   fecha → Fecha del reporte (formato YYYY-MM-DD). Default: hoy.
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "fecha":   "2026-09-01",
     *     "filas": [
     *       { "fechaPago": "2026-09-01", "metodo": "Efectivo",  "totalIngresos": 150.00, "cantidadTransacciones": 5 },
     *       { "fechaPago": "2026-09-01", "metodo": "Tarjeta",   "totalIngresos": 80.00,  "cantidadTransacciones": 2 },
     *       { "fechaPago": "2026-09-01", "metodo": "Yape/Plin", "totalIngresos": 45.00,  "cantidadTransacciones": 3 }
     *     ],
     *     "total_dia": 275.00
     *   }
     * }
     *
     * @return ResponseInterface
     */
    public function reporteDiario(): ResponseInterface
    {
        if (! $this->tieneRol('cajero', 'admin')) {
            return $this->respondError('Acceso denegado. Requiere rol cajero o admin.', ResponseInterface::HTTP_FORBIDDEN);
        }

        $fecha = $this->request->getGet('fecha') ?? date('Y-m-d');

        // Validar formato de fecha
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return $this->respondError('Formato de fecha inválido. Use YYYY-MM-DD.', ResponseInterface::HTTP_BAD_REQUEST);
        }

        $filas      = $this->reporteModel->getReportePorRango($fecha, $fecha);
        $totalDia   = array_sum(array_column($filas, 'totalIngresos'));

        return $this->respondSuccess([
            'fecha'     => $fecha,
            'filas'     => $filas,
            'total_dia' => round($totalDia, 2),
        ]);
    }

    // ---------------------------------------------------------------
    // GET /api/v1/reportes/mensual?año=YYYY&mes=MM
    // ---------------------------------------------------------------

    /**
     * Retorna el reporte de ingresos de un mes completo desde `v_reporte_diario`.
     *
     * ACCESO: solo admin.
     *
     * @return ResponseInterface
     */
    public function reporteMensual(): ResponseInterface
    {
        if (! $this->tieneRol('admin')) {
            return $this->respondError('Acceso denegado. Requiere rol admin.', ResponseInterface::HTTP_FORBIDDEN);
        }

        $año = (int) ($this->request->getGet('año') ?? date('Y'));
        $mes = (int) ($this->request->getGet('mes') ?? date('n'));

        if ($año < 2000 || $año > 2100 || $mes < 1 || $mes > 12) {
            return $this->respondError('Año o mes inválido.', ResponseInterface::HTTP_BAD_REQUEST);
        }

        $filas         = $this->reporteModel->getReporteMensual($año, $mes);
        $totalMes      = array_sum(array_column($filas, 'totalIngresos'));
        $totalOps      = array_sum(array_column($filas, 'cantidadTransacciones'));

        return $this->respondSuccess([
            'año'          => $año,
            'mes'          => $mes,
            'filas'        => $filas,
            'total_mes'    => round($totalMes, 2),
            'total_operaciones' => (int) $totalOps,
        ]);
    }

    // ---------------------------------------------------------------
    // GET /api/v1/reportes/cierre-caja?fecha=YYYY-MM-DD
    // ---------------------------------------------------------------

    /**
     * Ejecuta el Stored Procedure `sp_cierre_caja` para la fecha indicada.
     * Devuelve el desglose de ingresos por método de pago usando ROLLUP.
     * La fila con metodo=null del SP representa el TOTAL GENERAL.
     *
     * ACCESO: cajero y admin.
     *
     * QUERY PARAMS:
     *   fecha → Fecha del cierre (YYYY-MM-DD). Default: hoy.
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "fecha":         "2026-09-01",
     *     "desglose": [
     *       { "metodo": "Efectivo",  "cantidadTransacciones": 5,  "total_ingresos": 150.00 },
     *       { "metodo": "Tarjeta",   "cantidadTransacciones": 2,  "total_ingresos": 80.00  },
     *       { "metodo": "Yape/Plin", "cantidadTransacciones": 3,  "total_ingresos": 45.00  }
     *     ],
     *     "total_general": 275.00,
     *     "total_operaciones": 10
     *   }
     * }
     *
     * @return ResponseInterface
     */
    public function cierreCaja(): ResponseInterface
    {
        if (! $this->tieneRol('cajero', 'admin')) {
            return $this->respondError('Acceso denegado. Requiere rol cajero o admin.', ResponseInterface::HTTP_FORBIDDEN);
        }

        $fecha = $this->request->getGet('fecha') ?? date('Y-m-d');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return $this->respondError('Formato de fecha inválido. Use YYYY-MM-DD.', ResponseInterface::HTTP_BAD_REQUEST);
        }

        try {
            $resultado = $this->reporteModel->ejecutarCierreCaja($fecha);

            $totalOps = array_sum(array_column($resultado['filas'], 'cantidadTransacciones'));

            return $this->respondSuccess([
                'fecha'              => $resultado['fecha'],
                'desglose'           => $resultado['filas'],
                'total_general'      => $resultado['total_general'],
                'total_operaciones'  => (int) $totalOps,
            ], "Cierre de caja del {$fecha} generado exitosamente.");
        } catch (DatabaseException $e) {
            return $this->handleDbException($e);
        }
    }

    // ---------------------------------------------------------------
    // GET /api/v1/reportes/dashboard
    // ---------------------------------------------------------------

    /**
     * Devuelve el resumen estadístico del día para el Dashboard principal
     * del Frontend Web y la pantalla de inicio de la App Móvil.
     *
     * ACCESO: todos los roles autenticados.
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "pedidos_hoy":     8,
     *     "ingresos_hoy":    "275.00",
     *     "pedidos_activos": 5,
     *     "pendientes_cobro":2,
     *     "fecha":           "01/09/2026"
     *   }
     * }
     *
     * @return ResponseInterface
     */
    public function dashboard(): ResponseInterface
    {
        $resumen = $this->reporteModel->getResumenDashboard();
        return $this->respondSuccess($resumen);
    }

    // ---------------------------------------------------------------
    // GET /api/v1/reportes/servicios?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
    // ---------------------------------------------------------------

    /**
     * Retorna el ranking de servicios más solicitados en un rango de fechas.
     * Agrupa por tipo de servicio con el total de prendas e importe generado.
     *
     * ACCESO: solo admin.
     *
     * @return ResponseInterface
     */
    public function serviciosMasSolicitados(): ResponseInterface
    {
        if (! $this->tieneRol('admin')) {
            return $this->respondError('Acceso denegado. Requiere rol admin.', ResponseInterface::HTTP_FORBIDDEN);
        }

        $desde = $this->request->getGet('desde') ?? date('Y-m-01'); // Primer día del mes
        $hasta = $this->request->getGet('hasta') ?? date('Y-m-d');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            return $this->respondError('Formato de fecha inválido. Use YYYY-MM-DD.', ResponseInterface::HTTP_BAD_REQUEST);
        }

        $ranking = $this->reporteModel->getServiciosMasSolicitados($desde, $hasta);

        return $this->respondSuccess([
            'desde'   => $desde,
            'hasta'   => $hasta,
            'ranking' => $ranking,
        ]);
    }
}
