<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuración de reglas de negocio para pedidos.
 *
 * SC-01:
 * Permite configurar el porcentaje mínimo requerido
 * cuando el cliente decide realizar un pago inicial.
 */
class Pedidos extends BaseConfig
{
    /**
     * Porcentaje mínimo del total que debe cubrir
     * un pago inicial.
     *
     * 0.0  = no existe mínimo.
     * 20.0 = mínimo de 20%.
     */
    public float $porcentajePagoInicialMinimo = 20.0;
}