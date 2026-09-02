<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use CodeIgniter\Database\Exceptions\DatabaseException;
use App\Libraries\DbExceptionHandler;

/**
 * PedidoModel
 *
 * Gestiona la tabla `Pedido` y orquesta la lógica de negocio del ciclo
 * de vida de un pedido en MaryClean.
 *
 * INTERACCIÓN CON LA BASE DE DATOS:
 * - Stored Procedure `sp_registrar_recepcion`: Crea el pedido en una
 *   transacción nativa de MySQL. Devuelve el ID generado (OUT param).
 * - Vista `v_pedidos_activos`: Pedidos en curso (no entregados/cancelados).
 * - Trigger `trg_detalle_insertar_total` et al.: Recalculan `total` automáticamente.
 * - Trigger `trg_pedido_antes_actualizar`: Asigna `fechaEntrega` al pasar a 'Entregado'.
 * - Trigger `trg_pedido_despues_actualizar_auditoria`: Registra cambios de estado.
 * - Trigger `trg_pago_despues_insertar_estado`: Cambia a 'Pagado' si la suma cubre el total.
 *
 * @package App\Models
 */
class PedidoModel extends Model
{
    use DbExceptionHandler;

    protected $table            = 'Pedido';
    protected $primaryKey       = 'idPedido';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /**
     * `total` y `fechaEntrega` son calculados por triggers de MySQL,
     * no se incluyen en allowedFields para evitar sobrescritura accidental.
     */
    protected $allowedFields = [
        'codigoTicket',
        'fechaRecepcion',
        'estado',
        'idCliente',
        'idEmpleado',
    ];

    protected $useTimestamps = false;

    /**
     * Estados válidos del ciclo de vida de un pedido.
     */
    public const ESTADOS = [
        'Recibido',
        'En Proceso',
        'Listo',
        'Entregado',
        'Pagado',
        'Cancelado',
    ];

    // ---------------------------------------------------------------
    // Reglas de Validación
    // ---------------------------------------------------------------
    protected $validationRules = [
        'codigoTicket'   => 'required|min_length[5]|max_length[20]|is_unique[Pedido.codigoTicket,idPedido,{idPedido}]',
        'fechaRecepcion' => 'required|valid_date[Y-m-d H:i:s]',
        'estado'         => 'required|in_list[Recibido,En Proceso,Listo,Entregado,Pagado,Cancelado]',
        'idCliente'      => 'required|integer|is_not_unique[Cliente.idCliente]',
        'idEmpleado'     => 'required|integer|is_not_unique[Empleado.idEmpleado]',
    ];

    protected $validationMessages = [
        'codigoTicket' => [
            'required'   => 'El código de ticket es obligatorio.',
            'is_unique'  => 'Ya existe un pedido con ese código de ticket.',
        ],
        'fechaRecepcion' => [
            'required'   => 'La fecha de recepción es obligatoria.',
            'valid_date' => 'La fecha de recepción debe tener formato Y-m-d H:i:s.',
        ],
        'estado' => [
            'required' => 'El estado del pedido es obligatorio.',
            'in_list'  => 'Estado inválido. Valores permitidos: ' . implode(', ', self::ESTADOS),
        ],
        'idCliente' => [
            'required'      => 'El cliente del pedido es obligatorio.',
            'is_not_unique' => 'El cliente especificado no existe.',
        ],
        'idEmpleado' => [
            'required'      => 'El empleado asignado al pedido es obligatorio.',
            'is_not_unique' => 'El empleado especificado no existe.',
        ],
    ];

    protected $skipValidation = false;

    // ---------------------------------------------------------------
    // Stored Procedure: sp_registrar_recepcion
    // ---------------------------------------------------------------

    /**
     * Registra la recepción de un nuevo pedido ejecutando el Stored Procedure
     * `sp_registrar_recepcion`, que inicia una transacción MySQL nativa.
     *
     * El SP inserta la cabecera del pedido y devuelve el ID generado
     * mediante un parámetro OUT (@p_idPedidoGenerado).
     *
     * @param string $codigoTicket   Código único del ticket (generado en el controlador).
     * @param string $fechaRecepcion Fecha/hora de ingreso del pedido (Y-m-d H:i:s).
     * @param int    $idCliente      FK del cliente.
     * @param int    $idEmpleado     FK del empleado recepcionista.
     * @return int                   El ID del nuevo pedido generado.
     * @throws DatabaseException     Si el SP falla (ej. violación de FK o error interno).
     */
    public function registrarRecepcion(
        string $codigoTicket,
        string $fechaRecepcion,
        int $idCliente,
        int $idEmpleado
    ): int {
        try {
            // Llamar al SP que inicia la transacción MySQL nativa
            $this->db->query(
                'CALL sp_registrar_recepcion(?, ?, ?, ?, @p_idPedidoGenerado)',
                [$codigoTicket, $fechaRecepcion, $idCliente, $idEmpleado]
            );

            // Recuperar el parámetro OUT
            $resultado = $this->db->query('SELECT @p_idPedidoGenerado AS idPedido')->getRowArray();

            if (! $resultado || ! isset($resultado['idPedido']) || $resultado['idPedido'] === null) {
                throw new DatabaseException(
                    'sp_registrar_recepcion no devolvió un ID de pedido válido.'
                );
            }

            return (int) $resultado['idPedido'];
        } catch (DatabaseException $e) {
            throw $this->envolverExcepcionDB($e, 'registrarRecepcion');
        }
    }

    // ---------------------------------------------------------------
    // Vista: v_pedidos_activos
    // ---------------------------------------------------------------

    /**
     * Obtiene todos los pedidos activos (estado distinto a 'Entregado' o 'Cancelado')
     * desde la vista `v_pedidos_activos` con datos del cliente y empleado.
     *
     * @return array
     */
    public function getPedidosActivos(): array
    {
        return $this->db->table('v_pedidos_activos')
            ->orderBy('fechaRecepcion', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Obtiene los pedidos activos filtrados por sucursal.
     *
     * @param int $idSucursal
     * @return array
     */
    public function getPedidosActivosPorSucursal(int $idSucursal): array
    {
        return $this->db->table('v_pedidos_activos')
            ->where('idSucursal', $idSucursal)
            ->orderBy('fechaRecepcion', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Obtiene un pedido activo por su código de ticket.
     *
     * @param string $codigoTicket
     * @return array|null
     */
    public function getPedidoActivoPorTicket(string $codigoTicket): ?array
    {
        return $this->db->table('v_pedidos_activos')
            ->where('codigoTicket', $codigoTicket)
            ->get()
            ->getRowArray() ?: null;
    }

    // ---------------------------------------------------------------
    // Gestión de Estados
    // ---------------------------------------------------------------

    /**
     * Cambia el estado de un pedido.
     * Al cambiar a 'Entregado', el trigger `trg_pedido_antes_actualizar` asigna
     * automáticamente la fechaEntrega. El trigger de auditoría registra el cambio.
     *
     * @param int    $idPedido
     * @param string $nuevoEstado
     * @return bool
     * @throws \InvalidArgumentException Si el estado no es válido.
     */
    public function cambiarEstado(int $idPedido, string $nuevoEstado): bool
    {
        if (! in_array($nuevoEstado, self::ESTADOS, true)) {
            throw new \InvalidArgumentException(
                "Estado inválido: '{$nuevoEstado}'. Valores permitidos: " . implode(', ', self::ESTADOS)
            );
        }

        // Solo actualiza el estado; el trigger se encarga de fechaEntrega y auditoría
        return $this->db->table($this->table)
            ->where('idPedido', $idPedido)
            ->update(['estado' => $nuevoEstado]);
    }

    /**
     * Cancela un pedido, registrando el cambio en la auditoría vía trigger.
     *
     * @param int $idPedido
     * @return bool
     */
    public function cancelarPedido(int $idPedido): bool
    {
        return $this->cambiarEstado($idPedido, 'Cancelado');
    }

    // ---------------------------------------------------------------
    // Consultas con Joins Completos
    // ---------------------------------------------------------------

    /**
     * Obtiene un pedido completo con cliente, empleado y detalles.
     *
     * @param int $idPedido
     * @return array|null
     */
    public function getPedidoCompleto(int $idPedido): ?array
    {
        $pedido = $this->db->table('Pedido p')
            ->select(
                'p.idPedido, p.codigoTicket, p.fechaRecepcion, p.fechaEntrega, p.estado, p.total, ' .
                'c.idCliente, c.documento, c.nombres AS cliente, c.telefono AS telefonoCliente, ' .
                'e.idEmpleado, e.nombres AS empleado, e.rol AS rolEmpleado'
            )
            ->join('Cliente c', 'c.idCliente = p.idCliente', 'inner')
            ->join('Empleado e', 'e.idEmpleado = p.idEmpleado', 'inner')
            ->where('p.idPedido', $idPedido)
            ->get()
            ->getRowArray();

        if (! $pedido) {
            return null;
        }

        // Cargar detalles del pedido
        $detalleModel       = new DetallePedidoModel();
        $pedido['detalles'] = $detalleModel->getDetallesPorPedido($idPedido);

        // Cargar pagos del pedido
        $pagoModel       = new PagoModel();
        $pedido['pagos'] = $pagoModel->getPagosPorPedido($idPedido);

        return $pedido;
    }

    /**
     * Lista pedidos de un cliente con paginación.
     *
     * @param int $idCliente
     * @param int $limite
     * @param int $offset
     * @return array
     */
    public function getPedidosPorCliente(int $idCliente, int $limite = 10, int $offset = 0): array
    {
        return $this->where('idCliente', $idCliente)
            ->orderBy('fechaRecepcion', 'DESC')
            ->findAll($limite, $offset);
    }

    // ---------------------------------------------------------------
    // Datos para Comprobante / Ticket Imprimible
    // ---------------------------------------------------------------

    /**
     * Genera el array estructurado de datos para el ticket físico o imprimible.
     * Incluye encabezado, detalles y saldo de pagos.
     *
     * @param int $idPedido
     * @return array
     * @throws \RuntimeException Si el pedido no existe.
     */
    public function getDatosTicket(int $idPedido): array
    {
        $pedido = $this->getPedidoCompleto($idPedido);

        if (! $pedido) {
            throw new \RuntimeException("Pedido #{$idPedido} no encontrado.");
        }

        $totalPagado = array_sum(array_column($pedido['pagos'], 'monto'));
        $saldoPendiente = (float) $pedido['total'] - $totalPagado;

        return [
            'encabezado' => [
                'empresa'        => 'LAVANDERÍA MARYCLEAN',
                'ticket'         => $pedido['codigoTicket'],
                'fecha_emision'  => date('d/m/Y H:i:s'),
                'fecha_recepcion'=> date('d/m/Y H:i', strtotime($pedido['fechaRecepcion'])),
                'fecha_entrega'  => $pedido['fechaEntrega']
                    ? date('d/m/Y H:i', strtotime($pedido['fechaEntrega']))
                    : 'Por definir',
            ],
            'cliente' => [
                'documento' => $pedido['documento'],
                'nombres'   => strtoupper($pedido['cliente']),
                'telefono'  => $pedido['telefonoCliente'] ?? '—',
            ],
            'empleado' => [
                'nombres' => $pedido['empleado'],
                'rol'     => $pedido['rolEmpleado'],
            ],
            'detalles'        => $pedido['detalles'],
            'total'           => number_format((float) $pedido['total'], 2),
            'total_pagado'    => number_format($totalPagado, 2),
            'saldo_pendiente' => number_format($saldoPendiente, 2),
            'estado'          => $pedido['estado'],
        ];
    }

    // ---------------------------------------------------------------
    // Generador de Código de Ticket
    // ---------------------------------------------------------------

    /**
     * Genera un código de ticket único con formato: MC-YYYYMMDD-XXXXX
     * Verifica que no exista en la BD antes de retornarlo.
     *
     * @return string
     */
    public function generarCodigoTicket(): string
    {
        do {
            $codigo = 'MC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        } while ($this->where('codigoTicket', $codigo)->countAllResults() > 0);

        return $codigo;
    }

    /**
     * Calcula el saldo pendiente de pago de un pedido.
     *
     * @param int $idPedido
     * @return float
     */
    public function getSaldoPendiente(int $idPedido): float
    {
        $pedido = $this->select('total')->find($idPedido);
        if (! $pedido) {
            return 0.0;
        }

        $totalPagado = $this->db->table('Pago')
            ->selectSum('monto')
            ->where('idPedido', $idPedido)
            ->get()
            ->getRowArray();

        $sumaPagos = (float) ($totalPagado['monto'] ?? 0);
        return max(0.0, (float) $pedido['total'] - $sumaPagos);
    }
}
