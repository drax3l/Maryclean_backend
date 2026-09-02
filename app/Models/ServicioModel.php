<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ServicioModel
 *
 * Gestiona la tabla `Servicio`.
 * Un servicio representa el tipo de trabajo (ej: Lavado, Planchado, Lavado en seco).
 * Cada servicio contiene múltiples prendas (`ServicioPrenda`) con su precio individual.
 *
 * @package App\Models
 */
class ServicioModel extends Model
{
    protected $table            = 'Servicio';
    protected $primaryKey       = 'idServicio';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'nombre',
        'tiempoEstimado',
    ];

    protected $useTimestamps = false;

    // ---------------------------------------------------------------
    // Reglas de Validación
    // ---------------------------------------------------------------
    protected $validationRules = [
        'nombre'         => 'required|min_length[3]|max_length[100]',
        'tiempoEstimado' => 'required|integer|greater_than[0]',
    ];

    protected $validationMessages = [
        'nombre' => [
            'required'   => 'El nombre del servicio es obligatorio.',
            'min_length' => 'El nombre debe tener al menos 3 caracteres.',
            'max_length' => 'El nombre no puede superar los 100 caracteres.',
        ],
        'tiempoEstimado' => [
            'required'     => 'El tiempo estimado es obligatorio.',
            'integer'      => 'El tiempo estimado debe ser un número entero (en horas o minutos).',
            'greater_than' => 'El tiempo estimado debe ser mayor a 0.',
        ],
    ];

    protected $skipValidation = false;

    // ---------------------------------------------------------------
    // Métodos Personalizados
    // ---------------------------------------------------------------

    /**
     * Devuelve todos los servicios con sus prendas y precios anidados.
     * Útil para el catálogo de servicios del mostrador.
     *
     * @return array
     */
    public function getCatalogoCompleto(): array
    {
        $servicios = $this->orderBy('nombre', 'ASC')->findAll();
        $prendaModel = new ServicioPrendaModel();

        foreach ($servicios as &$servicio) {
            $servicio['prendas'] = $prendaModel->getPrendasPorServicio($servicio['idServicio']);
        }

        return $servicios;
    }

    /**
     * Devuelve un servicio con su lista de prendas incluida.
     *
     * @param int $idServicio
     * @return array|null
     */
    public function getServicioConPrendas(int $idServicio): ?array
    {
        $servicio = $this->find($idServicio);

        if (! $servicio) {
            return null;
        }

        $prendaModel          = new ServicioPrendaModel();
        $servicio['prendas']  = $prendaModel->getPrendasPorServicio($idServicio);

        return $servicio;
    }

    /**
     * Busca servicios por nombre parcial.
     *
     * @param string $termino
     * @return array
     */
    public function buscarPorNombre(string $termino): array
    {
        return $this->like('nombre', $termino, 'both')
            ->orderBy('nombre', 'ASC')
            ->findAll();
    }

    /**
     * Devuelve la lista de servicios como pares id => nombre
     * para poblar dropdowns en formularios.
     *
     * @return array
     */
    public function getListaDropdown(): array
    {
        $servicios = $this->select('idServicio, nombre')->findAll();
        $lista     = [];

        foreach ($servicios as $s) {
            $lista[$s['idServicio']] = $s['nombre'];
        }

        return $lista;
    }
}
