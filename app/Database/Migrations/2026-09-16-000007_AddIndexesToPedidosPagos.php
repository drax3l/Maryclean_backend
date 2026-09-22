<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 7 — Añadir Índices de Rendimiento (Performance)
 *
 * Añade índices estratégicos a las tablas más consultadas para
 * garantizar que los reportes (ej. buscar entre dos fechas) 
 * se ejecuten en milisegundos incluso con millones de registros.
 *
 * @package App\Database\Migrations
 */
class Migration_2026_09_16_000007_AddIndexesToPedidosPagos extends Migration
{
    public function up(): void
    {
        // Índices para fechas en Pedido
        $this->db->query("ALTER TABLE `Pedido` ADD INDEX `idx_pedido_fechaRecepcion` (`fechaRecepcion`)");
        $this->db->query("ALTER TABLE `Pedido` ADD INDEX `idx_pedido_fechaEntrega` (`fechaEntrega`)");
        
        // Índices para fechas en Pago
        $this->db->query("ALTER TABLE `Pago` ADD INDEX `idx_pago_fechaPago` (`fechaPago`)");
        
        // Índices para búsquedas de cliente
        $this->db->query("ALTER TABLE `Cliente` ADD INDEX `idx_cliente_nombres` (`nombres`)");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE `Pedido` DROP INDEX `idx_pedido_fechaRecepcion`");
        $this->db->query("ALTER TABLE `Pedido` DROP INDEX `idx_pedido_fechaEntrega`");
        $this->db->query("ALTER TABLE `Pago` DROP INDEX `idx_pago_fechaPago`");
        $this->db->query("ALTER TABLE `Cliente` DROP INDEX `idx_cliente_nombres`");
    }
}
