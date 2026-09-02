<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * DetallePedidoModel
 *
 * Gestiona la tabla `DetallePedido`.
 *
 * TRIGGERS ASOCIADOS (automáticos en MySQL, sin intervención de PHP):
 * - `trg_detalle_insertar_total`: Suma el importe al total del pedido al insertar.
 * - `trg_detalle_actualizar_total`: Recalcula el total al modificar cantidad/importe.
 * - `trg_detalle_eliminar_total`: Resta el importe al total al eliminar una línea.
 *
 * El campo `importe` es calculado desde PHP (cantidad × precio_prenda) antes de
 * insertar, ya que el trigger usa el importe almacenado para acumular en `Pedido.total`.
 *
 * CHECK CONSTRAINT en BD: cantidad > 0 e importe > 0.
 *
 * @package App\Models
 */
class DetallePedidoModel extends Model
{
    protected $table            = 'DetallePedido';
    protected $primaryKey       = 'idDetalle';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'cantidad',
        'descripcion',
        'importe',
        'idPedido',
        'idPrenda',
    ];

    protected $useTimestamps = false;

    // ---------------------------------------------------------------
    // Reglas de Validación
    // ---------------------------------------------------------------
    protected $validationRules = [
        'cantidad'    => 'required|integer|greater_than[0]',
        'descripcion' => 'permit_empty|max_length[255]',
        'importe'     => 'required|decimal|greater_than[0]',
        'idPedido'    => 'required|integer|is_not_unique[Pedido.idPedido]',
        'idPrenda'    => 'required|integer|is_not_unique[ServicioPrenda.idPrenda]',
    ];

    protected $validationMessages = [
        'cantidad' => [
            'required'     => 'La cantidad es obligatoria.',
            'integer'      => 'La cantidad debe ser un número entero.',
            'greater_than' => 'La cantidad debe ser mayor a 0.',
        ],
        'importe' => [
            'required'     => 'El importe es obligatorio.',
            'decimal'      => 'El importe debe ser un número decimal válido.',
            'greater_than' => 'El importe debe ser mayor a 0.',
        ],
        'idPedido' => [
            'required'      => 'El pedido asociado es obligatorio.',
            'is_not_unique' => 'El pedido especificado no existe.',
        ],
        'idPrenda' => [
            'required'      => 'La prenda es obligatoria.',
            'is_not_unique' => 'La prenda especificada no existe.',
        ],
    ];

    protected $skipValidation = false;

    // ---------------------------------------------------------------
    // Métodos Personalizados
    // ---------------------------------------------------------------

    /**
     * Inserta un detalle calculando el importe automáticamente desde PHP
     * (cantidad × precio unitario de la prenda), reforzando el CHECK de la BD.
     *
     * @param int    $idPedido
     * @param int    $idPrenda
     * @param int    $cantidad
     * @param string $descripcion Descripción opcional (color, talla, etc.)
     * @return int|bool ID del nuevo detalle o false en caso de error
     */
    public function insertarDetalle(
        int $idPedido,
        int $idPrenda,
        int $cantidad,
        string $descripcion = ''
    ): int|bool {
        $prendaModel = new ServicioPrendaModel();
        $precio      = $prendaModel->getPrecio($idPrenda);

        if ($precio === null || $precio <= 0) {
            return false;
        }

        $importe = round($cantidad * $precio, 2);

        $data = [
            'cantidad'    => $cantidad,
            'descripcion' => $descripcion,
            'importe'     => $importe,
            'idPedido'    => $idPedido,
            'idPrenda'    => $idPrenda,
        ];

        return $this->insert($data, true);
    }

    /**
     * Obtiene todos los detalles de un pedido con información de la prenda y servicio.
     *
     * @param int $idPedido
     * @return array
     */
    public function getDetallesPorPedido(int $idPedido): array
    {
        return $this->db->table('DetallePedido dp')
            ->select(
                'dp.idDetalle, dp.cantidad, dp.descripcion, dp.importe, ' .
                'sp.idPrenda, sp.nombrePrenda, sp.precio AS precioUnitario, ' .
                's.nombre AS servicio'
            )
            ->join('ServicioPrenda sp', 'sp.idPrenda = dp.idPrenda', 'inner')
            ->join('Servicio s', 's.idServicio = sp.idServicio', 'inner')
            ->where('dp.idPedido', $idPedido)
            ->orderBy('dp.idDetalle', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Elimina todos los detalles de un pedido.
     * Los triggers ajustarán el total del pedido automáticamente.
     *
     * @param int $idPedido
     * @return bool
     */
    public function eliminarDetallesPorPedido(int $idPedido): bool
    {
        return $this->db->table($this->table)
            ->where('idPedido', $idPedido)
            ->delete();
    }

    /**
     * Calcula el subtotal de un pedido sumando los importes de sus detalles
     * (útil para validaciones en PHP antes de consultar la BD).
     *
     * @param int $idPedido
     * @return float
     */
    public function calcularSubtotal(int $idPedido): float
    {
        $resultado = $this->db->table($this->table)
            ->selectSum('importe')
            ->where('idPedido', $idPedido)
            ->get()
            ->getRowArray();

        return (float) ($resultado['importe'] ?? 0);
    }

    /**
     * Devuelve los detalles formateados para el ticket imprimible.
     *
     * @param int $idPedido
     * @return array
     */
    public function getDetallesParaTicket(int $idPedido): array
    {
        $detalles = $this->getDetallesPorPedido($idPedido);

        return array_map(function (array $d) {
            return [
                'servicio'       => $d['servicio'],
                'prenda'         => $d['nombrePrenda'],
                'cantidad'       => (int) $d['cantidad'],
                'precio_unit'    => number_format((float) $d['precioUnitario'], 2),
                'importe'        => number_format((float) $d['importe'], 2),
                'descripcion'    => $d['descripcion'] ?: '—',
            ];
        }, $detalles);
    }
}
