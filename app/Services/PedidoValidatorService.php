<?php

namespace App\Services;

class PedidoValidatorService
{
    /**
     * Regla de Negocio: 
     * Un detalle de pedido es válido SÓLO si su cantidad es mayor a 0 
     * y tiene un ID de prenda válido.
     */
    public function validarRegistroBasico(array $body): void
    {
        if (empty($body['idCliente']) || !is_numeric($body['idCliente'])) {
            throw new \InvalidArgumentException('El idCliente es obligatorio y debe ser numérico.');
        }
    }

    public function esDetalleValido(array $detalle): bool
    {
        if (!isset($detalle['idPrenda']) || !is_numeric($detalle['idPrenda'])) {
            return false;
        }
        
        if (!isset($detalle['cantidad']) || !is_numeric($detalle['cantidad']) || (float)$detalle['cantidad'] <= 0) {
            return false;
        }

        return true;
    }
}
