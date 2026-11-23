<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PagoModel;

class PedidoValidatorService
{
    /**
     * Regla de Negocio (TA-03):
     * El identificador de cliente es obligatorio y numérico.
     */
    public function validarRegistroBasico(array $body): void
    {
        if (empty($body['idCliente']) || ! is_numeric($body['idCliente'])) {
            throw new \InvalidArgumentException('El idCliente es obligatorio y debe ser numérico.');
        }
    }

    /**
     * Regla de Negocio (TA-01 / TA-02):
     * Un detalle de pedido es válido SÓLO si tiene un ID de prenda positivo
     * y su cantidad es mayor a 0.
     */
    public function esDetalleValido(array $detalle): bool
    {
        return $this->validarDetalleIndividual(0, $detalle) === [];
    }

    /**
     * Valida la lista completa de prendas del pedido reutilizando
     * una única regla de validación por ítem (elimina duplicación DT-02).
     *
     * @return array<string, string> Mapa de errores o arreglo vacío si es válido.
     */
    public function validarDetalles(mixed $detalles): array
    {
        if (! is_array($detalles) || count($detalles) === 0) {
            return [
                'detalles' => 'Debe incluir al menos una prenda en el pedido.',
            ];
        }

        foreach ($detalles as $i => $detalle) {
            $errorDetalle = $this->validarDetalleIndividual((int) $i, $detalle);
            if ($errorDetalle !== []) {
                return $errorDetalle;
            }
        }

        return [];
    }

    /**
     * Valida y normaliza la estructura del pago inicial opcional.
     *
     * @return array{pagoInicial: ?array{monto: float, metodo: string}, errors: array<string, string>}
     */
    public function validarYNormalizarPagoInicial(array $body): array
    {
        if (! array_key_exists('pagoInicial', $body)) {
            return [
                'pagoInicial' => null,
                'errors'      => [],
            ];
        }

        if (! is_array($body['pagoInicial'])) {
            return [
                'pagoInicial' => null,
                'errors'      => [
                    'pagoInicial' => 'Los datos del pago inicial no son válidos.',
                ],
            ];
        }

        $montoPagoInicial  = $body['pagoInicial']['monto'] ?? null;
        $metodoPagoInicial = trim((string) ($body['pagoInicial']['metodo'] ?? ''));

        if (! is_numeric($montoPagoInicial) || (float) $montoPagoInicial <= 0) {
            return [
                'pagoInicial' => null,
                'errors'      => [
                    'pagoInicial.monto' => 'El monto del adelanto debe ser mayor a S/ 0.00.',
                ],
            ];
        }

        if (! in_array($metodoPagoInicial, PagoModel::METODOS_PAGO, true)) {
            return [
                'pagoInicial' => null,
                'errors'      => [
                    'pagoInicial.metodo' => 'Seleccione un método de pago válido: Efectivo, Tarjeta o Yape/Plin.',
                ],
            ];
        }

        return [
            'pagoInicial' => [
                'monto'  => round((float) $montoPagoInicial, 2),
                'metodo' => $metodoPagoInicial,
            ],
            'errors' => [],
        ];
    }

    /**
     * Valida un detalle individual y retorna el error indexado si incumple.
     *
     * @return array<string, string>
     */
    private function validarDetalleIndividual(int $indice, mixed $detalle): array
    {
        if (
            ! is_array($detalle)
            || ! isset($detalle['idPrenda'])
            || ! is_numeric($detalle['idPrenda'])
            || (int) $detalle['idPrenda'] <= 0
        ) {
            return [
                "detalles[{$indice}].idPrenda" => 'La prenda es obligatoria y debe ser válida.',
            ];
        }

        if (
            ! isset($detalle['cantidad'])
            || ! is_numeric($detalle['cantidad'])
            || (float) $detalle['cantidad'] <= 0
        ) {
            return [
                "detalles[{$indice}].cantidad" => 'La cantidad debe ser mayor a 0.',
            ];
        }

        return [];
    }
}