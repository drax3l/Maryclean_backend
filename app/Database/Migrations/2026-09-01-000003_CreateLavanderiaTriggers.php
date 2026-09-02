<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 3/4 — Triggers Automatizados
 *
 * Crea los 7 triggers que encapsulan la lógica de negocio crítica
 * directamente en el motor MySQL.
 *
 * NOTA IMPORTANTE: Los triggers usan DELIMITER $$ en SQL nativo, pero
 * como CI4 ejecuta sentencias individuales, cada trigger se crea con
 * una llamada separada a $this->db->query().
 *
 * @package App\Database\Migrations
 */
class Migration_2026_09_01_000003_CreateLavanderiaTriggers extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // TRIGGER 1: trg_detalle_insertar_total
        // Acción: AFTER INSERT en DetallePedido
        // Recalcula el campo `total` en Pedido sumando todos sus detalles.
        // ---------------------------------------------------------------
        $this->db->query('DROP TRIGGER IF EXISTS `trg_detalle_insertar_total`');

        $this->db->query('
            CREATE TRIGGER `trg_detalle_insertar_total`
            AFTER INSERT ON `DetallePedido`
            FOR EACH ROW
            BEGIN
                UPDATE `Pedido`
                   SET `total` = (
                       SELECT COALESCE(SUM(`importe`), 0)
                         FROM `DetallePedido`
                        WHERE `idPedido` = NEW.idPedido
                   )
                 WHERE `idPedido` = NEW.idPedido;
            END
        ');

        // ---------------------------------------------------------------
        // TRIGGER 2: trg_detalle_actualizar_total
        // Acción: AFTER UPDATE en DetallePedido
        // Recalcula el total cuando se modifica cantidad o importe de una línea.
        // ---------------------------------------------------------------
        $this->db->query('DROP TRIGGER IF EXISTS `trg_detalle_actualizar_total`');

        $this->db->query('
            CREATE TRIGGER `trg_detalle_actualizar_total`
            AFTER UPDATE ON `DetallePedido`
            FOR EACH ROW
            BEGIN
                UPDATE `Pedido`
                   SET `total` = (
                       SELECT COALESCE(SUM(`importe`), 0)
                         FROM `DetallePedido`
                        WHERE `idPedido` = NEW.idPedido
                   )
                 WHERE `idPedido` = NEW.idPedido;
            END
        ');

        // ---------------------------------------------------------------
        // TRIGGER 3: trg_detalle_eliminar_total
        // Acción: AFTER DELETE en DetallePedido
        // Recalcula el total cuando se elimina una línea del detalle.
        // ---------------------------------------------------------------
        $this->db->query('DROP TRIGGER IF EXISTS `trg_detalle_eliminar_total`');

        $this->db->query('
            CREATE TRIGGER `trg_detalle_eliminar_total`
            AFTER DELETE ON `DetallePedido`
            FOR EACH ROW
            BEGIN
                UPDATE `Pedido`
                   SET `total` = (
                       SELECT COALESCE(SUM(`importe`), 0)
                         FROM `DetallePedido`
                        WHERE `idPedido` = OLD.idPedido
                   )
                 WHERE `idPedido` = OLD.idPedido;
            END
        ');

        // ---------------------------------------------------------------
        // TRIGGER 4: trg_pedido_antes_actualizar
        // Acción: BEFORE UPDATE en Pedido
        // Asigna fechaEntrega automáticamente cuando el estado cambia a \'Entregado\'.
        // ---------------------------------------------------------------
        $this->db->query('DROP TRIGGER IF EXISTS `trg_pedido_antes_actualizar`');

        $this->db->query("
            CREATE TRIGGER `trg_pedido_antes_actualizar`
            BEFORE UPDATE ON `Pedido`
            FOR EACH ROW
            BEGIN
                IF NEW.estado = 'Entregado' AND OLD.estado != 'Entregado' THEN
                    SET NEW.fechaEntrega = NOW();
                END IF;
            END
        ");

        // ---------------------------------------------------------------
        // TRIGGER 5: trg_pedido_despues_actualizar_auditoria
        // Acción: AFTER UPDATE en Pedido
        // Registra en AuditoriaEstado cada cambio de estado del pedido.
        // Esta es la ÚNICA fuente de escritura en AuditoriaEstado.
        // ---------------------------------------------------------------
        $this->db->query('DROP TRIGGER IF EXISTS `trg_pedido_despues_actualizar_auditoria`');

        $this->db->query("
            CREATE TRIGGER `trg_pedido_despues_actualizar_auditoria`
            AFTER UPDATE ON `Pedido`
            FOR EACH ROW
            BEGIN
                IF NEW.estado != OLD.estado THEN
                    INSERT INTO `AuditoriaEstado`
                        (`idPedido`, `estadoAnterior`, `estadoNuevo`, `fechaCambio`)
                    VALUES
                        (NEW.idPedido, OLD.estado, NEW.estado, NOW());
                END IF;
            END
        ");

        // ---------------------------------------------------------------
        // TRIGGER 6: trg_pago_antes_insertar_validar
        // Acción: BEFORE INSERT en Pago
        // Valida que el monto no supere el saldo pendiente del pedido.
        // Lanza SQLSTATE \'45000\' (error de aplicación definido por usuario) si excede.
        // ---------------------------------------------------------------
        $this->db->query('DROP TRIGGER IF EXISTS `trg_pago_antes_insertar_validar`');

        $this->db->query("
            CREATE TRIGGER `trg_pago_antes_insertar_validar`
            BEFORE INSERT ON `Pago`
            FOR EACH ROW
            BEGIN
                DECLARE v_total         DECIMAL(10,2) DEFAULT 0;
                DECLARE v_totalPagado   DECIMAL(10,2) DEFAULT 0;
                DECLARE v_saldo         DECIMAL(10,2) DEFAULT 0;

                SELECT `total`
                  INTO v_total
                  FROM `Pedido`
                 WHERE `idPedido` = NEW.idPedido;

                SELECT COALESCE(SUM(`monto`), 0)
                  INTO v_totalPagado
                  FROM `Pago`
                 WHERE `idPedido` = NEW.idPedido;

                SET v_saldo = v_total - v_totalPagado;

                IF NEW.monto > v_saldo THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = CONCAT(
                            'ERROR: El monto ingresado (S/ ',
                            ROUND(NEW.monto, 2),
                            ') excede el saldo pendiente del pedido (S/ ',
                            ROUND(v_saldo, 2),
                            '). Operacion de sobrepago rechazada.'
                        );
                END IF;
            END
        ");

        // ---------------------------------------------------------------
        // TRIGGER 7: trg_pago_despues_insertar_estado
        // Acción: AFTER INSERT en Pago
        // Si la suma acumulada de pagos cubre el total del pedido, cambia a \'Pagado\'.
        // ---------------------------------------------------------------
        $this->db->query('DROP TRIGGER IF EXISTS `trg_pago_despues_insertar_estado`');

        $this->db->query("
            CREATE TRIGGER `trg_pago_despues_insertar_estado`
            AFTER INSERT ON `Pago`
            FOR EACH ROW
            BEGIN
                DECLARE v_total       DECIMAL(10,2) DEFAULT 0;
                DECLARE v_totalPagado DECIMAL(10,2) DEFAULT 0;

                SELECT `total`
                  INTO v_total
                  FROM `Pedido`
                 WHERE `idPedido` = NEW.idPedido;

                SELECT COALESCE(SUM(`monto`), 0)
                  INTO v_totalPagado
                  FROM `Pago`
                 WHERE `idPedido` = NEW.idPedido;

                IF v_totalPagado >= v_total AND v_total > 0 THEN
                    UPDATE `Pedido`
                       SET `estado` = 'Pagado'
                     WHERE `idPedido` = NEW.idPedido;
                END IF;
            END
        ");
    }

    public function down(): void
    {
        $triggers = [
            'trg_pago_despues_insertar_estado',
            'trg_pago_antes_insertar_validar',
            'trg_pedido_despues_actualizar_auditoria',
            'trg_pedido_antes_actualizar',
            'trg_detalle_eliminar_total',
            'trg_detalle_actualizar_total',
            'trg_detalle_insertar_total',
        ];

        foreach ($triggers as $trigger) {
            $this->db->query("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
    }
}
