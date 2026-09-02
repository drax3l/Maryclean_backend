<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 5/5 — Agregar campo password a la tabla Empleado
 *
 * REQUISITO: El sistema de autenticación JWT necesita validar credenciales.
 * Se agrega el campo `password` (hash bcrypt) y `username` (identificador único)
 * a la tabla `Empleado` para soportar el login de empleados en la API REST.
 *
 * También se agrega `activo` (tinyint) para poder desactivar empleados sin
 * eliminarlos (integridad referencial con Pedido).
 *
 * @package App\Database\Migrations
 */
class Migration_2026_09_01_000005_AddAuthToEmpleado extends Migration
{
    public function up(): void
    {
        // Agregar campos de autenticación
        $this->db->query("
            ALTER TABLE `Empleado`
                ADD COLUMN `username`  VARCHAR(50)  NULL UNIQUE AFTER `nombres`,
                ADD COLUMN `password`  VARCHAR(255) NULL         AFTER `username`,
                ADD COLUMN `activo`    TINYINT(1)   NOT NULL DEFAULT 1 AFTER `password`,
                ADD INDEX `idx_empleado_username` (`username`)
        ");

        // Poblar usernames iniciales basados en el ID usando Query Builder (seguro y multiplataforma)
        $empleados = $this->db->table('Empleado')->where('username', null)->get()->getResult();
        foreach ($empleados as $emp) {
            $username = 'emp' . str_pad((string)$emp->idEmpleado, 4, '0', STR_PAD_LEFT);
            $this->db->table('Empleado')
                     ->where('idEmpleado', $emp->idEmpleado)
                     ->update(['username' => $username]);
        }
    }

    public function down(): void
    {
        $this->db->query("
            ALTER TABLE `Empleado`
                DROP INDEX  `idx_empleado_username`,
                DROP COLUMN `username`,
                DROP COLUMN `password`,
                DROP COLUMN `activo`
        ");
    }
}
