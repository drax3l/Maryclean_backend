<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use CodeIgniter\Database\Exceptions\DatabaseException;
use App\Libraries\DbExceptionHandler;

/**
 * ReporteModel
 *
 * Modelo dedicado a la consulta de vistas y ejecución del Stored Procedure
 * de cierre de caja. No tiene tabla propia; actúa como capa de acceso a datos
 * para los reportes del sistema MaryClean.
 *
 * ELEMENTOS DE DB QUE CONSUME:
 * - Vista `v_pedidos_activos`: Pedidos en curso con datos de cliente/empleado.
 * - Vista `v_reporte_diario`: Ingresos del día agrupados por método de pago.
 * - SP `sp_cierre_caja`: Calcula el desglose de ingresos con GROUP BY ... WITH ROLLUP.
 *
 * @package App\Models
 */
class ReporteModel extends Model
{
    use DbExceptionHandler;

    /**
     * Esta clase no representa una tabla directa.
     * Se asigna 'Pedido' como tabla base para satisfacer al ORM de CI4,
     * pero todos los métodos usan queries directas a vistas/SPs.
     */
    protected $table      = 'Pedido';
    protected $returnType = 'array';

    protected $allowedFields = [];
    protected $useTimestamps = false;

    // ---------------------------------------------------------------
    // Vista: v_pedidos_activos
    // ---------------------------------------------------------------

    /**
     * Obtiene todos los pedidos activos desde la vista `v_pedidos_activos`.
     * La vista excluye los estados 'Entregado' y 'Cancelado'.
     *
     * @return array
     */
    public function getPedidosActivos(): array
    {
        return $this->db->table('v_pedidos_activos')
            ->orderBy('fechaRecepcion', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Obtiene pedidos activos filtrados por sucursal desde la vista.
     *
     * @param int $idSucursal
     * @return array
     */
    public function getPedidosActivosPorSucursal(int $idSucursal): array
    {
        return $this->db->table('v_pedidos_activos')
            ->where('idSucursal', $idSucursal)
            ->orderBy('fechaRecepcion', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Obtiene los pedidos activos filtrados por estado desde la vista.
     *
     * @param string $estado
     * @return array
     */
    public function getPedidosActivosPorEstado(string $estado): array
    {
        return $this->db->table('v_pedidos_activos')
            ->where('estado', $estado)
            ->orderBy('fechaRecepcion', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Cuenta el número de pedidos activos actuales.
     *
     * @return int
     */
    public function contarPedidosActivos(): int
    {
        $resultado = $this->db->table('v_pedidos_activos')
            ->selectCount('idPedido', 'total')
            ->get()
            ->getRowArray();

        return (int) ($resultado['total'] ?? 0);
    }

    // ---------------------------------------------------------------
    // Vista: v_reporte_diario
    // ---------------------------------------------------------------

    /**
     * Obtiene el reporte de ingresos del día desde `v_reporte_diario`.
     * Agrupa por fecha y método de pago (Efectivo, Tarjeta, Yape/Plin).
     *
     * @return array
     */
    public function getReporteDiarioHoy(): array
    {
        return $this->db->table('v_reporte_diario')
            ->where('DATE(fechaPago)', date('Y-m-d'))
            ->get()
            ->getResultArray();
    }

    /**
     * Obtiene el reporte de ingresos de un rango de fechas.
     *
     * @param string $fechaInicio Formato: Y-m-d
     * @param string $fechaFin    Formato: Y-m-d
     * @return array
     */
    public function getReportePorRango(string $fechaInicio, string $fechaFin): array
    {
        return $this->db->table('v_reporte_diario')
            ->where('DATE(fechaPago) >=', $fechaInicio)
            ->where('DATE(fechaPago) <=', $fechaFin)
            ->orderBy('fechaPago', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Obtiene el reporte de un mes específico.
     *
     * @param int $año  Ej: 2026
     * @param int $mes  Ej: 9 (septiembre)
     * @return array
     */
    public function getReporteMensual(int $año, int $mes): array
    {
        return $this->db->table('v_reporte_diario')
            ->where('YEAR(fechaPago)', $año)
            ->where('MONTH(fechaPago)', $mes)
            ->orderBy('fechaPago', 'ASC')
            ->get()
            ->getResultArray();
    }

    // ---------------------------------------------------------------
    // Stored Procedure: sp_cierre_caja
    // ---------------------------------------------------------------

    /**
     * Ejecuta el Stored Procedure `sp_cierre_caja` que devuelve el desglose
     * de ingresos del día usando GROUP BY metodo WITH ROLLUP.
     *
     * La fila con metodo = NULL en el resultado corresponde al TOTAL acumulado
     * generado por el ROLLUP.
     *
     * @param string|null $fecha Fecha para el cierre (Y-m-d). Por defecto: hoy.
     * @return array{
     *   filas: array,
     *   total_general: float,
     *   fecha: string
     * }
     * @throws DatabaseException Si el SP falla por error interno.
     */
    public function ejecutarCierreCaja(?string $fecha = null): array
    {
        $fecha = $fecha ?? date('Y-m-d');

        try {
            $this->db->query('CALL sp_cierre_caja(?)', [$fecha]);
            $resultado = $this->db->getLastQuery();

            // Obtener resultado del SP
            $filas = $this->db->query('CALL sp_cierre_caja(?)', [$fecha])->getResultArray();

            // Separar la fila ROLLUP (total general, donde metodo es NULL)
            $totalGeneral = 0.0;
            $filasDetalle = [];

            foreach ($filas as $fila) {
                if ($fila['metodo'] === null) {
                    $totalGeneral = (float) ($fila['total_ingresos'] ?? $fila['monto'] ?? 0);
                } else {
                    $filasDetalle[] = $fila;
                }
            }

            return [
                'filas'          => $filasDetalle,
                'total_general'  => $totalGeneral,
                'fecha'          => $fecha,
            ];
        } catch (DatabaseException $e) {
            throw $this->envolverExcepcionDB($e, 'ejecutarCierreCaja');
        }
    }

    // ---------------------------------------------------------------
    // Reportes Auxiliares
    // ---------------------------------------------------------------

    /**
     * Devuelve el ranking de servicios más solicitados.
     *
     * @param string $fechaInicio Formato: Y-m-d
     * @param string $fechaFin    Formato: Y-m-d
     * @return array
     */
    public function getServiciosMasSolicitados(string $fechaInicio, string $fechaFin): array
    {
        return $this->db->table('DetallePedido dp')
            ->select('s.nombre AS servicio, SUM(dp.cantidad) AS totalPrendas, SUM(dp.importe) AS totalImporte')
            ->join('ServicioPrenda sp', 'sp.idPrenda = dp.idPrenda', 'inner')
            ->join('Servicio s', 's.idServicio = sp.idServicio', 'inner')
            ->join('Pedido p', 'p.idPedido = dp.idPedido', 'inner')
            ->where('DATE(p.fechaRecepcion) >=', $fechaInicio)
            ->where('DATE(p.fechaRecepcion) <=', $fechaFin)
            ->groupBy('s.idServicio, s.nombre')
            ->orderBy('totalImporte', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Resumen estadístico del día para el dashboard principal.
     *
     * @return array
     */
    public function getResumenDashboard(): array
    {
        $hoy = date('Y-m-d');

        $pedidosHoy = $this->db->table('Pedido')
            ->where('DATE(fechaRecepcion)', $hoy)
            ->countAllResults();

        $ingresosHoy = (float) ($this->db->table('Pago')
            ->selectSum('monto')
            ->where('DATE(fechaPago)', $hoy)
            ->get()
            ->getRowArray()['monto'] ?? 0);

        $pedidosActivos = $this->contarPedidosActivos();

        $pendientesCobro = $this->db->table('Pedido')
            ->whereIn('estado', ['Listo', 'Entregado'])
            ->countAllResults();

        return [
            'pedidos_hoy'       => $pedidosHoy,
            'ingresos_hoy'      => number_format($ingresosHoy, 2),
            'pedidos_activos'   => $pedidosActivos,
            'pendientes_cobro'  => $pendientesCobro,
            'fecha'             => date('d/m/Y'),
        ];
    }
}
