<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PedidoModel;
use App\Services\PedidoProcesadorService;
use App\Services\PedidoValidatorService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PedidosController
 *
 * API REST para el ciclo de vida de los pedidos de MaryClean.
 *
 * ENDPOINTS:
 *
 * GET   /api/v1/pedidos
 * POST  /api/v1/pedidos
 * GET   /api/v1/pedidos/{id}
 * GET   /api/v1/pedidos/{id}/ticket
 * PATCH /api/v1/pedidos/{id}/estado
 */
class PedidosController extends BaseApiController
{
    private PedidoModel $pedidoModel;
    private PedidoValidatorService $validatorService;
    private PedidoProcesadorService $procesadorService;

    public function __construct()
    {
        $this->pedidoModel       = new PedidoModel();
        $this->validatorService  = new PedidoValidatorService();
        $this->procesadorService = new PedidoProcesadorService($this->pedidoModel);
    }

    // ===============================================================
    // GET /api/v1/pedidos
    // ===============================================================

    /**
     * Lista pedidos aplicando filtros dinámicos.
     */
    public function index(): ResponseInterface
    {
        $payload = $this->getAuthPayload();

        $filtros = [
            'estado'     => $this->request->getGet('estado'),
            'sucursal'   => $this->request->getGet('sucursal'),
            'cliente_id' => $this->request->getGet('cliente_id'),
            'search'     => $this->request->getGet('search'),
            'page'       => $this->request->getGet('page') ?? 1,
        ];

        // Usuarios que no son admin solo pueden consultar pedidos de su propia sucursal.
        if (! $this->tieneRol('admin') && empty($filtros['sucursal'])) {
            $filtros['sucursal'] = $payload['sucursal'] ?? null;
        }

        $perPage = (int) ($this->request->getGet('per_page') ?? 15);
        $perPage = ($perPage > 0 && $perPage <= 100) ? $perPage : 15;

        $resultado = $this->pedidoModel->getPedidosDinamicos($filtros, $perPage);

        return $this->respondSuccess([
            'pedidos'  => $resultado['pedidos'],
            'total'    => $resultado['total'],
            'page'     => $resultado['page'],
            'per_page' => $resultado['per_page'],
        ]);
    }

    // ===============================================================
    // GET /api/v1/pedidos/{id}
    // ===============================================================

    /**
     * Obtiene el pedido completo.
     */
    public function show($id = null): ResponseInterface
    {
        if ($id === null || ! is_numeric($id)) {
            return $this->respondError(
                'El identificador del pedido es inválido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }

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

    // ===============================================================
    // POST /api/v1/pedidos
    // ===============================================================

    /**
     * Registra un nuevo pedido delegando validaciones y procesamiento transaccional.
     */
    public function crearRecepcion(): ResponseInterface
    {
        if (! $this->tieneRol('recepcionista', 'cajero', 'admin')) {
            return $this->respondError(
                'No tiene permisos para registrar pedidos.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        $body = $this->getJsonBody();

        $rules = [
            'idCliente' => 'required|integer|is_not_unique[Cliente.idCliente]',
            'detalles'  => 'required',
        ];

        $messages = [
            'idCliente' => [
                'required'      => 'El cliente es obligatorio.',
                'integer'       => 'El identificador del cliente debe ser válido.',
                'is_not_unique' => 'El cliente especificado no existe.',
            ],
            'detalles' => [
                'required' => 'Debe incluir al menos una prenda en el pedido.',
            ],
        ];

        if (! $this->validateData($body, $rules, $messages)) {
            return $this->respondValidationError($this->validator->getErrors());
        }

        $erroresDetalles = $this->validatorService->validarDetalles($body['detalles'] ?? []);
        if ($erroresDetalles !== []) {
            return $this->respondValidationError($erroresDetalles);
        }

        $validacionPago = $this->validatorService->validarYNormalizarPagoInicial($body);
        if ($validacionPago['errors'] !== []) {
            return $this->respondValidationError($validacionPago['errors']);
        }

        $payload    = $this->getAuthPayload();
        $idEmpleado = (int) ($payload['id'] ?? 0);

        if ($idEmpleado <= 0) {
            return $this->respondError(
                'No se pudo identificar al empleado autenticado.',
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        try {
            $resultado = $this->procesadorService->registrarPedidoCompleto(
                (int) $body['idCliente'],
                $idEmpleado,
                $body['detalles'],
                $validacionPago['pagoInicial']
            );

            if (! $resultado['success']) {
                return $this->respondError(
                    $resultado['message'],
                    $resultado['status'],
                    $resultado['errors']
                );
            }

            return $this->respondCreated($resultado['data'], $resultado['message']);
        } catch (DatabaseException $e) {
            return $this->handleDbException($e);
        } catch (\RuntimeException $e) {
            return $this->respondError(
                $e->getMessage(),
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    // ===============================================================
    // GET /api/v1/pedidos/{id}/ticket
    // ===============================================================

    /**
     * Obtiene el ticket completo del pedido.
     */
    public function obtenerTicket($id = null): ResponseInterface
    {
        if ($id === null || ! is_numeric($id)) {
            return $this->respondError(
                'El identificador del pedido es inválido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }

        try {
            $ticket = $this->pedidoModel->getDatosTicket((int) $id);

            return $this->respondSuccess($ticket, 'Ticket generado exitosamente.');
        } catch (\RuntimeException $e) {
            return $this->respondNotFound($e->getMessage());
        } catch (DatabaseException $e) {
            return $this->handleDbException($e);
        }
    }

    // ===============================================================
    // PATCH /api/v1/pedidos/{id}/estado
    // ===============================================================

    /**
     * Actualiza el estado operativo del pedido.
     */
    public function cambiarEstado($id = null): ResponseInterface
    {
        if (
            $id === null
            || ! is_numeric($id)
        ) {
            return $this->respondError(
                'El identificador del pedido es inválido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }

        $body = $this->getJsonBody();

        if (! is_array($body)) {
            return $this->respondError(
                'El cuerpo de la solicitud debe contener un JSON válido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }

        $nuevoEstado = trim(
            (string) (
                $body['estado']
                ?? ''
            )
        );

        if ($nuevoEstado === '') {
            return $this->respondValidationError([
                'estado' => 'El nuevo estado es requerido.',
            ]);
        }

        $pedido = $this->pedidoModel->find((int) $id);

        if ($pedido === null) {
            return $this->respondNotFound("Pedido #{$id} no encontrado.");
        }

        // Solo admin puede cancelar
        if (
            $nuevoEstado === 'Cancelado'
            && ! $this->tieneRol('admin')
        ) {
            return $this->respondError(
                'Solo el administrador puede cancelar pedidos.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        try {
            $this->pedidoModel->cambiarEstado((int) $id, $nuevoEstado);

            // Refrescar después del trigger
            $pedidoActualizado = $this->pedidoModel
                ->select('idPedido, codigoTicket, estado, fechaEntrega, total')
                ->find((int) $id);

            return $this->respondSuccess(
                $pedidoActualizado,
                "Estado del pedido #{$id} actualizado a '{$nuevoEstado}'."
            );
        } catch (\InvalidArgumentException $e) {
            return $this->respondValidationError([
                'estado' => $e->getMessage(),
            ]);
        } catch (DatabaseException $e) {
            return $this->handleDbException($e);
        }
    }
}