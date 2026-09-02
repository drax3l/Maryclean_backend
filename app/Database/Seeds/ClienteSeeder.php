<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * ClienteSeeder
 *
 * Puebla la tabla `Cliente` con datos de prueba para testing.
 * Incluye clientes con DNI, teléfono y dirección variados.
 *
 * @package App\Database\Seeds
 */
class ClienteSeeder extends Seeder
{
    public function run(): void
    {
        $clientes = [
            [
                'idCliente' => 1,
                'documento' => '12345678',
                'nombres'   => 'Juan Pablo Mendoza García',
                'telefono'  => '987654321',
                'direccion' => 'Jr. Los Pinos 234, Miraflores',
            ],
            [
                'idCliente' => 2,
                'documento' => '87654321',
                'nombres'   => 'Rosa Elena Paredes Vidal',
                'telefono'  => '956123456',
                'direccion' => 'Av. El Sol 567, San Isidro',
            ],
            [
                'idCliente' => 3,
                'documento' => '45678901',
                'nombres'   => 'Pedro Antonio Rojas Huanca',
                'telefono'  => '941789012',
                'direccion' => null,
            ],
            [
                'idCliente' => 4,
                'documento' => '23456789',
                'nombres'   => 'Carmen Rosa Llanos Tapia',
                'telefono'  => null,
                'direccion' => 'Calle Las Dalias 890, Surco',
            ],
            [
                'idCliente' => 5,
                'documento' => '34567890',
                'nombres'   => 'Luis Alberto Quispe Mamani',
                'telefono'  => '912345678',
                'direccion' => 'Av. Universitaria 123, San Miguel',
            ],
        ];

        $this->db->table('Cliente')->insertBatch($clientes);
    }
}
