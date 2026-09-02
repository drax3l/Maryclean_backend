<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * LavanderiaSeeder — Seeder Maestro
 *
 * Orquesta la ejecución de todos los seeders en el orden correcto
 * respetando las dependencias de claves foráneas.
 *
 * USO:
 *   php spark db:seed LavanderiaSeeder
 *
 * ENTORNO DE PRUEBAS:
 *   Apuntar a `lavanderia_test` mediante la variable de entorno:
 *   php spark db:seed LavanderiaSeeder --env testing
 *
 * @package App\Database\Seeds
 */
class LavanderiaSeeder extends Seeder
{
    public function run(): void
    {
        // Deshabilitar temporalmente las restricciones FK para inserción limpia
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        // Limpiar tablas en orden inverso a FKs
        $tablasLimpiar = [
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

        foreach ($tablasLimpiar as $tabla) {
            $this->db->query("TRUNCATE TABLE `{$tabla}`");
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        // Ejecutar seeders en orden de dependencia
        $this->call(SucursalSeeder::class);
        $this->call(ClienteSeeder::class);
        $this->call(ServicioSeeder::class);
        $this->call(PedidoSeeder::class);
    }
}
