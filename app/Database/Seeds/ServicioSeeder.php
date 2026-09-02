<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * ServicioSeeder
 *
 * Puebla las tablas `Servicio` y `ServicioPrenda` con el catálogo
 * completo de servicios y prendas de MaryClean para pruebas.
 *
 * Todos los precios son > 0 (CHECK CONSTRAINT de la BD).
 *
 * @package App\Database\Seeds
 */
class ServicioSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // Servicios
        // ---------------------------------------------------------------
        $servicios = [
            ['idServicio' => 1, 'nombre' => 'Lavado Simple',      'tiempoEstimado' => 2],
            ['idServicio' => 2, 'nombre' => 'Lavado y Planchado', 'tiempoEstimado' => 4],
            ['idServicio' => 3, 'nombre' => 'Lavado en Seco',     'tiempoEstimado' => 6],
            ['idServicio' => 4, 'nombre' => 'Planchado Especial', 'tiempoEstimado' => 3],
        ];

        $this->db->table('Servicio')->insertBatch($servicios);

        // ---------------------------------------------------------------
        // ServicioPrendas (depende de Servicio)
        // ---------------------------------------------------------------
        $prendas = [
            // Servicio 1: Lavado Simple
            ['idPrenda' => 1,  'nombrePrenda' => 'Camisa',          'precio' => 5.00,  'idServicio' => 1],
            ['idPrenda' => 2,  'nombrePrenda' => 'Pantalón',         'precio' => 6.00,  'idServicio' => 1],
            ['idPrenda' => 3,  'nombrePrenda' => 'Polo',             'precio' => 3.50,  'idServicio' => 1],
            ['idPrenda' => 4,  'nombrePrenda' => 'Ropa Interior',    'precio' => 2.00,  'idServicio' => 1],
            ['idPrenda' => 5,  'nombrePrenda' => 'Calcetines (par)', 'precio' => 1.50,  'idServicio' => 1],
            // Servicio 2: Lavado y Planchado
            ['idPrenda' => 6,  'nombrePrenda' => 'Camisa formal',   'precio' => 9.00,  'idServicio' => 2],
            ['idPrenda' => 7,  'nombrePrenda' => 'Pantalón formal', 'precio' => 10.00, 'idServicio' => 2],
            ['idPrenda' => 8,  'nombrePrenda' => 'Terno (completo)','precio' => 35.00, 'idServicio' => 2],
            ['idPrenda' => 9,  'nombrePrenda' => 'Vestido',         'precio' => 18.00, 'idServicio' => 2],
            // Servicio 3: Lavado en Seco
            ['idPrenda' => 10, 'nombrePrenda' => 'Abrigo',          'precio' => 25.00, 'idServicio' => 3],
            ['idPrenda' => 11, 'nombrePrenda' => 'Casaca cuero',    'precio' => 30.00, 'idServicio' => 3],
            ['idPrenda' => 12, 'nombrePrenda' => 'Corbata',         'precio' => 8.00,  'idServicio' => 3],
            ['idPrenda' => 13, 'nombrePrenda' => 'Saco',            'precio' => 20.00, 'idServicio' => 3],
            // Servicio 4: Planchado Especial
            ['idPrenda' => 14, 'nombrePrenda' => 'Camisa (solo plancha)',   'precio' => 4.00, 'idServicio' => 4],
            ['idPrenda' => 15, 'nombrePrenda' => 'Pantalón (solo plancha)', 'precio' => 5.00, 'idServicio' => 4],
            ['idPrenda' => 16, 'nombrePrenda' => 'Vestido formal (plancha)','precio' => 12.00,'idServicio' => 4],
        ];

        $this->db->table('ServicioPrenda')->insertBatch($prendas);
    }
}
