<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PagoModel;
use App\Models\PedidoModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PagosController
 *
 * API REST para el sistema de cobro presencial de MaryClean.
 *
 * Permite:
 * - Registrar pagos parciales.
 * - Registrar el pago total restante.
 * - Consultar historial de pagos.
 * - Consultar total pagado y saldo pendiente.
 * - Obtener el recibo de un pago.
 *
 * MÉTODOS DE PAGO:
 * - Efectivo
 * - Tarjeta
 * - Yape/Plin
 *
 * SEGURIDAD:
 * La validación se realiza en varias capas:
 *
 * 1. Controller:
 *    valida estructura y tipos de datos.
 *
 * 2. PagoModel:
 *    valida saldo pendiente y evita sobrepagos.
 *
 * 3. MySQL:
 *    el trigger vuelve a comprobar el saldo antes del INSERT.
 *
 * ENDPOINTS:
 *
 * POST /api/v1/pagos
 * GET  /api/v1/pagos/pedido/{idPedido}
 * GET  /api/v1/pagos/{idPago}/recibo
 */
class PagosController extends BaseApiController
{
    private PagoModel $pagoModel;
    private PedidoModel $pedidoModel;

    public function __construct()
    {
        $this->pagoModel = new PagoModel();
        $this->pedidoModel = new PedidoModel();
    }


    // ===============================================================
    // POST /api/v1/pagos
    // ===============================================================

    /**
     * Registra un pago o adelanto sobre un pedido existente.
     *
     * Ejemplo:
     *
     * {
     *   "idPedido": 3,
     *   "monto": 2.00,
     *   "metodo": "Efectivo"
     * }
     *
     * Permite múltiples pagos mientras:
     *
     * monto <= saldo pendiente
     *
     * @return ResponseInterface
     */
    public function registrarPago(): ResponseInterface
    {
        // -----------------------------------------------------------
        // Seguridad por rol
        // -----------------------------------------------------------

        if (! $this->tieneRol('cajero', 'admin')) {
            return $this->respondError(
                'No tiene permisos para registrar pagos. Rol requerido: cajero o admin.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }


        // -----------------------------------------------------------
        // Obtener JSON
        // -----------------------------------------------------------

        $body = $this->getJsonBody();

        if (! is_array($body)) {
            return $this->respondError(
                'El cuerpo de la solicitud debe contener un JSON válido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }


        // -----------------------------------------------------------
        // Reglas de validación
        // -----------------------------------------------------------

        $rules = [
            'idPedido' =>
                'required|integer|is_not_unique[Pedido.idPedido]',

            'monto' =>
                'required|decimal|greater_than[0]',

            'metodo' =>
                'required|in_list[Efectivo,Tarjeta,Yape/Plin]',
        ];


        // -----------------------------------------------------------
        // Mensajes en español
        // -----------------------------------------------------------

        $messages = [

            'idPedido' => [
                'required' =>
                    'El pedido es obligatorio.',

                'integer' =>
                    'El identificador del pedido debe ser un número entero válido.',

                'is_not_unique' =>
                    'El pedido especificado no existe.',
            ],


            'monto' => [
                'required' =>
                    'El monto del pago es obligatorio.',

                'decimal' =>
                    'El monto debe ser un número válido.',

                'greater_than' =>
                    'El monto del pago debe ser mayor a S/ 0.00.',
            ],


            'metodo' => [
                'required' =>
                    'El método de pago es obligatorio.',

                'in_list' =>
                    'Seleccione un método de pago válido: Efectivo, Tarjeta o Yape/Plin.',
            ],
        ];


        // -----------------------------------------------------------
        // Ejecutar validación
        // -----------------------------------------------------------

        if (! $this->validateData(
            $body,
            $rules,
            $messages
        )) {
            return $this->respondValidationError(
                $this->validator->getErrors()
            );
        }


        // -----------------------------------------------------------
        // Normalizar datos
        // -----------------------------------------------------------

        $idPedido = (int) $body['idPedido'];

        $monto = round(
            (float) $body['monto'],
            2
        );

        $metodo = trim(
            (string) $body['metodo']
        );


        // -----------------------------------------------------------
        // Consultar pedido
        // -----------------------------------------------------------

        $pedido = $this->pedidoModel
            ->select(
                'idPedido, codigoTicket, total, estado'
            )
            ->find(
                $idPedido
            );


        if ($pedido === null) {
            return $this->respondNotFound(
                "Pedido #{$idPedido} no encontrado."
            );
        }


        // -----------------------------------------------------------
        // Pedido cancelado
        // -----------------------------------------------------------

        if (
            ($pedido['estado'] ?? '')
            === 'Cancelado'
        ) {
            return $this->respondError(
                'No se puede registrar un pago sobre un pedido cancelado.',
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        // -----------------------------------------------------------
        // Pedido marcado como Pagado
        //
        // Esta validación se mantiene mientras el sistema actual
        // utilice "Pagado" dentro de Pedido.estado.
        //
        // Más adelante conviene separar estado operativo y financiero.
        // -----------------------------------------------------------

        if (
            ($pedido['estado'] ?? '')
            === 'Pagado'
        ) {
            return $this->respondError(
                'El pedido ya se encuentra completamente pagado.',
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        // -----------------------------------------------------------
        // Registrar pago
        //
        // PagoModel valida nuevamente:
        // - monto > 0
        // - método
        // - saldo disponible
        // - sobrepago
        //
        // Además el trigger MySQL es la última barrera.
        // -----------------------------------------------------------

        $resultado =
            $this->pagoModel->registrarPago(
                $idPedido,
                $monto,
                $metodo
            );


        // -----------------------------------------------------------
        // Manejar errores provenientes del modelo
        // -----------------------------------------------------------

        if (! $resultado['success']) {

            $codigoError =
                $resultado['codigo_error']
                ?? 'UNKNOWN_ERROR';


            $codigoHttp = match ($codigoError) {

                // Sobrepago
                'SOBREPAGO',
                'SOBREPAGO_TRIGGER_45000' =>
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,


                // Pedido sin saldo restante
                'SIN_SALDO_PENDIENTE' =>
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,


                // Datos inválidos
                'MONTO_INVALIDO',
                'METODO_INVALIDO',
                'VALIDATION_ERROR' =>
                    ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,


                // Pedido inexistente
                'PEDIDO_NO_EXISTE' =>
                    ResponseInterface::HTTP_NOT_FOUND,


                // Error inesperado
                default =>
                    ResponseInterface::HTTP_INTERNAL_SERVER_ERROR,
            };


            return $this->response
                ->setStatusCode(
                    $codigoHttp
                )
                ->setJSON([
                    'success' =>
                        false,

                    'status' =>
                        $codigoHttp,

                    'message' =>
                        $resultado['mensaje'],

                    'data' =>
                        null,

                    'errors' => [
                        'codigo_error' =>
                            $codigoError,
                    ],
                ]);
        }


        // -----------------------------------------------------------
        // Pago registrado correctamente
        // -----------------------------------------------------------

        $idPago =
            (int) $resultado['idPago'];


        // -----------------------------------------------------------
        // Obtener recibo actualizado
        // -----------------------------------------------------------

        $recibo =
            $this->pagoModel
                ->getDatosRecibo(
                    $idPago
                );


        // -----------------------------------------------------------
        // Consultar información financiera actualizada
        // -----------------------------------------------------------

        $totalPagado =
            $this->pagoModel
                ->getTotalPagado(
                    $idPedido
                );


        $saldoPendiente =
            $this->pedidoModel
                ->getSaldoPendiente(
                    $idPedido
                );


        // -----------------------------------------------------------
        // Refrescar pedido después de triggers
        // -----------------------------------------------------------

        $pedidoRefrescado =
            $this->pedidoModel
                ->select(
                    'idPedido, codigoTicket, estado, total'
                )
                ->find(
                    $idPedido
                );


        // -----------------------------------------------------------
        // Estado financiero calculado
        // -----------------------------------------------------------

        $estadoPago =
            $this->calcularEstadoPago(
                (float) (
                    $pedidoRefrescado['total']
                    ?? $pedido['total']
                ),

                $totalPagado
            );


        // -----------------------------------------------------------
        // Respuesta
        // -----------------------------------------------------------

        return $this->respondCreated(
            [
                'idPago' =>
                    $idPago,


                'idPedido' =>
                    $idPedido,


                'codigoTicket' =>
                    $pedidoRefrescado['codigoTicket']
                    ?? $pedido['codigoTicket']
                    ?? null,


                'recibo' =>
                    $recibo,


                // Estado operativo actual
                'pedidoEstado' =>
                    $pedidoRefrescado['estado']
                    ?? 'Desconocido',


                // Estado financiero calculado
                'estadoPago' =>
                    $estadoPago,


                'total' =>
                    round(
                        (float) (
                            $pedidoRefrescado['total']
                            ?? $pedido['total']
                        ),
                        2
                    ),


                'total_pagado' =>
                    round(
                        $totalPagado,
                        2
                    ),


                'saldo_pendiente' =>
                    round(
                        $saldoPendiente,
                        2
                    ),
            ],

            $resultado['mensaje']
        );
    }


    // ===============================================================
    // GET /api/v1/pagos/pedido/{idPedido}
    // ===============================================================

    /**
     * Obtiene todos los pagos realizados sobre un pedido.
     *
     * También devuelve:
     *
     * - total del pedido
     * - total pagado
     * - saldo pendiente
     * - estado operativo
     * - estado financiero
     */
    public function obtenerHistorial(
        $idPedido = null
    ): ResponseInterface {

        // -----------------------------------------------------------
        // Validar ID
        // -----------------------------------------------------------

        if (
            $idPedido === null
            ||
            ! is_numeric(
                $idPedido
            )
        ) {
            return $this->respondError(
                'El identificador del pedido es inválido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }


        $idPedido =
            (int) $idPedido;


        // -----------------------------------------------------------
        // Obtener pedido
        // -----------------------------------------------------------

        $pedido =
            $this->pedidoModel
                ->select(
                    'idPedido, codigoTicket, total, estado'
                )
                ->find(
                    $idPedido
                );


        if ($pedido === null) {
            return $this->respondNotFound(
                "Pedido #{$idPedido} no encontrado."
            );
        }


        // -----------------------------------------------------------
        // Pagos
        // -----------------------------------------------------------

        $pagos =
            $this->pagoModel
                ->getPagosPorPedido(
                    $idPedido
                );


        $totalPagado =
            $this->pagoModel
                ->getTotalPagado(
                    $idPedido
                );


        $saldoPendiente =
            $this->pedidoModel
                ->getSaldoPendiente(
                    $idPedido
                );


        // -----------------------------------------------------------
        // Estado financiero
        // -----------------------------------------------------------

        $estadoPago =
            $this->calcularEstadoPago(
                (float) $pedido['total'],
                $totalPagado
            );


        // -----------------------------------------------------------
        // Respuesta
        // -----------------------------------------------------------

        return $this->respondSuccess([
            'idPedido' =>
                $idPedido,


            'codigoTicket' =>
                $pedido['codigoTicket']
                ?? null,


            'total' =>
                round(
                    (float) $pedido['total'],
                    2
                ),


            'total_pagado' =>
                round(
                    $totalPagado,
                    2
                ),


            'saldo_pendiente' =>
                round(
                    $saldoPendiente,
                    2
                ),


            // Estado físico/operativo
            'estado' =>
                $pedido['estado'],


            // Estado financiero calculado
            'estado_pago' =>
                $estadoPago,


            'pagos' =>
                $pagos,
        ]);
    }


    // ===============================================================
    // GET /api/v1/pagos/{idPago}/recibo
    // ===============================================================

    /**
     * Obtiene el recibo correspondiente a un pago concreto.
     */
    public function obtenerRecibo(
        $idPago = null
    ): ResponseInterface {

        // -----------------------------------------------------------
        // Seguridad
        // -----------------------------------------------------------

        if (! $this->tieneRol(
            'cajero',
            'admin'
        )) {
            return $this->respondError(
                'No tiene permisos para ver recibos.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }


        // -----------------------------------------------------------
        // Validar ID
        // -----------------------------------------------------------

        if (
            $idPago === null
            ||
            ! is_numeric(
                $idPago
            )
        ) {
            return $this->respondError(
                'El identificador del pago es inválido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }


        // -----------------------------------------------------------
        // Obtener recibo
        // -----------------------------------------------------------

        $recibo =
            $this->pagoModel
                ->getDatosRecibo(
                    (int) $idPago
                );


        if ($recibo === null) {
            return $this->respondNotFound(
                "Recibo del pago #{$idPago} no encontrado."
            );
        }


        return $this->respondSuccess(
            $recibo,
            'Recibo de pago obtenido correctamente.'
        );
    }


    // ===============================================================
    // UTILIDADES
    // ===============================================================

    /**
     * Calcula el estado financiero del pedido.
     *
     * Esto permite diferenciar:
     *
     * Estado operativo:
     * Recibido / En Proceso / Listo / Entregado
     *
     * Estado financiero:
     * Pendiente / Parcial / Pagado
     */
    private function calcularEstadoPago(
        float $total,
        float $totalPagado
    ): string {

        $total =
            round(
                $total,
                2
            );

        $totalPagado =
            round(
                $totalPagado,
                2
            );


        if ($totalPagado <= 0) {
            return 'Pendiente';
        }


        if ($totalPagado >= $total) {
            return 'Pagado';
        }


        return 'Parcial';
    }
}