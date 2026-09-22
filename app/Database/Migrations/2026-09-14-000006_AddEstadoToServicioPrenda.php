<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 6 — Añadir campo estado a la tabla ServicioPrenda
 *
 * @package App\Database\Migrations
 */
class Migration_2026_09_14_000006_AddEstadoToServicioPrenda extends Migration
{
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE `ServicioPrenda`
                ADD COLUMN `estado` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0: Inactivo, 1: Activo' AFTER `idServicio`
        ");
    }

    public function down(): void
    {
        $this->db->query("
            ALTER TABLE `ServicioPrenda`
                DROP COLUMN `estado`
        ");
    }
}
