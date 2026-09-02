<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * SucursalModel
 *
 * Gestiona la tabla `Sucursal`.
 * Cada sucursal es el punto físico de operación de MaryClean.
 *
 * @package App\Models
 */
class SucursalModel extends Model
{
    protected $table            = 'Sucursal';
    protected $primaryKey       = 'idSucursal';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'nombre',
        'direccion',
        'telefono',
    ];

    // ---------------------------------------------------------------
    // Timestamps (la tabla no tiene columnas de timestamp nativas)
    // ---------------------------------------------------------------
    protected $useTimestamps = false;

    // ---------------------------------------------------------------
    // Reglas de Validación
    // ---------------------------------------------------------------
    protected $validationRules = [
        'nombre'    => 'required|min_length[3]|max_length[100]',
        'direccion' => 'required|min_length[5]|max_length[255]',
        'telefono'  => 'required|min_length[7]|max_length[15]|regex_match[/^\+?[0-9\s\-]+$/]',
    ];

    protected $validationMessages = [
        'nombre' => [
            'required'   => 'El nombre de la sucursal es obligatorio.',
            'min_length' => 'El nombre debe tener al menos 3 caracteres.',
            'max_length' => 'El nombre no puede superar los 100 caracteres.',
        ],
        'direccion' => [
            'required'   => 'La dirección de la sucursal es obligatoria.',
            'min_length' => 'La dirección debe tener al menos 5 caracteres.',
            'max_length' => 'La dirección no puede superar los 255 caracteres.',
        ],
        'telefono' => [
            'required'    => 'El teléfono de la sucursal es obligatorio.',
            'regex_match' => 'El teléfono solo puede contener números, espacios, guiones y el símbolo +.',
        ],
    ];

    protected $skipValidation = false;

    // ---------------------------------------------------------------
    // Métodos Personalizados
    // ---------------------------------------------------------------

    /**
     * Obtiene una sucursal junto con la cantidad de empleados asignados.
     *
     * @param int $idSucursal
     * @return array|null
     */
    public function getSucursalConEmpleados(int $idSucursal): ?array
    {
        return $this->db->table('Sucursal s')
            ->select('s.idSucursal, s.nombre, s.direccion, s.telefono, COUNT(e.idEmpleado) AS totalEmpleados')
            ->join('Empleado e', 'e.idSucursal = s.idSucursal', 'left')
            ->where('s.idSucursal', $idSucursal)
            ->groupBy('s.idSucursal, s.nombre, s.direccion, s.telefono')
            ->get()
            ->getRowArray();
    }

    /**
     * Lista todas las sucursales con conteo de empleados activos.
     *
     * @return array
     */
    public function listarConEmpleados(): array
    {
        return $this->db->table('Sucursal s')
            ->select('s.idSucursal, s.nombre, s.direccion, s.telefono, COUNT(e.idEmpleado) AS totalEmpleados')
            ->join('Empleado e', 'e.idSucursal = s.idSucursal', 'left')
            ->groupBy('s.idSucursal, s.nombre, s.direccion, s.telefono')
            ->orderBy('s.nombre', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Busca sucursales por nombre (búsqueda parcial insensible a mayúsculas).
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
}
