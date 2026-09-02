<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 4/4 — Stored Procedures
 *
 * Crea los 2 procedimientos almacenados que encapsulan operaciones
 * transaccionales y de reporting en el motor MySQL.
 *
 * STORED PROCEDURES:
 * - `sp_registrar_recepcion`: Inserta el encabezado del pedido en una
 *   transacción nativa y devuelve el ID generado (parámetro OUT).
 * - `sp_cierre_caja`: Calcula el desglose de ingresos del día usando
 *   GROUP BY metodo WITH ROLLUP para incluir el total general.
 *
 * @package App\Database\Migrations
 */
class Migration_2026_09_01_000004_CreateLavanderiaProcedures extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // SP: sp_registrar_recepcion
        // Inicia una transacción MySQL nativa, inserta el encabezado del
        // pedido y devuelve el ID generado en el parámetro OUT.
        // Invocado en PedidoModel::registrarRecepcion().
        // ---------------------------------------------------------------
        $this->db->query('DROP PROCEDURE IF EXISTS `sp_registrar_recepcion`');

        $this->db->query("
            CREATE PROCEDURE `sp_registrar_recepcion`(
                IN  p_codigoTicket   VARCHAR(20),
                IN  p_fechaRecepcion DATETIME,
                IN  p_idCliente      INT UNSIGNED,
                IN  p_idEmpleado     INT UNSIGNED,
                OUT p_idPedidoGenerado INT UNSIGNED
            )
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    ROLLBACK;
                    RESIGNAL;
                END;

                START TRANSACTION;

                    INSERT INTO `Pedido`
                        (`codigoTicket`, `fechaRecepcion`, `estado`, `total`, `idCliente`, `idEmpleado`)
                    VALUES
                        (p_codigoTicket, p_fechaRecepcion, 'Recibido', 0.00, p_idCliente, p_idEmpleado);

                    SET p_idPedidoGenerado = LAST_INSERT_ID();

                COMMIT;
            END
        ");

        // ---------------------------------------------------------------
        // SP: sp_cierre_caja
        // Calcula el desglose de ingresos para una fecha dada, agrupados
        // por método de pago con ROLLUP para incluir el total general.
        // La fila con metodo = NULL es el total acumulado (ROLLUP).
        // Invocado en ReporteModel::ejecutarCierreCaja().
        // ---------------------------------------------------------------
        $this->db->query('DROP PROCEDURE IF EXISTS `sp_cierre_caja`');

        $this->db->query("
            CREATE PROCEDURE `sp_cierre_caja`(
                IN p_fecha DATE
            )
            BEGIN
                SELECT
                    metodo,
                    COUNT(idPago)       AS cantidadTransacciones,
                    SUM(monto)          AS total_ingresos,
                    MIN(monto)          AS montoMinimo,
                    MAX(monto)          AS montoMaximo
                FROM `Pago`
                WHERE DATE(fechaPago) = p_fecha
                GROUP BY metodo WITH ROLLUP;
            END
        ");
    }

    public function down(): void
    {
        $this->db->query('DROP PROCEDURE IF EXISTS `sp_cierre_caja`');
        $this->db->query('DROP PROCEDURE IF EXISTS `sp_registrar_recepcion`');
    }
}
