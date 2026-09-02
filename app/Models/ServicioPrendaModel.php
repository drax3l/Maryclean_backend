<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ServicioPrendaModel
 *
 * Gestiona la tabla `ServicioPrenda`.
 * Cada prenda pertenece a un servicio y tiene un precio unitario.
 * El CHECK CONSTRAINT en BD impone precio > 0.
 *
 * @package App\Models
 */
class ServicioPrendaModel extends Model
{
    protected $table            = 'ServicioPrenda';
    protected $primaryKey       = 'idPrenda';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'nombrePrenda',
        'precio',
        'idServicio',
    ];

    protected $useTimestamps = false;

    // ---------------------------------------------------------------
    // Reglas de Validación
    // ---------------------------------------------------------------
    protected $validationRules = [
        'nombrePrenda' => 'required|min_length[2]|max_length[100]',
        'precio'       => 'required|decimal|greater_than[0]',
        'idServicio'   => 'required|integer|is_not_unique[Servicio.idServicio]',
    ];

    protected $validationMessages = [
        'nombrePrenda' => [
            'required'   => 'El nombre de la prenda es obligatorio.',
            'min_length' => 'El nombre debe tener al menos 2 caracteres.',
            'max_length' => 'El nombre no puede superar los 100 caracteres.',
        ],
        'precio' => [
            'required'     => 'El precio de la prenda es obligatorio.',
            'decimal'      => 'El precio debe ser un número decimal válido.',
            'greater_than' => 'El precio debe ser mayor a 0.',
        ],
        'idServicio' => [
            'required'      => 'El servicio al que pertenece la prenda es obligatorio.',
            'integer'       => 'El ID de servicio debe ser un número entero.',
            'is_not_unique' => 'El servicio especificado no existe en la base de datos.',
        ],
    ];

    protected $skipValidation = false;

    // ---------------------------------------------------------------
    // Métodos Personalizados
    // ---------------------------------------------------------------

    /**
     * Devuelve todas las prendas pertenecientes a un servicio.
     *
     * @param int $idServicio
     * @return array
     */
    public function getPrendasPorServicio(int $idServicio): array
    {
        return $this->where('idServicio', $idServicio)
            ->orderBy('nombrePrenda', 'ASC')
            ->findAll();
    }

    /**
     * Devuelve una prenda con la información de su servicio padre.
     *
     * @param int $idPrenda
     * @return array|null
     */
    public function getPrendaConServicio(int $idPrenda): ?array
    {
        return $this->db->table('ServicioPrenda sp')
            ->select('sp.idPrenda, sp.nombrePrenda, sp.precio, s.idServicio, s.nombre AS servicio, s.tiempoEstimado')
            ->join('Servicio s', 's.idServicio = sp.idServicio', 'inner')
            ->where('sp.idPrenda', $idPrenda)
            ->get()
            ->getRowArray();
    }

    /**
     * Lista prendas de todos los servicios para la selección en el formulario de pedido.
     * Incluye el nombre del servicio para agrupar en la UI.
     *
     * @return array
     */
    public function getCatalogoParaPedido(): array
    {
        return $this->db->table('ServicioPrenda sp')
            ->select('sp.idPrenda, sp.nombrePrenda, sp.precio, s.nombre AS servicio')
            ->join('Servicio s', 's.idServicio = sp.idServicio', 'inner')
            ->orderBy('s.nombre, sp.nombrePrenda', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Verifica si un precio es mayor a 0 antes de insertar (refuerzo en PHP
     * además del CHECK CONSTRAINT de MySQL).
     *
     * @param float $precio
     * @return bool
     */
    public function esPrecioValido(float $precio): bool
    {
        return $precio > 0;
    }

    /**
     * Devuelve el precio de una prenda específica.
     *
     * @param int $idPrenda
     * @return float|null
     */
    public function getPrecio(int $idPrenda): ?float
    {
        $prenda = $this->select('precio')->find($idPrenda);
        return $prenda ? (float) $prenda['precio'] : null;
    }
}
