<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * PedidoSeeder
 *
 * Puebla Pedido, DetallePedido y Pago con datos de prueba coherentes.
 *
 * NOTA: Los totales de Pedido serán recalculados por los triggers
 * `trg_detalle_insertar_total` al insertar los detalles. Se inserta
 * total=0 intencionalmente ya que el trigger lo actualiza.
 *
 * @package App\Database\Seeds
 */
class PedidoSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // Pedidos
        // ---------------------------------------------------------------
        $pedidos = [
            [
                'idPedido'       => 1,
                'codigoTicket'   => 'MC-20260901-TEST1',
                'fechaRecepcion' => '2026-09-01 08:00:00',
                'fechaEntrega'   => null,
                'estado'         => 'Recibido',
                'total'          => 0.00, // Actualizado por trigger
                'idCliente'      => 1,
                'idEmpleado'     => 3,
            ],
            [
                'idPedido'       => 2,
                'codigoTicket'   => 'MC-20260901-TEST2',
                'fechaRecepcion' => '2026-09-01 09:30:00',
                'fechaEntrega'   => null,
                'estado'         => 'En Proceso',
                'total'          => 0.00,
                'idCliente'      => 2,
                'idEmpleado'     => 3,
            ],
            [
                'idPedido'       => 3,
                'codigoTicket'   => 'MC-20260901-TEST3',
                'fechaRecepcion' => '2026-09-01 10:00:00',
                'fechaEntrega'   => null,
                'estado'         => 'Listo',
                'total'          => 0.00,
                'idCliente'      => 3,
                'idEmpleado'     => 4,
            ],
        ];

        $this->db->table('Pedido')->insertBatch($pedidos);

        // ---------------------------------------------------------------
        // Detalles (el trigger actualizará el total en Pedido al insertar)
        // ---------------------------------------------------------------
        $detalles = [
            // Pedido 1: 3 camisas + 2 pantalones (Lavado Simple)
            ['cantidad' => 3, 'descripcion' => 'Camisas blancas', 'importe' => 15.00, 'idPedido' => 1, 'idPrenda' => 1],
            ['cantidad' => 2, 'descripcion' => 'Pantalón oscuro',  'importe' => 12.00, 'idPedido' => 1, 'idPrenda' => 2],
            // Pedido 2: 1 terno completo (Lavado y Planchado)
            ['cantidad' => 1, 'descripcion' => 'Terno azul marino','importe' => 35.00, 'idPedido' => 2, 'idPrenda' => 8],
            ['cantidad' => 2, 'descripcion' => 'Camisas formales', 'importe' => 18.00, 'idPedido' => 2, 'idPrenda' => 6],
            // Pedido 3: 1 abrigo (Lavado en Seco)
            ['cantidad' => 1, 'descripcion' => 'Abrigo beige',     'importe' => 25.00, 'idPedido' => 3, 'idPrenda' => 10],
        ];

        $this->db->table('DetallePedido')->insertBatch($detalles);

        // ---------------------------------------------------------------
        // Pago parcial del Pedido 2 (para probar el trigger de sobrepago)
        // ---------------------------------------------------------------
        $pagos = [
            [
                'monto'     => 20.00,
                'metodo'    => 'Efectivo',
                'fechaPago' => '2026-09-01 14:00:00',
                'idPedido'  => 2,
            ],
        ];

        $this->db->table('Pago')->insertBatch($pagos);
    }
}
