<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PedidoModel;
use App\Models\DetallePedidoModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * PedidosController
 *
 * API REST para el ciclo de vida completo de los pedidos de MaryClean.
 * Orquesta la recepción de pedidos, gestión de detalles y cambios de estado.
 *
 * ENDPOINTS:
 *   GET   /api/v1/pedidos                      → index()          [jwt]
 *   POST  /api/v1/pedidos                      → crearRecepcion() [jwt: recepcionista, cajero, admin]
 *   GET   /api/v1/pedidos/{id}/ticket          → obtenerTicket()  [jwt]
 *   PATCH /api/v1/pedidos/{id}/estado          → cambiarEstado()  [jwt]
 *   GET   /api/v1/pedidos/{id}                 → show()           [jwt]
 *
 * TRIGGERS IMPLICADOS (transparentes para el controlador):
 *   crearRecepcion()  → trg_detalle_insertar_total (recalcula total)
 *   cambiarEstado()   → trg_pedido_antes_actualizar (fechaEntrega si Entregado)
 *                     → trg_pedido_despues_actualizar_auditoria (registra cambio)
 *
 * THIN CONTROLLER:
 * No contiene SQL. Delega toda la lógica a PedidoModel y DetallePedidoModel.
 *
 * @package App\Controllers\Api
 */
class PedidosController extends BaseApiController
{
    private PedidoModel       $pedidoModel;
    private DetallePedidoModel $detalleModel;

    public function __construct()
    {
        $this->pedidoModel  = new PedidoModel();
        $this->detalleModel = new DetallePedidoModel();
    }

    // ---------------------------------------------------------------
    // GET /api/v1/pedidos?estado=<estado>&sucursal=<id>
    // ---------------------------------------------------------------

    /**
     * Lista los pedidos activos desde la vista `v_pedidos_activos`.
     * La vista excluye automáticamente los estados 'Entregado' y 'Cancelado'.
     *
     * QUERY PARAMS:
     *   estado   → Filtrar por estado específico (opcional)
     *   sucursal → Filtrar por idSucursal (opcional, admin puede ver todas)
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "pedidos": [ { idPedido, codigoTicket, cliente, estado, total, ... } ],
     *     "total": 12
     *   }
     * }
     *
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $payload    = $this->getAuthPayload();
        $estado     = $this->request->getGet('estado');
        $idSucursal = $this->request->getGet('sucursal');

        // No-admins solo ven su sucursal
        if (! $this->tieneRol('admin') && empty($idSucursal)) {
            $idSucursal = $payload['sucursal'] ?? null;
        }

        if ($estado !== null) {
            $pedidos = $this->pedidoModel->getPedidosActivosPorEstado($estado);
        } elseif ($idSucursal !== null) {
            $pedidos = $this->pedidoModel->getPedidosActivosPorSucursal((int) $idSucursal);
        } else {
            $pedidos = $this->pedidoModel->getPedidosActivos();
        }

        return $this->respondSuccess([
            'pedidos' => $pedidos,
            'total'   => count($pedidos),
        ]);
    }

    // ---------------------------------------------------------------
    // GET /api/v1/pedidos/{id}
    // ---------------------------------------------------------------

    /**
     * Obtiene un pedido completo con cliente, empleado, detalles y pagos.
     *
     * @param int|string|null $id
     * @return ResponseInterface
     */
    public function show($id = null): ResponseInterface
    {
        try {
            $pedido = $this->pedidoModel->getPedidoCompleto((int) $id);

            if ($pedido === null) {
                return $this->respondNotFound("Pedido #{$id} no encontrado.");
            }

            return $this->respondSuccess($pedido);
        } catch (DatabaseException $e) {
            return $this->handleDbException($e);
        }
    }

    // ---------------------------------------------------------------
    // POST /api/v1/pedidos
    // ---------------------------------------------------------------

    /**
     * Registra la recepción de un nuevo pedido ejecutando el SP `sp_registrar_recepcion`
     * e insertando los detalles de prendas.
     *
     * REQUEST (JSON):
     * {
     *   "idCliente":  1,
     *   "detalles": [
     *     { "idPrenda": 1, "cantidad": 3, "descripcion": "Camisas blancas" },
     *     { "idPrenda": 2, "cantidad": 2, "descripcion": "Pantalón oscuro"  }
     *   ]
     * }
     *
     * RESPONSE 201:
     * {
     *   "success": true,
     *   "message": "Pedido registrado exitosamente.",
     *   "data": {
     *     "idPedido": 10,
     *     "codigoTicket": "MC-20260901-A3F7B",
     *     "ticket": { ... estructura completa del ticket ... }
     *   }
     * }
     *
     * TRIGGERS DISPARADOS (automáticamente por MySQL):
     *   - trg_detalle_insertar_total: Actualiza Pedido.total al insertar cada prenda.
     *
     * @return ResponseInterface
     */
    public function crearRecepcion(): ResponseInterface
    {
        // Validar estructura base del request
        $rules = [
            'idCliente' => 'required|integer|is_not_unique[Cliente.idCliente]',
            'detalles'  => 'required',
        ];

        $body = $this->getJsonBody();

        if (! $this->validateData($body, $rules)) {
            return $this->respondValidationError($this->validator->getErrors());
        }

        $detalles = $body['detalles'] ?? [];

        if (empty($detalles) || ! is_array($detalles)) {
            return $this->respondValidationError(
                ['detalles' => 'Debe incluir al menos una prenda en el pedido.']
            );
        }

        // Validar estructura de cada detalle
        foreach ($detalles as $i => $detalle) {
            if (empty($detalle['idPrenda']) || empty($detalle['cantidad']) || $detalle['cantidad'] < 1) {
                return $this->respondValidationError([
                    "detalles[{$i}]" => 'Cada prenda debe tener idPrenda y cantidad (>0) válidos.',
                ]);
            }
        }

        try {
            // Obtener el empleado autenticado desde el JWT
            $payload    = $this->getAuthPayload();
            $idEmpleado = (int) ($payload['id'] ?? 0);

            if ($idEmpleado === 0) {
                return $this->respondError('No se pudo identificar al empleado autenticado.', 401);
            }

            // Generar código de ticket único
            $codigoTicket   = $this->pedidoModel->generarCodigoTicket();
            $fechaRecepcion = date('Y-m-d H:i:s');

            // Ejecutar SP (transacción nativa MySQL)
            $idPedido = $this->pedidoModel->registrarRecepcion(
                $codigoTicket,
                $fechaRecepcion,
                (int) $body['idCliente'],
                $idEmpleado
            );

            // Insertar detalles (triggers actualizan Pedido.total automáticamente)
            $erroresDetalle = [];
            foreach ($detalles as $i => $detalle) {
                $resultado = $this->detalleModel->insertarDetalle(
                    $idPedido,
                    (int) $detalle['idPrenda'],
                    (int) $detalle['cantidad'],
                    $detalle['descripcion'] ?? ''
                );

                if ($resultado === false) {
                    $erroresDetalle[] = "Prenda #{$detalle['idPrenda']} (línea {$i}): no se pudo insertar (precio inválido o prenda inexistente).";
                }
            }

            // Si hubo errores en detalles, informar pero el pedido fue creado
            if (! empty($erroresDetalle)) {
                return $this->respondSuccess(
                    [
                        'idPedido'     => $idPedido,
                        'codigoTicket' => $codigoTicket,
                        'advertencias' => $erroresDetalle,
                    ],
                    'Pedido creado con advertencias en algunos detalles.',
                    ResponseInterface::HTTP_CREATED
                );
            }

            // Obtener datos completos del ticket para la respuesta
            $ticket = $this->pedidoModel->getDatosTicket($idPedido);

            return $this->respondCreated(
                [
                    'idPedido'     => $idPedido,
                    'codigoTicket' => $codigoTicket,
                    'ticket'       => $ticket,
                ],
                'Pedido registrado exitosamente.'
            );
        } catch (DatabaseException $e) {
            return $this->handleDbException($e);
        } catch (\RuntimeException $e) {
            return $this->respondError($e->getMessage(), ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ---------------------------------------------------------------
    // GET /api/v1/pedidos/{id}/ticket
    // ---------------------------------------------------------------

    /**
     * Retorna la estructura JSON completa del ticket para impresión física
     * o renderizado en pantalla desde la App Móvil / Frontend Web.
     *
     * La estructura incluye todos los datos necesarios para imprimir un
     * ticket de lavandería: encabezado, cliente, prendas, total y saldo.
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "encabezado": { "empresa": "LAVANDERÍA MARYCLEAN", "ticket": "MC-...", ... },
     *     "cliente":    { "documento": "...", "nombres": "...", "telefono": "..." },
     *     "empleado":   { "nombres": "...", "rol": "..." },
     *     "detalles":   [ { "servicio": "...", "prenda": "...", "cantidad": 2, ... } ],
     *     "total":           "27.00",
     *     "total_pagado":    "0.00",
     *     "saldo_pendiente": "27.00",
     *     "estado":          "Recibido"
     *   }
     * }
     *
     * @param int|string|null $id
     * @return ResponseInterface
     */
    public function obtenerTicket($id = null): ResponseInterface
    {
        try {
            $ticket = $this->pedidoModel->getDatosTicket((int) $id);
            return $this->respondSuccess($ticket, 'Ticket generado exitosamente.');
        } catch (\RuntimeException $e) {
            return $this->respondNotFound($e->getMessage());
        } catch (DatabaseException $e) {
            return $this->handleDbException($e);
        }
    }

    // ---------------------------------------------------------------
    // PATCH /api/v1/pedidos/{id}/estado
    // ---------------------------------------------------------------

    /**
     * Cambia el estado de un pedido.
     *
     * TRIGGERS DISPARADOS AUTOMÁTICAMENTE (MySQL):
     *   - Si nuevoEstado = 'Entregado' → trg_pedido_antes_actualizar asigna fechaEntrega
     *   - Siempre → trg_pedido_despues_actualizar_auditoria registra el cambio en AuditoriaEstado
     *
     * REQUEST (JSON):
     * { "estado": "En Proceso" }
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "message": "Estado actualizado a 'En Proceso'.",
     *   "data": { "idPedido": 1, "estado": "En Proceso", "fechaEntrega": null }
     * }
     *
     * @param int|string|null $id
     * @return ResponseInterface
     */
    public function cambiarEstado($id = null): ResponseInterface
    {
        $body        = $this->getJsonBody();
        $nuevoEstado = trim($body['estado'] ?? '');

        if (empty($nuevoEstado)) {
            return $this->respondValidationError(['estado' => 'El nuevo estado es requerido.']);
        }

        $pedido = $this->pedidoModel->find((int) $id);

        if ($pedido === null) {
            return $this->respondNotFound("Pedido #{$id} no encontrado.");
        }

        // Restricción de rol: solo admin puede cancelar
        if ($nuevoEstado === 'Cancelado' && ! $this->tieneRol('admin')) {
            return $this->respondError(
                'Solo el administrador puede cancelar pedidos.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        try {
            $this->pedidoModel->cambiarEstado((int) $id, $nuevoEstado);

            // Refrescar el pedido tras el update (trigger pudo modificar fechaEntrega)
            $pedidoActualizado = $this->pedidoModel->select('idPedido, estado, fechaEntrega, total')->find((int) $id);

            return $this->respondSuccess(
                $pedidoActualizado,
                "Estado del pedido #{$id} actualizado a '{$nuevoEstado}'."
            );
        } catch (\InvalidArgumentException $e) {
            return $this->respondValidationError(['estado' => $e->getMessage()]);
        } catch (DatabaseException $e) {
            return $this->handleDbException($e);
        }
    }
}
