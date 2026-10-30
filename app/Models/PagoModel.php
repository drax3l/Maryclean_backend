<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\DbExceptionHandler;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;

/**
 * PagoModel
 *
 * Gestiona la tabla `Pago` y el flujo de cobro presencial de MaryClean.
 *
 * COBRO EXCLUSIVAMENTE PRESENCIAL:
 * - Efectivo
 * - Tarjeta (POS físico)
 * - Yape/Plin (verificación manual)
 *
 * SEGURIDAD DE PAGOS:
 * 1. PHP valida explícitamente el saldo antes de insertar.
 * 2. La base de datos vuelve a validar mediante trigger.
 *
 * De esta forma, aunque el frontend sea omitido o manipulado,
 * el backend sigue rechazando sobrepagos.
 */
class PagoModel extends Model
{
    use DbExceptionHandler;

    protected $table            = 'Pago';
    protected $primaryKey       = 'idPago';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'monto',
        'metodo',
        'fechaPago',
        'idPedido',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Métodos de pago permitidos.
     */
    public const METODOS_PAGO = [
        'Efectivo',
        'Tarjeta',
        'Yape/Plin',
    ];

    // ---------------------------------------------------------------
    // Reglas de validación
    // ---------------------------------------------------------------

    protected $validationRules = [
        'monto'     => 'required|decimal|greater_than[0]',
        'metodo'    => 'required|in_list[Efectivo,Tarjeta,Yape/Plin]',
        'fechaPago' => 'required|valid_date[Y-m-d H:i:s]',
        'idPedido'  => 'required|integer|is_not_unique[Pedido.idPedido]',
    ];

    protected $validationMessages = [
        'monto' => [
            'required'     => 'El monto del pago es obligatorio.',
            'decimal'      => 'El monto debe ser un número decimal válido.',
            'greater_than' => 'El monto debe ser mayor a 0.',
        ],

        'metodo' => [
            'required' =>
                'El método de pago es obligatorio.',

            'in_list' =>
                'Método de pago inválido. Valores permitidos: Efectivo, Tarjeta, Yape/Plin.',
        ],

        'fechaPago' => [
            'required' =>
                'La fecha del pago es obligatoria.',

            'valid_date' =>
                'La fecha de pago debe tener formato Y-m-d H:i:s.',
        ],

        'idPedido' => [
            'required' =>
                'El pedido asociado al pago es obligatorio.',

            'is_not_unique' =>
                'El pedido especificado no existe.',
        ],
    ];

    protected $skipValidation = false;


    // ---------------------------------------------------------------
    // Registro de pago
    // ---------------------------------------------------------------

    /**
     * Registra un pago presencial.
     *
     * Valida previamente:
     *
     * - existencia del pedido;
     * - monto mayor que cero;
     * - método permitido;
     * - existencia de saldo pendiente;
     * - monto <= saldo pendiente.
     *
     * El trigger de MySQL continúa funcionando como segunda
     * barrera de protección contra condiciones de carrera.
     *
     * @param int         $idPedido
     * @param float       $monto
     * @param string      $metodo
     * @param string|null $fechaPago
     *
     * @return array{
     *     success: bool,
     *     idPago: int|null,
     *     mensaje: string,
     *     codigo_error: string|null
     * }
     */
    public function registrarPago(
        int $idPedido,
        float $monto,
        string $metodo,
        ?string $fechaPago = null
    ): array {
        $fechaPago = $fechaPago ?? date('Y-m-d H:i:s');

        /*
         * Normalizar a dos decimales.
         *
         * Las comparaciones monetarias se realizarán además
         * en céntimos para evitar problemas típicos de float.
         */
        $montoNormalizado = round($monto, 2);

        // -----------------------------------------------------------
        // Validaciones básicas
        // -----------------------------------------------------------

        if ($montoNormalizado <= 0) {
            return [
                'success'      => false,
                'idPago'       => null,
                'mensaje'      => 'El monto del pago debe ser mayor a S/ 0.00.',
                'codigo_error' => 'MONTO_INVALIDO',
            ];
        }

        if (! in_array($metodo, self::METODOS_PAGO, true)) {
            return [
                'success'      => false,
                'idPago'       => null,
                'mensaje'      =>
                    'Método de pago inválido. Valores permitidos: Efectivo, Tarjeta, Yape/Plin.',
                'codigo_error' => 'METODO_INVALIDO',
            ];
        }

        try {
            // -------------------------------------------------------
            // Obtener estado financiero actual del pedido
            // -------------------------------------------------------

            $resumen = $this->obtenerResumenFinancieroPedido(
                $idPedido
            );

            if ($resumen === null) {
                return [
                    'success'      => false,
                    'idPago'       => null,
                    'mensaje'      => 'El pedido especificado no existe.',
                    'codigo_error' => 'PEDIDO_NO_EXISTE',
                ];
            }

            $saldoPendiente = $resumen['saldoPendiente'];

            // -------------------------------------------------------
            // El pedido ya está completamente pagado
            // -------------------------------------------------------

            if ($saldoPendiente <= 0) {
                return [
                    'success'      => false,
                    'idPago'       => null,
                    'mensaje'      =>
                        'El pedido ya se encuentra completamente pagado y no tiene saldo pendiente.',
                    'codigo_error' => 'SIN_SALDO_PENDIENTE',
                ];
            }

            // -------------------------------------------------------
            // Comparación monetaria usando céntimos
            // -------------------------------------------------------

            $montoCentimos = (int) round(
                $montoNormalizado * 100
            );

            $saldoCentimos = (int) round(
                $saldoPendiente * 100
            );

            if ($montoCentimos > $saldoCentimos) {
                return [
                    'success' => false,

                    'idPago' => null,

                    'mensaje' => sprintf(
                        'El monto ingresado (S/ %.2f) supera el saldo pendiente del pedido (S/ %.2f). '
                        . 'Ingrese un monto igual o menor al saldo pendiente.',
                        $montoNormalizado,
                        $saldoPendiente
                    ),

                    'codigo_error' => 'SOBREPAGO',
                ];
            }

            // -------------------------------------------------------
            // Preparar pago
            // -------------------------------------------------------

            $data = [
                'monto'     => $montoNormalizado,
                'metodo'    => $metodo,
                'fechaPago' => $fechaPago,
                'idPedido'  => $idPedido,
            ];

            // -------------------------------------------------------
            // Insertar
            //
            // El trigger trg_pago_antes_insertar_validar debe volver
            // a comprobar el saldo en MySQL.
            // -------------------------------------------------------

            $idPago = $this->insert(
                $data,
                true
            );

            if ($idPago === false) {
                return [
                    'success' => false,

                    'idPago' => null,

                    'mensaje' =>
                        'Error de validación: '
                        . implode(
                            ', ',
                            $this->errors()
                        ),

                    'codigo_error' =>
                        'VALIDATION_ERROR',
                ];
            }

            return [
                'success'      => true,
                'idPago'       => (int) $idPago,
                'mensaje'      => 'Pago registrado correctamente.',
                'codigo_error' => null,
            ];

        } catch (DatabaseException $e) {

            /*
             * Segunda barrera:
             *
             * Si otro pago fue registrado entre nuestra consulta
             * y el INSERT, el trigger debe impedir el sobrepago.
             */
            return $this->procesarExcepcionPago(
                $e,
                $idPedido,
                $montoNormalizado
            );
        }
    }


    // ---------------------------------------------------------------
    // Resumen financiero
    // ---------------------------------------------------------------

    /**
     * Obtiene el total del pedido, total pagado y saldo pendiente.
     *
     * IMPORTANTE:
     * No usa max(0) internamente porque queremos poder detectar
     * una eventual inconsistencia histórica de sobrepago.
     *
     * @return array{
     *     totalPedido: float,
     *     totalPagado: float,
     *     saldoPendiente: float
     * }|null
     */
    private function obtenerResumenFinancieroPedido(
        int $idPedido
    ): ?array {
        $pedido = $this->db
            ->table('Pedido')
            ->select(
                'idPedido, total'
            )
            ->where(
                'idPedido',
                $idPedido
            )
            ->get()
            ->getRowArray();

        if (! $pedido) {
            return null;
        }

        $totalPedido = round(
            (float) $pedido['total'],
            2
        );

        $totalPagado = round(
            $this->getTotalPagado(
                $idPedido
            ),
            2
        );

        $saldoPendiente = round(
            $totalPedido - $totalPagado,
            2
        );

        return [
            'totalPedido'     => $totalPedido,
            'totalPagado'     => $totalPagado,
            'saldoPendiente'  => $saldoPendiente,
        ];
    }


    // ---------------------------------------------------------------
    // Excepciones de trigger / BD
    // ---------------------------------------------------------------

    /**
     * Procesa la excepción DatabaseException generada
     * principalmente por el trigger de pagos.
     */
    private function procesarExcepcionPago(
        DatabaseException $e,
        int $idPedido,
        float $monto
    ): array {
        $mensaje =
            $e->getMessage();

        $mensajeMinusculas =
            strtolower($mensaje);

        /*
         * No usamos únicamente la palabra "monto",
         * porque podría aparecer en otros errores de BD
         * y generar un falso diagnóstico de sobrepago.
         */
        $esSobrepago =
            str_contains(
                $mensaje,
                '45000'
            )
            ||
            str_contains(
                $mensajeMinusculas,
                'saldo pendiente'
            )
            ||
            str_contains(
                $mensajeMinusculas,
                'sobrepago'
            )
            ||
            str_contains(
                $mensajeMinusculas,
                'supera'
            )
            ||
            str_contains(
                $mensajeMinusculas,
                'excede'
            );

        if ($esSobrepago) {
            $saldoPendiente = 0.0;

            try {
                $resumen =
                    $this->obtenerResumenFinancieroPedido(
                        $idPedido
                    );

                if ($resumen !== null) {
                    $saldoPendiente =
                        $resumen['saldoPendiente'];
                }
            } catch (\Throwable) {
                /*
                 * No ocultamos el error original
                 * si esta consulta secundaria falla.
                 */
            }

            return [
                'success' => false,

                'idPago' => null,

                'mensaje' => sprintf(
                    'El monto ingresado (S/ %.2f) supera el saldo pendiente del pedido (S/ %.2f). '
                    . 'Ingrese un monto igual o menor al saldo pendiente.',
                    $monto,
                    max(
                        0.0,
                        $saldoPendiente
                    )
                ),

                'codigo_error' =>
                    'SOBREPAGO_TRIGGER_45000',
            ];
        }

        // Otros errores reales de BD.
        log_message(
            'error',
            '[PagoModel::registrarPago] DatabaseException: '
            . $mensaje
        );

        return [
            'success'      => false,
            'idPago'       => null,
            'mensaje'      =>
                'Error interno al procesar el pago. Contacte al administrador.',
            'codigo_error' => 'DATABASE_ERROR',
        ];
    }


    // ---------------------------------------------------------------
    // Consultas
    // ---------------------------------------------------------------

    /**
     * Obtiene todos los pagos de un pedido.
     */
    public function getPagosPorPedido(
        int $idPedido
    ): array {
        return $this
            ->where(
                'idPedido',
                $idPedido
            )
            ->orderBy(
                'fechaPago',
                'ASC'
            )
            ->findAll();
    }


    /**
     * Calcula el total abonado a un pedido.
     */
    public function getTotalPagado(
        int $idPedido
    ): float {
        $resultado = $this->db
            ->table(
                $this->table
            )
            ->selectSum(
                'monto'
            )
            ->where(
                'idPedido',
                $idPedido
            )
            ->get()
            ->getRowArray();

        return round(
            (float) (
                $resultado['monto']
                ?? 0
            ),
            2
        );
    }


    /**
     * Obtiene los pagos del día actual agrupados por método.
     */
    public function getPagosHoy(): array
    {
        return $this->db
            ->table(
                $this->table
            )
            ->select(
                'metodo, '
                . 'SUM(monto) AS totalMetodo, '
                . 'COUNT(*) AS cantidadTransacciones'
            )
            ->where(
                'DATE(fechaPago)',
                date('Y-m-d')
            )
            ->groupBy(
                'metodo'
            )
            ->get()
            ->getResultArray();
    }


    // ---------------------------------------------------------------
    // Recibo de pago
    // ---------------------------------------------------------------

    /**
     * Genera la información estructurada de un recibo
     * de pago para impresión en mostrador.
     */
    public function getDatosRecibo(
        int $idPago
    ): ?array {
        $pago = $this->db
            ->table('Pago pag')
            ->select(
                'pag.idPago, '
                . 'pag.idPedido, '
                . 'pag.monto, '
                . 'pag.metodo, '
                . 'pag.fechaPago, '
                . 'p.codigoTicket, '
                . 'p.total AS totalPedido, '
                . 'p.estado, '
                . 'c.nombres AS cliente, '
                . 'c.documento'
            )
            ->join(
                'Pedido p',
                'p.idPedido = pag.idPedido',
                'inner'
            )
            ->join(
                'Cliente c',
                'c.idCliente = p.idCliente',
                'inner'
            )
            ->where(
                'pag.idPago',
                $idPago
            )
            ->get()
            ->getRowArray();

        if (! $pago) {
            return null;
        }

        /*
         * El recibo ahora distingue:
         *
         * - monto de ESTE pago;
         * - total del pedido;
         * - total acumulado pagado;
         * - saldo pendiente.
         */
        $resumen =
            $this->obtenerResumenFinancieroPedido(
                (int) $pago['idPedido']
            );

        $totalPedido =
            $resumen['totalPedido']
            ?? (float) $pago['totalPedido'];

        $totalPagado =
            $resumen['totalPagado']
            ?? (float) $pago['monto'];

        $saldoPendiente =
            $resumen['saldoPendiente']
            ?? (
                $totalPedido
                - $totalPagado
            );

        return [
            'empresa' =>
                'LAVANDERÍA MARYCLEAN',

            'recibo_n' =>
                str_pad(
                    (string) $idPago,
                    8,
                    '0',
                    STR_PAD_LEFT
                ),

            'fecha_pago' =>
                date(
                    'd/m/Y H:i:s',
                    strtotime(
                        $pago['fechaPago']
                    )
                ),

            'ticket' =>
                $pago['codigoTicket'],

            'cliente' =>
                strtoupper(
                    $pago['cliente']
                ),

            'documento' =>
                $pago['documento'],

            /*
             * Importe correspondiente exclusivamente
             * a este pago.
             */
            'monto_abonado' =>
                number_format(
                    (float) $pago['monto'],
                    2,
                    '.',
                    ''
                ),

            'metodo' =>
                $pago['metodo'],

            /*
             * Importe total original del pedido.
             */
            'total_pedido' =>
                number_format(
                    $totalPedido,
                    2,
                    '.',
                    ''
                ),

            /*
             * Suma de todos los pagos realizados
             * hasta este momento.
             */
            'total_pagado' =>
                number_format(
                    $totalPagado,
                    2,
                    '.',
                    ''
                ),

            /*
             * Saldo restante.
             *
             * max() solamente se utiliza para presentación
             * del recibo. La validación interna NO oculta
             * posibles valores negativos.
             */
            'saldo_pendiente' =>
                number_format(
                    max(
                        0.0,
                        $saldoPendiente
                    ),
                    2,
                    '.',
                    ''
                ),

            'estado_pedido' =>
                $pago['estado'],

            'nota' =>
                'Pago recibido en mostrador.',
        ];
    }
}