<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 2/4 — Vistas SQL
 *
 * Crea las dos vistas que abstraen consultas complejas y optimizan
 * el acceso a datos desde los modelos de CI4.
 *
 * VISTAS CREADAS:
 * - `v_pedidos_activos`: Pedidos no finalizados con datos de cliente y empleado.
 * - `v_reporte_diario`: Ingresos agrupados por fecha y método de pago.
 *
 * @package App\Database\Migrations
 */
class Migration_2026_09_01_000002_CreateLavanderiaViews extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // VISTA: v_pedidos_activos
        // ---------------------------------------------------------------
        // Muestra pedidos con estado distinto a 'Entregado' o 'Cancelado'.
        // Incluye datos del cliente y del empleado responsable.
        // Usada en PedidoModel::getPedidosActivos() y ReporteModel.
        $this->db->query('DROP VIEW IF EXISTS `v_pedidos_activos`');

        $this->db->query("
            CREATE VIEW `v_pedidos_activos` AS
            SELECT
                p.idPedido,
                p.codigoTicket,
                p.fechaRecepcion,
                p.fechaEntrega,
                p.estado,
                p.total,
                c.idCliente,
                c.documento      AS documentoCliente,
                c.nombres        AS cliente,
                c.telefono       AS telefonoCliente,
                e.idEmpleado,
                e.nombres        AS empleado,
                e.rol            AS rolEmpleado,
                s.idSucursal,
                s.nombre         AS sucursal
            FROM Pedido p
            INNER JOIN Cliente  c ON c.idCliente  = p.idCliente
            INNER JOIN Empleado e ON e.idEmpleado  = p.idEmpleado
            INNER JOIN Sucursal s ON s.idSucursal  = e.idSucursal
            WHERE p.estado NOT IN ('Entregado', 'Cancelado')
        ");

        // ---------------------------------------------------------------
        // VISTA: v_reporte_diario
        // ---------------------------------------------------------------
        // Agrupa los ingresos de la tabla Pago por fecha y método de pago.
        // Usada en ReporteModel::getReporteDiarioHoy() y getReportePorRango().
        $this->db->query('DROP VIEW IF EXISTS `v_reporte_diario`');

        $this->db->query("
            CREATE VIEW `v_reporte_diario` AS
            SELECT
                DATE(fechaPago)           AS fechaPago,
                metodo,
                SUM(monto)                AS totalIngresos,
                COUNT(idPago)             AS cantidadTransacciones,
                MIN(monto)                AS montoMinimo,
                MAX(monto)                AS montoMaximo,
                AVG(monto)                AS montoPromedio
            FROM Pago
            GROUP BY DATE(fechaPago), metodo
            ORDER BY DATE(fechaPago) DESC, metodo ASC
        ");
    }

    public function down(): void
    {
        $this->db->query('DROP VIEW IF EXISTS `v_reporte_diario`');
        $this->db->query('DROP VIEW IF EXISTS `v_pedidos_activos`');
    }
}
