<?php

namespace App\Services;

use App\Models\PedidoModel;

class PedidoProcesadorService
{
    private PedidoModel $pedidoModel;

    public function __construct(PedidoModel $pedidoModel)
    {
        $this->pedidoModel = $pedidoModel;
    }

    public function procesarRegistro(string $codigo, int $idCliente, int $idSucursal): int
    {
        // Delega la responsabilidad de base de datos al Modelo real o al Mock
        return $this->pedidoModel->registrarRecepcion($codigo, date('Y-m-d H:i:s'), $idCliente, $idSucursal);
    }
}
