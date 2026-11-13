<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PedidoModel;
use App\Models\DetallePedidoModel;
use App\Models\PagoModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;

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
 *
 * El registro de un pedido puede incluir opcionalmente un adelanto.
 *
 * Si ocurre un error durante:
 * - inserción de prendas;
 * - validación del pago inicial;
 * - sobrepago;
 *
 * el pedido recién creado es revertido para evitar registros
 * incompletos o inconsistentes.
 */
class PedidosController extends BaseApiController
{
    private PedidoModel $pedidoModel;
    private DetallePedidoModel $detalleModel;
    private PagoModel $pagoModel;

    public function __construct()
    {
        $this->pedidoModel = new PedidoModel();
        $this->detalleModel = new DetallePedidoModel();
        $this->pagoModel = new PagoModel();
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
            'estado' =>
                $this->request->getGet('estado'),

            'sucursal' =>
                $this->request->getGet('sucursal'),

            'cliente_id' =>
                $this->request->getGet('cliente_id'),

            'search' =>
                $this->request->getGet('search'),

            'page' =>
                $this->request->getGet('page') ?? 1,
        ];


        // Usuarios que no son admin solo pueden consultar
        // pedidos de su propia sucursal.
        if (
            ! $this->tieneRol('admin')
            && empty($filtros['sucursal'])
        ) {
            $filtros['sucursal'] =
                $payload['sucursal']
                ?? null;
        }


        $perPage = (int) (
            $this->request->getGet('per_page')
            ?? 15
        );


        // Evitar consultas excesivamente grandes.
        $perPage =
            $perPage > 0
            && $perPage <= 100
                ? $perPage
                : 15;


        $resultado =
            $this->pedidoModel
                ->getPedidosDinamicos(
                    $filtros,
                    $perPage
                );


        return $this->respondSuccess([
            'pedidos' =>
                $resultado['pedidos'],

            'total' =>
                $resultado['total'],

            'page' =>
                $resultado['page'],

            'per_page' =>
                $resultado['per_page'],
        ]);
    }


    // ===============================================================
    // GET /api/v1/pedidos/{id}
    // ===============================================================

    /**
     * Obtiene el pedido completo.
     */
    public function show(
        $id = null
    ): ResponseInterface {
        if (
            $id === null
            || ! is_numeric($id)
        ) {
            return $this->respondError(
                'El identificador del pedido es inválido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }


        try {
            $pedido =
                $this->pedidoModel
                    ->getPedidoCompleto(
                        (int) $id
                    );


            if ($pedido === null) {
                return $this->respondNotFound(
                    "Pedido #{$id} no encontrado."
                );
            }


            return $this->respondSuccess(
                $pedido
            );

        } catch (DatabaseException $e) {
            return $this->handleDbException(
                $e
            );
        }
    }


    // ===============================================================
    // POST /api/v1/pedidos
    // ===============================================================

    /**
     * Registra un nuevo pedido.
     *
     * Puede incluir opcionalmente:
     *
     * "pagoInicial": {
     *     "monto": 5.00,
     *     "metodo": "Efectivo"
     * }
     *
     * Si el adelanto supera el total del pedido,
     * el pedido completo será revertido.
     */
    public function crearRecepcion(): ResponseInterface
    {
        // -----------------------------------------------------------
        // 1. Seguridad
        // -----------------------------------------------------------

        if (
            ! $this->tieneRol(
                'recepcionista',
                'cajero',
                'admin'
            )
        ) {
            return $this->respondError(
                'No tiene permisos para registrar pedidos.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }


        // -----------------------------------------------------------
        // 2. Obtener JSON
        // -----------------------------------------------------------

        $body =
            $this->getJsonBody();


        if (! is_array($body)) {
            return $this->respondError(
                'El cuerpo de la solicitud debe contener un JSON válido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }


        // -----------------------------------------------------------
        // 3. Validaciones generales
        // -----------------------------------------------------------

        $rules = [
            'idCliente' =>
                'required|integer|is_not_unique[Cliente.idCliente]',

            'detalles' =>
                'required',
        ];


        $messages = [
            'idCliente' => [
                'required' =>
                    'El cliente es obligatorio.',

                'integer' =>
                    'El identificador del cliente debe ser válido.',

                'is_not_unique' =>
                    'El cliente especificado no existe.',
            ],

            'detalles' => [
                'required' =>
                    'Debe incluir al menos una prenda en el pedido.',
            ],
        ];


        if (
            ! $this->validateData(
                $body,
                $rules,
                $messages
            )
        ) {
            return $this->respondValidationError(
                $this->validator->getErrors()
            );
        }


        // -----------------------------------------------------------
        // 4. Validar detalles
        // -----------------------------------------------------------

        $detalles =
            $body['detalles']
            ?? [];


        if (
            ! is_array($detalles)
            || count($detalles) === 0
        ) {
            return $this->respondValidationError([
                'detalles' =>
                    'Debe incluir al menos una prenda en el pedido.',
            ]);
        }


        foreach (
            $detalles as $i => $detalle
        ) {
            if (
                ! isset($detalle['idPrenda'])
                || ! is_numeric(
                    $detalle['idPrenda']
                )
                || (int) $detalle['idPrenda'] <= 0
            ) {
                return $this->respondValidationError([
                    "detalles[{$i}].idPrenda" =>
                        'La prenda es obligatoria y debe ser válida.',
                ]);
            }


            if (
                ! isset($detalle['cantidad'])
                || ! is_numeric(
                    $detalle['cantidad']
                )
                || (float) $detalle['cantidad'] <= 0
            ) {
                return $this->respondValidationError([
                    "detalles[{$i}].cantidad" =>
                        'La cantidad debe ser mayor a 0.',
                ]);
            }
        }


        // -----------------------------------------------------------
        // 5. Validar estructura del pago inicial
        //
        // Esta validación ocurre ANTES de crear el pedido.
        // Todavía no podemos validar sobrepago porque Pedido.total
        // se calcula al insertar las prendas.
        // -----------------------------------------------------------

        $pagoInicial = null;


        if (
            array_key_exists(
                'pagoInicial',
                $body
            )
        ) {
            if (
                ! is_array(
                    $body['pagoInicial']
                )
            ) {
                return $this->respondValidationError([
                    'pagoInicial' =>
                        'Los datos del pago inicial no son válidos.',
                ]);
            }


            $montoPagoInicial =
                $body['pagoInicial']['monto']
                ?? null;


            $metodoPagoInicial =
                trim(
                    (string) (
                        $body['pagoInicial']['metodo']
                        ?? ''
                    )
                );


            if (
                ! is_numeric(
                    $montoPagoInicial
                )
                || (float) $montoPagoInicial <= 0
            ) {
                return $this->respondValidationError([
                    'pagoInicial.monto' =>
                        'El monto del adelanto debe ser mayor a S/ 0.00.',
                ]);
            }


            if (
                ! in_array(
                    $metodoPagoInicial,
                    PagoModel::METODOS_PAGO,
                    true
                )
            ) {
                return $this->respondValidationError([
                    'pagoInicial.metodo' =>
                        'Seleccione un método de pago válido: Efectivo, Tarjeta o Yape/Plin.',
                ]);
            }


            $pagoInicial = [
                'monto' =>
                    round(
                        (float) $montoPagoInicial,
                        2
                    ),

                'metodo' =>
                    $metodoPagoInicial,
            ];
        }


        // -----------------------------------------------------------
        // 6. Registrar pedido
        // -----------------------------------------------------------

        try {
            // -------------------------------------------------------
            // Empleado autenticado
            // -------------------------------------------------------

            $payload =
                $this->getAuthPayload();


            $idEmpleado =
                (int) (
                    $payload['id']
                    ?? 0
                );


            if ($idEmpleado <= 0) {
                return $this->respondError(
                    'No se pudo identificar al empleado autenticado.',
                    ResponseInterface::HTTP_UNAUTHORIZED
                );
            }


            // -------------------------------------------------------
            // Generar ticket
            // -------------------------------------------------------

            $codigoTicket =
                $this->pedidoModel
                    ->generarCodigoTicket();


            $fechaRecepcion =
                date(
                    'Y-m-d H:i:s'
                );


            // -------------------------------------------------------
            // Crear encabezado Pedido
            // -------------------------------------------------------

            $idPedido =
                $this->pedidoModel
                    ->registrarRecepcion(
                        $codigoTicket,
                        $fechaRecepcion,
                        (int) $body['idCliente'],
                        $idEmpleado
                    );


            // -------------------------------------------------------
            // Insertar detalles
            // -------------------------------------------------------

            $erroresDetalle = [];


            foreach (
                $detalles as $i => $detalle
            ) {
                $resultado =
                    $this->detalleModel
                        ->insertarDetalle(
                            $idPedido,
                            (int) $detalle['idPrenda'],
                            (float) $detalle['cantidad'],
                            trim(
                                (string) (
                                    $detalle['descripcion']
                                    ?? ''
                                )
                            )
                        );


                if ($resultado === false) {
                    $erroresDetalle[] =
                        sprintf(
                            'Prenda #%d (línea %d): no se pudo registrar.',
                            (int) $detalle['idPrenda'],
                            $i + 1
                        );
                }
            }


            // -------------------------------------------------------
            // 7. Si falla una prenda, revertir pedido
            // -------------------------------------------------------

            if (
                ! empty(
                    $erroresDetalle
                )
            ) {
                /*
                 * DetallePedido utiliza ON DELETE CASCADE,
                 * por lo que al borrar Pedido se eliminan
                 * los detalles insertados previamente.
                 *
                 * Esto ocurre antes de registrar cualquier pago.
                 */
                $this->pedidoModel
                    ->delete(
                        $idPedido
                    );


                return $this->response
                    ->setStatusCode(
                        ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                    )
                    ->setJSON([
                        'success' =>
                            false,

                        'status' =>
                            ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,

                        'message' =>
                            'No se pudo registrar completamente el pedido. La operación fue revertida.',

                        'data' =>
                            null,

                        'errors' => [
                            'detalles' =>
                                $erroresDetalle,
                        ],
                    ]);
            }


            // -------------------------------------------------------
            // 8. Procesar pago inicial opcional
            //
            // En este punto los triggers ya calcularon Pedido.total.
            // -------------------------------------------------------

            if (
                $pagoInicial !== null
            ) {
                $resultadoPago =
                    $this->pagoModel
                        ->registrarPago(
                            $idPedido,
                            $pagoInicial['monto'],
                            $pagoInicial['metodo']
                        );


                // ---------------------------------------------------
                // Pago rechazado
                // ---------------------------------------------------

                if (
                    ! $resultadoPago['success']
                ) {
                    $codigoError =
                        $resultadoPago['codigo_error']
                        ?? 'PAGO_INICIAL_INVALIDO';


                    /*
                     * Como registrarPago() devolvió error,
                     * no existe una fila Pago válida.
                     *
                     * Por lo tanto el pedido puede eliminarse.
                     *
                     * Sus DetallePedido se eliminan mediante CASCADE.
                     */
                    $this->pedidoModel
                        ->delete(
                            $idPedido
                        );


                    $codigoHttp =
                        match (
                            $codigoError
                        ) {
                            'SOBREPAGO',
                            'SOBREPAGO_TRIGGER_45000',
                            'SIN_SALDO_PENDIENTE',
                            'MONTO_INVALIDO',
                            'METODO_INVALIDO',
                            'VALIDATION_ERROR' =>
                                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,

                            'PEDIDO_NO_EXISTE' =>
                                ResponseInterface::HTTP_NOT_FOUND,

                            default =>
                                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,
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
                                'No se pudo registrar el pedido porque el pago inicial es inválido.',

                            'data' =>
                                null,

                            'errors' => [
                                'pagoInicial' =>
                                    $resultadoPago['mensaje'],

                                'codigo_error' =>
                                    $codigoError,
                            ],
                        ]);
                }
            }


            // -------------------------------------------------------
            // 9. Obtener ticket completo
            // -------------------------------------------------------

            $ticket =
                $this->pedidoModel
                    ->getDatosTicket(
                        $idPedido
                    );


            // -------------------------------------------------------
            // 10. Respuesta exitosa
            // -------------------------------------------------------

            return $this->respondCreated(
                [
                    'idPedido' =>
                        $idPedido,

                    'codigoTicket' =>
                        $codigoTicket,

                    'ticket' =>
                        $ticket,
                ],

                'Pedido registrado exitosamente.'
            );

        } catch (DatabaseException $e) {

            return $this->handleDbException(
                $e
            );

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
    public function obtenerTicket(
        $id = null
    ): ResponseInterface {
        if (
            $id === null
            || ! is_numeric($id)
        ) {
            return $this->respondError(
                'El identificador del pedido es inválido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }


        try {
            $ticket =
                $this->pedidoModel
                    ->getDatosTicket(
                        (int) $id
                    );


            return $this->respondSuccess(
                $ticket,
                'Ticket generado exitosamente.'
            );

        } catch (\RuntimeException $e) {

            return $this->respondNotFound(
                $e->getMessage()
            );

        } catch (DatabaseException $e) {

            return $this->handleDbException(
                $e
            );
        }
    }


    // ===============================================================
    // PATCH /api/v1/pedidos/{id}/estado
    // ===============================================================

    /**
     * Actualiza el estado operativo del pedido.
     *
     * Estados esperados:
     *
     * Recibido
     * En Proceso
     * Listo
     * Entregado
     * Cancelado
     */
    public function cambiarEstado(
        $id = null
    ): ResponseInterface {

        if (
            $id === null
            || ! is_numeric($id)
        ) {
            return $this->respondError(
                'El identificador del pedido es inválido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }


        $body =
            $this->getJsonBody();


        if (! is_array($body)) {
            return $this->respondError(
                'El cuerpo de la solicitud debe contener un JSON válido.',
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }


        $nuevoEstado =
            trim(
                (string) (
                    $body['estado']
                    ?? ''
                )
            );


        if (
            $nuevoEstado === ''
        ) {
            return $this->respondValidationError([
                'estado' =>
                    'El nuevo estado es requerido.',
            ]);
        }


        $pedido =
            $this->pedidoModel
                ->find(
                    (int) $id
                );


        if ($pedido === null) {
            return $this->respondNotFound(
                "Pedido #{$id} no encontrado."
            );
        }


        // -----------------------------------------------------------
        // Solo admin puede cancelar
        // -----------------------------------------------------------

        if (
            $nuevoEstado === 'Cancelado'
            && ! $this->tieneRol(
                'admin'
            )
        ) {
            return $this->respondError(
                'Solo el administrador puede cancelar pedidos.',
                ResponseInterface::HTTP_FORBIDDEN
            );
        }


        try {
            $this->pedidoModel
                ->cambiarEstado(
                    (int) $id,
                    $nuevoEstado
                );


            // -------------------------------------------------------
            // Refrescar después del trigger
            // -------------------------------------------------------

            $pedidoActualizado =
                $this->pedidoModel
                    ->select(
                        'idPedido, codigoTicket, estado, fechaEntrega, total'
                    )
                    ->find(
                        (int) $id
                    );


            return $this->respondSuccess(
                $pedidoActualizado,

                "Estado del pedido #{$id} actualizado a '{$nuevoEstado}'."
            );

        } catch (\InvalidArgumentException $e) {

            return $this->respondValidationError([
                'estado' =>
                    $e->getMessage(),
            ]);

        } catch (DatabaseException $e) {

            return $this->handleDbException(
                $e
            );
        }
    }
}