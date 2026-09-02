<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * SucursalSeeder — Actualizado con credenciales de autenticación JWT
 *
 * Pobla las tablas `Sucursal` y `Empleado` con datos de prueba,
 * incluyendo username y password hasheados con bcrypt (cost=12)
 * para el sistema de autenticación JWT.
 *
 * CREDENCIALES DE PRUEBA (solo para lavanderia_test):
 *   admin        | usuario: admin001  | password: Admin123!
 *   cajero       | usuario: cajero001 | password: Cajero123!
 *   recepcionista| usuario: recep001  | password: Recep123!
 *
 * ⚠️ NUNCA usar estas credenciales en producción.
 *
 * @package App\Database\Seeds
 */
class SucursalSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // Sucursales
        // ---------------------------------------------------------------
        $sucursales = [
            [
                'idSucursal' => 1,
                'nombre'     => 'MaryClean Centro',
                'direccion'  => 'Av. Principal 123, Centro Histórico',
                'telefono'   => '01-4501234',
            ],
            [
                'idSucursal' => 2,
                'nombre'     => 'MaryClean San Borja',
                'direccion'  => 'Calle Las Flores 456, San Borja',
                'telefono'   => '01-4765432',
            ],
        ];

        foreach ($sucursales as $sucursal) {
            $this->db->table('Sucursal')->replace($sucursal);
        }

        // ---------------------------------------------------------------
        // Empleados con credenciales JWT (passwords hasheados con bcrypt)
        // ---------------------------------------------------------------
        $empleados = [
            [
                'idEmpleado' => 1,
                'nombres'    => 'María Torres Quispe',
                'username'   => 'admin001',
                'password'   => password_hash('Admin123!', PASSWORD_BCRYPT, ['cost' => 12]),
                'activo'     => 1,
                'rol'        => 'admin',
                'idSucursal' => 1,
            ],
            [
                'idEmpleado' => 2,
                'nombres'    => 'Carlos Mamani López',
                'username'   => 'cajero001',
                'password'   => password_hash('Cajero123!', PASSWORD_BCRYPT, ['cost' => 12]),
                'activo'     => 1,
                'rol'        => 'cajero',
                'idSucursal' => 1,
            ],
            [
                'idEmpleado' => 3,
                'nombres'    => 'Ana Flores Ramos',
                'username'   => 'recep001',
                'password'   => password_hash('Recep123!', PASSWORD_BCRYPT, ['cost' => 12]),
                'activo'     => 1,
                'rol'        => 'recepcionista',
                'idSucursal' => 1,
            ],
            [
                'idEmpleado' => 4,
                'nombres'    => 'Roberto Silva Chávez',
                'username'   => 'recep002',
                'password'   => password_hash('Recep123!', PASSWORD_BCRYPT, ['cost' => 12]),
                'activo'     => 1,
                'rol'        => 'recepcionista',
                'idSucursal' => 2,
            ],
            [
                'idEmpleado' => 5,
                'nombres'    => 'Lucía Castro Vargas',
                'username'   => 'cajero002',
                'password'   => password_hash('Cajero123!', PASSWORD_BCRYPT, ['cost' => 12]),
                'activo'     => 1,
                'rol'        => 'cajero',
                'idSucursal' => 2,
            ],
        ];

        foreach ($empleados as $empleado) {
            $this->db->table('Empleado')->replace($empleado);
        }
    }
}
