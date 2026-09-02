<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use CodeIgniter\Database\Exceptions\DatabaseException;
use App\Libraries\DbExceptionHandler;

/**
 * PagoModel
 *
 * Gestiona la tabla `Pago` y el flujo de cobro presencial de MaryClean.
 *
 * COBRO EXCLUSIVAMENTE PRESENCIAL:
 * Los métodos de pago soportados son los que se procesan en mostrador:
 * Efectivo, Tarjeta (POS físico) y Yape/Plin (verificación manual).
 * NO se integran pasarelas de pago externas ni APIs de cobro online.
 *
 * TRIGGERS ASOCIADOS:
 * - `trg_pago_antes_insertar_validar`: ANTES de insertar, valida que el monto
 *   no supere el saldo pendiente (total - SUM(pagos)). Lanza SQLSTATE '45000'
 *   si se intenta cobrar en exceso. Este modelo captura esa excepción.
 * - `trg_pago_despues_insertar_estado`: DESPUÉS de insertar, si la suma de
 *   pagos cubre el total del pedido, cambia el estado a 'Pagado' automáticamente.
 *
 * CHECK CONSTRAINT en BD: monto > 0.
 *
 * @package App\Models
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

    protected $useTimestamps = false;

    /**
     * Métodos de pago válidos (exclusivamente presenciales).
     */
    public const METODOS_PAGO = ['Efectivo', 'Tarjeta', 'Yape/Plin'];

    // ---------------------------------------------------------------
    // Reglas de Validación
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
            'required' => 'El método de pago es obligatorio.',
            'in_list'  => 'Método de pago inválido. Valores permitidos: Efectivo, Tarjeta, Yape/Plin.',
        ],
        'fechaPago' => [
            'required'   => 'La fecha del pago es obligatoria.',
            'valid_date' => 'La fecha de pago debe tener formato Y-m-d H:i:s.',
        ],
        'idPedido' => [
            'required'      => 'El pedido asociado al pago es obligatorio.',
            'is_not_unique' => 'El pedido especificado no existe.',
        ],
    ];

    protected $skipValidation = false;

    // ---------------------------------------------------------------
    // Registro de Pago con Manejo de Excepción SQLSTATE 45000
    // ---------------------------------------------------------------

    /**
     * Registra un pago presencial capturando el error SQLSTATE '45000'
     * lanzado por `trg_pago_antes_insertar_validar` si el monto supera
     * el saldo pendiente.
     *
     * @param int    $idPedido ID del pedido a pagar
     * @param float  $monto    Monto a abonar (debe ser > 0 y ≤ saldo pendiente)
     * @param string $metodo   Método de pago: 'Efectivo', 'Tarjeta', 'Yape/Plin'
     * @param string|null $fechaPago Fecha/hora del pago (por defecto: ahora)
     * @return array{
     *   success: bool,
     *   idPago: int|null,
     *   mensaje: string,
     *   codigo_error: string|null
     * }
     */
    public function registrarPago(
        int $idPedido,
        float $monto,
        string $metodo,
        ?string $fechaPago = null
    ): array {
        $fechaPago = $fechaPago ?? date('Y-m-d H:i:s');

        $data = [
            'monto'     => $monto,
            'metodo'    => $metodo,
            'fechaPago' => $fechaPago,
            'idPedido'  => $idPedido,
        ];

        try {
            $idPago = $this->insert($data, true);

            if ($idPago === false) {
                return [
                    'success'      => false,
                    'idPago'       => null,
                    'mensaje'      => 'Error de validación: ' . implode(', ', $this->errors()),
                    'codigo_error' => 'VALIDATION_ERROR',
                ];
            }

            return [
                'success'      => true,
                'idPago'       => (int) $idPago,
                'mensaje'      => 'Pago registrado correctamente.',
                'codigo_error' => null,
            ];
        } catch (DatabaseException $e) {
            return $this->procesarExcepcionPago($e, $idPedido, $monto);
        }
    }

    /**
     * Procesa la excepción DatabaseException generada por el trigger de pago.
     * Detecta el SQLSTATE '45000' (sobrepago) y devuelve un mensaje estructurado.
     *
     * @param DatabaseException $e
     * @param int               $idPedido
     * @param float             $monto
     * @return array
     */
    private function procesarExcepcionPago(
        DatabaseException $e,
        int $idPedido,
        float $monto
    ): array {
        $mensaje = $e->getMessage();

        // El trigger lanza un mensaje con SQLSTATE 45000 y texto descriptivo
        if (
            str_contains($mensaje, '45000') ||
            str_contains(strtolower($mensaje), 'saldo') ||
            str_contains(strtolower($mensaje), 'excede') ||
            str_contains(strtolower($mensaje), 'monto')
        ) {
            $saldoPendiente = 0.0;

            try {
                $pedidoModel    = new PedidoModel();
                $saldoPendiente = $pedidoModel->getSaldoPendiente($idPedido);
            } catch (\Throwable) {
                // Silenciar error secundario al calcular saldo
            }

            return [
                'success'      => false,
                'idPago'       => null,
                'mensaje'      => sprintf(
                    'El monto ingresado (S/ %.2f) supera el saldo pendiente del pedido (S/ %.2f). ' .
                    'Por favor ingrese un monto igual o menor al saldo pendiente.',
                    $monto,
                    $saldoPendiente
                ),
                'codigo_error' => 'SOBREPAGO_TRIGGER_45000',
            ];
        }

        // Otros errores de base de datos (FK, conexión, etc.)
        log_message('error', '[PagoModel::registrarPago] DatabaseException: ' . $mensaje);

        return [
            'success'      => false,
            'idPago'       => null,
            'mensaje'      => 'Error interno al procesar el pago. Contacte al administrador.',
            'codigo_error' => 'DATABASE_ERROR',
        ];
    }

    // ---------------------------------------------------------------
    // Consultas
    // ---------------------------------------------------------------

    /**
     * Obtiene todos los pagos de un pedido.
     *
     * @param int $idPedido
     * @return array
     */
    public function getPagosPorPedido(int $idPedido): array
    {
        return $this->where('idPedido', $idPedido)
            ->orderBy('fechaPago', 'ASC')
            ->findAll();
    }

    /**
     * Calcula el total pagado hasta el momento para un pedido.
     *
     * @param int $idPedido
     * @return float
     */
    public function getTotalPagado(int $idPedido): float
    {
        $resultado = $this->db->table($this->table)
            ->selectSum('monto')
            ->where('idPedido', $idPedido)
            ->get()
            ->getRowArray();

        return (float) ($resultado['monto'] ?? 0);
    }

    /**
     * Obtiene los pagos del día actual agrupados por método.
     *
     * @return array
     */
    public function getPagosHoy(): array
    {
        return $this->db->table($this->table)
            ->select('metodo, SUM(monto) AS totalMetodo, COUNT(*) AS cantidadTransacciones')
            ->where('DATE(fechaPago)', date('Y-m-d'))
            ->groupBy('metodo')
            ->get()
            ->getResultArray();
    }

    /**
     * Genera el array estructurado del recibo de pago para impresión en mostrador.
     *
     * @param int $idPago
     * @return array|null
     */
    public function getDatosRecibo(int $idPago): ?array
    {
        $pago = $this->db->table('Pago pag')
            ->select(
                'pag.idPago, pag.monto, pag.metodo, pag.fechaPago, ' .
                'p.codigoTicket, p.total AS totalPedido, p.estado, ' .
                'c.nombres AS cliente, c.documento'
            )
            ->join('Pedido p', 'p.idPedido = pag.idPedido', 'inner')
            ->join('Cliente c', 'c.idCliente = p.idCliente', 'inner')
            ->where('pag.idPago', $idPago)
            ->get()
            ->getRowArray();

        if (! $pago) {
            return null;
        }

        return [
            'empresa'       => 'LAVANDERÍA MARYCLEAN',
            'recibo_n'      => str_pad((string) $idPago, 8, '0', STR_PAD_LEFT),
            'fecha_pago'    => date('d/m/Y H:i:s', strtotime($pago['fechaPago'])),
            'ticket'        => $pago['codigoTicket'],
            'cliente'       => strtoupper($pago['cliente']),
            'documento'     => $pago['documento'],
            'monto_abonado' => number_format((float) $pago['monto'], 2),
            'metodo'        => $pago['metodo'],
            'total_pedido'  => number_format((float) $pago['totalPedido'], 2),
            'estado_pedido' => $pago['estado'],
            'nota'          => 'Pago recibido en mostrador.',
        ];
    }
}
