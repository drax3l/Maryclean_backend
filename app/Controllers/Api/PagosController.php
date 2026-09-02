<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PagoModel;
use App\Models\PedidoModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * PagosController
 *
 * API REST para el sistema de cobro presencial de MaryClean.
 * Gestiona el registro de pagos en mostrador y la consulta del historial.
 *
 * COBRO EXCLUSIVAMENTE PRESENCIAL:
 * Los métodos soportados son los que se procesan en el mostrador físico:
 *   - Efectivo
 *   - Tarjeta (POS físico)
 *   - Yape/Plin (verificación manual del recepcionista)
 *
 * NO se integran pasarelas de pago externas ni APIs de cobro online.
 *
 * ENDPOINTS:
 *   POST /api/v1/pagos                           → registrarPago()    [jwt: cajero, admin]
 *   GET  /api/v1/pagos/pedido/{idPedido}         → obtenerHistorial() [jwt]
 *   GET  /api/v1/pagos/{idPago}/recibo           → obtenerRecibo()    [jwt: cajero, admin]
 *
 * TRIGGER IMPLICADO:
 *   registrarPago() → trg_pago_antes_insertar_validar: Si monto > saldo,
 *                     lanza SQLSTATE '45000'. PagoModel lo captura y devuelve
 *                     array{success: false, codigo_error: SOBREPAGO_TRIGGER_45000}.
 *                  → trg_pago_despues_insertar_estado: Si suma de pagos >= total,
 *                     cambia Pedido.estado = 'Pagado' automáticamente.
 *
 * THIN CONTROLLER:
 * La lógica de validación de sobrepago está en PagoModel::registrarPago().
 * Este controlador solo mapea el resultado del modelo a HTTP responses.
 *
 * @package App\Controllers\Api
 */
class PagosController extends BaseApiController
{
    private PagoModel   $pagoModel;
    private PedidoModel $pedidoModel;

    public function __construct()
    {
        $this->pagoModel   = new PagoModel();
        $this->pedidoModel = new PedidoModel();
    }

    // ---------------------------------------------------------------
    // POST /api/v1/pagos
    // ---------------------------------------------------------------

    /**
     * Registra un pago presencial en mostrador.
     * Captura el error SQLSTATE '45000' del trigger de validación de saldo
     * y lo convierte en una respuesta HTTP 422 estructurada.
     *
     * ACCESO: Roles 'cajero' y 'admin' únicamente.
     *
     * REQUEST (JSON):
     * {
     *   "idPedido": 2,
     *   "monto":    33.00,
     *   "metodo":   "Efectivo"
     * }
     *
     * RESPONSE 201 (éxito):
     * {
     *   "success": true,
     *   "message": "Pago registrado correctamente.",
     *   "data": {
     *     "idPago":       5,
     *     "recibo":       { ... datos del recibo para imprimir ... },
     *     "pedidoEstado": "Pagado"
     *   }
     * }
     *
     * RESPONSE 422 (sobrepago — SQLSTATE 45000):
     * {
     *   "success": false,
     *   "status":  422,
     *   "message": "El monto (S/ 50.00) supera el saldo pendiente (S/ 33.00).",
     *   "errors":  { "codigo_error": "SOBREPAGO_TRIGGER_45000" }
     * }
     *
     * @return ResponseInterface
     */
    public function registrarPago(): ResponseInterface
    {
        // Verificar rol (solo cajero y admin pueden cobrar)
        if (! $this->tieneRol('cajero', 'admin')) {
            return $this->respondError(
                'No tiene permisos para registrar pagos. Rol requerido: cajero o admin.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        // Validar campos del request
        $rules = [
            'idPedido' => 'required|integer|is_not_unique[Pedido.idPedido]',
            'monto'    => 'required|decimal|greater_than[0]',
            'metodo'   => 'required|in_list[Efectivo,Tarjeta,Yape/Plin]',
        ];

        $body = $this->getJsonBody();

        if (! $this->validateData($body, $rules)) {
            return $this->respondValidationError($this->validator->getErrors());
        }

        $idPedido = (int) $body['idPedido'];
        $monto    = (float) $body['monto'];
        $metodo   = $body['metodo'];

        // Verificar que el pedido no esté ya Pagado o Cancelado
        $pedido = $this->pedidoModel->find($idPedido);

        if (in_array($pedido['estado'] ?? '', ['Pagado', 'Cancelado'], true)) {
            return $this->respondError(
                "No se puede cobrar un pedido con estado '{$pedido['estado']}'.",
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }

        // Delegar el registro al modelo (que captura SQLSTATE 45000 internamente)
        $resultado = $this->pagoModel->registrarPago($idPedido, $monto, $metodo);

        // Mapear el resultado del modelo a la respuesta HTTP correcta
        if (! $resultado['success']) {
            $codigoHttp = match ($resultado['codigo_error']) {
                'SOBREPAGO_TRIGGER_45000' => ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, // 422
                'VALIDATION_ERROR'        => ResponseInterface::HTTP_BAD_REQUEST,           // 400
                default                   => ResponseInterface::HTTP_INTERNAL_SERVER_ERROR,  // 500
            };

            return $this->response
                ->setStatusCode($codigoHttp)
                ->setJSON([
                    'success' => false,
                    'status'  => $codigoHttp,
                    'message' => $resultado['mensaje'],
                    'data'    => null,
                    'errors'  => ['codigo_error' => $resultado['codigo_error']],
                ]);
        }

        // Pago exitoso: obtener recibo y estado actualizado del pedido
        $idPago          = $resultado['idPago'];
        $recibo          = $this->pagoModel->getDatosRecibo($idPago);
        $pedidoRefrescado = $this->pedidoModel->select('idPedido, estado, total')->find($idPedido);

        return $this->respondCreated(
            [
                'idPago'       => $idPago,
                'recibo'       => $recibo,
                'pedidoEstado' => $pedidoRefrescado['estado'] ?? 'Desconocido',
            ],
            $resultado['mensaje']
        );
    }

    // ---------------------------------------------------------------
    // GET /api/v1/pagos/pedido/{idPedido}
    // ---------------------------------------------------------------

    /**
     * Retorna el historial de pagos de un pedido y el saldo pendiente.
     * Útil para mostrar en la app cuánto se ha abonado y cuánto falta.
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "idPedido":        2,
     *     "total":           53.00,
     *     "total_pagado":    20.00,
     *     "saldo_pendiente": 33.00,
     *     "estado":          "En Proceso",
     *     "pagos": [
     *       { "idPago": 1, "monto": 20.00, "metodo": "Efectivo", "fechaPago": "..." }
     *     ]
     *   }
     * }
     *
     * @param int|string|null $idPedido
     * @return ResponseInterface
     */
    public function obtenerHistorial($idPedido = null): ResponseInterface
    {
        $pedido = $this->pedidoModel->select('idPedido, total, estado')->find((int) $idPedido);

        if ($pedido === null) {
            return $this->respondNotFound("Pedido #{$idPedido} no encontrado.");
        }

        $pagos          = $this->pagoModel->getPagosPorPedido((int) $idPedido);
        $totalPagado    = $this->pagoModel->getTotalPagado((int) $idPedido);
        $saldoPendiente = $this->pedidoModel->getSaldoPendiente((int) $idPedido);

        return $this->respondSuccess([
            'idPedido'        => (int) $pedido['idPedido'],
            'total'           => (float) $pedido['total'],
            'total_pagado'    => $totalPagado,
            'saldo_pendiente' => $saldoPendiente,
            'estado'          => $pedido['estado'],
            'pagos'           => $pagos,
        ]);
    }

    // ---------------------------------------------------------------
    // GET /api/v1/pagos/{idPago}/recibo
    // ---------------------------------------------------------------

    /**
     * Devuelve la estructura del recibo de un pago específico.
     * Usado para re-imprimir un comprobante de cobro desde la caja.
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "empresa": "LAVANDERÍA MARYCLEAN",
     *     "recibo_n": "00000005",
     *     "fecha_pago": "01/09/2026 14:30:00",
     *     "ticket": "MC-20260901-A3F7B",
     *     "cliente": "JUAN PABLO MENDOZA GARCÍA",
     *     "documento": "12345678",
     *     "monto_abonado": "33.00",
     *     "metodo": "Efectivo",
     *     "total_pedido": "53.00",
     *     "estado_pedido": "Pagado"
     *   }
     * }
     *
     * @param int|string|null $idPago
     * @return ResponseInterface
     */
    public function obtenerRecibo($idPago = null): ResponseInterface
    {
        // Solo cajero y admin pueden acceder a recibos
        if (! $this->tieneRol('cajero', 'admin')) {
            return $this->respondError('No tiene permisos para ver recibos.', ResponseInterface::HTTP_FORBIDDEN);
        }

        $recibo = $this->pagoModel->getDatosRecibo((int) $idPago);

        if ($recibo === null) {
            return $this->respondNotFound("Recibo del pago #{$idPago} no encontrado.");
        }

        return $this->respondSuccess($recibo, 'Recibo de pago obtenido.');
    }
}
