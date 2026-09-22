<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTimestampsToAllTables extends Migration
{
    public function up()
    {
        $tables = [
            'Sucursal', 'Empleado', 'Cliente', 'Servicio', 
            'ServicioPrenda', 'Pedido', 'DetallePedido', 'Pago'
        ];

        foreach ($tables as $table) {
            $this->forge->addColumn($table, [
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                ],
                'updated_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                ],
            ]);
        }
    }

    public function down()
    {
        $tables = [
            'Sucursal', 'Empleado', 'Cliente', 'Servicio', 
            'ServicioPrenda', 'Pedido', 'DetallePedido', 'Pago'
        ];

        foreach ($tables as $table) {
            $this->forge->dropColumn($table, 'created_at');
            $this->forge->dropColumn($table, 'updated_at');
        }
    }
}
