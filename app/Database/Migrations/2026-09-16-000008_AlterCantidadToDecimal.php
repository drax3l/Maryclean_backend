<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 8 — Alterar cantidad a Decimal para soportar Kilaje
 *
 * Convierte la columna `cantidad` en `DetallePedido` de INT a DECIMAL(8,2).
 * Esto permite registrar lavados por peso, ej. 2.50 Kilos.
 *
 * @package App\Database\Migrations
 */
class Migration_2026_09_16_000008_AlterCantidadToDecimal extends Migration
{
    public function up(): void
    {
        // Alterar tabla para permitir decimales (mantiene la restricción > 0 gracias al CHECK existente)
        $this->db->query("ALTER TABLE `DetallePedido` MODIFY `cantidad` DECIMAL(8,2) NOT NULL");
    }

    public function down(): void
    {
        // Revertir a INT (podría causar pérdida de precisión si hay decimales, pero es estándar para rollback)
        $this->db->query("ALTER TABLE `DetallePedido` MODIFY `cantidad` INT UNSIGNED NOT NULL");
    }
}
