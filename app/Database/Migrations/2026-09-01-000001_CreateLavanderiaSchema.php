<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 1/4 — Esquema Base de la BD `lavanderia`
 *
 * Crea las 9 tablas con sus claves primarias, foráneas, índices y
 * CHECK CONSTRAINTS que garantizan integridad referencial y de negocio.
 *
 * ORDEN DE CREACIÓN (dependencias FK):
 * Sucursal → Empleado → Cliente → Servicio → ServicioPrenda
 * → Pedido → DetallePedido → Pago → AuditoriaEstado
 *
 * NOTA: Esta migración aplica tanto a la BD de desarrollo (`lavanderia`)
 * como al entorno de pruebas (`lavanderia_test`).
 *
 * @package App\Database\Migrations
 */
class Migration_2026_09_01_000001_CreateLavanderiaSchema extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // TABLA: Sucursal
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `Sucursal` (
                `idSucursal`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nombre`      VARCHAR(100) NOT NULL,
                `direccion`   VARCHAR(255) NOT NULL,
                `telefono`    VARCHAR(15)  NOT NULL,
                PRIMARY KEY (`idSucursal`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Puntos físicos de operación de MaryClean';
        ");

        // ---------------------------------------------------------------
        // TABLA: Empleado
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `Empleado` (
                `idEmpleado`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nombres`     VARCHAR(150) NOT NULL,
                `rol`         ENUM('admin','cajero','recepcionista') NOT NULL,
                `idSucursal`  INT UNSIGNED NOT NULL,
                PRIMARY KEY (`idEmpleado`),
                CONSTRAINT `fk_empleado_sucursal`
                    FOREIGN KEY (`idSucursal`) REFERENCES `Sucursal` (`idSucursal`)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Personal de MaryClean con rol RBAC';
        ");

        // ---------------------------------------------------------------
        // TABLA: Cliente
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `Cliente` (
                `idCliente`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `documento`   VARCHAR(15)  NOT NULL,
                `nombres`     VARCHAR(200) NOT NULL,
                `telefono`    VARCHAR(15)  NULL,
                `direccion`   VARCHAR(255) NULL,
                PRIMARY KEY (`idCliente`),
                UNIQUE KEY `uq_cliente_documento` (`documento`),
                INDEX `idx_cliente_telefono` (`telefono`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Clientes de la lavandería';
        ");

        // ---------------------------------------------------------------
        // TABLA: Servicio
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `Servicio` (
                `idServicio`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nombre`         VARCHAR(100) NOT NULL,
                `tiempoEstimado` INT UNSIGNED NOT NULL COMMENT 'Tiempo en horas',
                PRIMARY KEY (`idServicio`),
                CONSTRAINT `chk_servicio_tiempo` CHECK (`tiempoEstimado` > 0)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Tipos de servicio ofrecidos (lavado, planchado, etc.)';
        ");

        // ---------------------------------------------------------------
        // TABLA: ServicioPrenda
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `ServicioPrenda` (
                `idPrenda`    INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                `nombrePrenda` VARCHAR(100)  NOT NULL,
                `precio`      DECIMAL(10,2)  NOT NULL,
                `idServicio`  INT UNSIGNED   NOT NULL,
                PRIMARY KEY (`idPrenda`),
                CONSTRAINT `fk_prenda_servicio`
                    FOREIGN KEY (`idServicio`) REFERENCES `Servicio` (`idServicio`)
                    ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `chk_prenda_precio` CHECK (`precio` > 0)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Catálogo de prendas con precio por tipo de servicio';
        ");

        // ---------------------------------------------------------------
        // TABLA: Pedido
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `Pedido` (
                `idPedido`       INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                `codigoTicket`   VARCHAR(20)    NOT NULL,
                `fechaRecepcion` DATETIME       NOT NULL,
                `fechaEntrega`   DATETIME       NULL COMMENT 'Asignado por trigger al pasar a Entregado',
                `estado`         ENUM('Recibido','En Proceso','Listo','Entregado','Pagado','Cancelado')
                                 NOT NULL DEFAULT 'Recibido',
                `total`          DECIMAL(10,2)  NOT NULL DEFAULT 0.00 COMMENT 'Calculado por triggers de DetallePedido',
                `idCliente`      INT UNSIGNED   NOT NULL,
                `idEmpleado`     INT UNSIGNED   NOT NULL,
                PRIMARY KEY (`idPedido`),
                UNIQUE KEY `uq_pedido_ticket` (`codigoTicket`),
                INDEX `idx_pedido_fecha`  (`fechaRecepcion`),
                INDEX `idx_pedido_estado` (`estado`),
                CONSTRAINT `fk_pedido_cliente`
                    FOREIGN KEY (`idCliente`) REFERENCES `Cliente` (`idCliente`)
                    ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `fk_pedido_empleado`
                    FOREIGN KEY (`idEmpleado`) REFERENCES `Empleado` (`idEmpleado`)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Pedidos de lavandería. Total calculado por triggers.';
        ");

        // ---------------------------------------------------------------
        // TABLA: DetallePedido
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `DetallePedido` (
                `idDetalle`   INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                `cantidad`    INT UNSIGNED   NOT NULL,
                `descripcion` VARCHAR(255)   NULL,
                `importe`     DECIMAL(10,2)  NOT NULL,
                `idPedido`    INT UNSIGNED   NOT NULL,
                `idPrenda`    INT UNSIGNED   NOT NULL,
                PRIMARY KEY (`idDetalle`),
                CONSTRAINT `fk_detalle_pedido`
                    FOREIGN KEY (`idPedido`) REFERENCES `Pedido` (`idPedido`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_detalle_prenda`
                    FOREIGN KEY (`idPrenda`) REFERENCES `ServicioPrenda` (`idPrenda`)
                    ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `chk_detalle_cantidad` CHECK (`cantidad` > 0),
                CONSTRAINT `chk_detalle_importe`  CHECK (`importe` > 0)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Líneas de detalle de cada pedido';
        ");

        // ---------------------------------------------------------------
        // TABLA: Pago
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `Pago` (
                `idPago`      INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                `monto`       DECIMAL(10,2)  NOT NULL,
                `metodo`      ENUM('Efectivo','Tarjeta','Yape/Plin') NOT NULL,
                `fechaPago`   DATETIME       NOT NULL,
                `idPedido`    INT UNSIGNED   NOT NULL,
                PRIMARY KEY (`idPago`),
                INDEX `idx_pago_fecha` (`fechaPago`),
                CONSTRAINT `fk_pago_pedido`
                    FOREIGN KEY (`idPedido`) REFERENCES `Pedido` (`idPedido`)
                    ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `chk_pago_monto` CHECK (`monto` > 0)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Pagos presenciales en mostrador. Validados por trigger.';
        ");

        // ---------------------------------------------------------------
        // TABLA: AuditoriaEstado
        // ---------------------------------------------------------------
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `AuditoriaEstado` (
                `idAuditoria`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `idPedido`      INT UNSIGNED NOT NULL,
                `estadoAnterior` ENUM('Recibido','En Proceso','Listo','Entregado','Pagado','Cancelado') NOT NULL,
                `estadoNuevo`    ENUM('Recibido','En Proceso','Listo','Entregado','Pagado','Cancelado') NOT NULL,
                `fechaCambio`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`idAuditoria`),
                CONSTRAINT `fk_auditoria_pedido`
                    FOREIGN KEY (`idPedido`) REFERENCES `Pedido` (`idPedido`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Registro inmutable de cambios de estado. Solo escrito por trigger.';
        ");
    }

    public function down(): void
    {
        // Eliminar en orden inverso (respetando FKs)
        $tablas = [
            'AuditoriaEstado',
            'Pago',
            'DetallePedido',
            'Pedido',
            'ServicioPrenda',
            'Servicio',
            'Cliente',
            'Empleado',
            'Sucursal',
        ];

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tablas as $tabla) {
            $this->db->query("DROP TABLE IF EXISTS `{$tabla}`");
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
