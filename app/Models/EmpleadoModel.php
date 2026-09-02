<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * EmpleadoModel
 *
 * Gestiona la tabla `Empleado`.
 * Los empleados tienen un rol que determina sus permisos en el sistema (RBAC).
 * Roles válidos: 'admin', 'cajero', 'recepcionista'.
 *
 * @package App\Models
 */
class EmpleadoModel extends Model
{
    protected $table            = 'Empleado';
    protected $primaryKey       = 'idEmpleado';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'nombres',
        'username',
        'password',
        'activo',
        'rol',
        'idSucursal',
    ];

    protected $useTimestamps = false;

    /**
     * Roles válidos del sistema. Refleja la lógica RBAC de los filtros.
     */
    public const ROLES_VALIDOS = ['admin', 'cajero', 'recepcionista'];

    // ---------------------------------------------------------------
    // Reglas de Validación
    // ---------------------------------------------------------------
    protected $validationRules = [
        'nombres'    => 'required|min_length[3]|max_length[150]',
        'username'   => 'permit_empty|min_length[4]|max_length[50]|is_unique[Empleado.username,idEmpleado,{idEmpleado}]',
        'rol'        => 'required|in_list[admin,cajero,recepcionista]',
        'idSucursal' => 'required|integer|is_not_unique[Sucursal.idSucursal]',
    ];

    protected $validationMessages = [
        'nombres' => [
            'required'   => 'El nombre del empleado es obligatorio.',
            'min_length' => 'El nombre debe tener al menos 3 caracteres.',
            'max_length' => 'El nombre no puede superar los 150 caracteres.',
        ],
        'username' => [
            'min_length' => 'El username debe tener al menos 4 caracteres.',
            'max_length' => 'El username no puede superar los 50 caracteres.',
            'is_unique'  => 'Ese username ya está en uso.',
        ],
        'rol' => [
            'required' => 'El rol del empleado es obligatorio.',
            'in_list'  => 'El rol debe ser uno de: admin, cajero, recepcionista.',
        ],
        'idSucursal' => [
            'required'      => 'La sucursal asignada es obligatoria.',
            'integer'       => 'El ID de sucursal debe ser un número entero.',
            'is_not_unique' => 'La sucursal especificada no existe en la base de datos.',
        ],
    ];

    protected $skipValidation = false;

    // ---------------------------------------------------------------
    // Métodos Personalizados
    // ---------------------------------------------------------------

    /**
     * Obtiene un empleado con los datos de su sucursal.
     *
     * @param int $idEmpleado
     * @return array|null
     */
    public function getEmpleadoConSucursal(int $idEmpleado): ?array
    {
        return $this->db->table('Empleado e')
            ->select('e.idEmpleado, e.nombres, e.rol, s.idSucursal, s.nombre AS sucursal, s.telefono AS telefonoSucursal')
            ->join('Sucursal s', 's.idSucursal = e.idSucursal', 'inner')
            ->where('e.idEmpleado', $idEmpleado)
            ->get()
            ->getRowArray();
    }

    /**
     * Lista todos los empleados de una sucursal específica.
     *
     * @param int $idSucursal
     * @return array
     */
    public function getEmpleadosPorSucursal(int $idSucursal): array
    {
        return $this->where('idSucursal', $idSucursal)
            ->orderBy('nombres', 'ASC')
            ->findAll();
    }

    /**
     * Lista todos los empleados de una sucursal filtrados por rol.
     *
     * @param int    $idSucursal
     * @param string $rol
     * @return array
     */
    public function getEmpleadosPorRol(int $idSucursal, string $rol): array
    {
        return $this->where('idSucursal', $idSucursal)
            ->where('rol', $rol)
            ->orderBy('nombres', 'ASC')
            ->findAll();
    }

    /**
     * Busca un empleado por nombre (búsqueda parcial).
     *
     * @param string $termino
     * @return array
     */
    public function buscarPorNombre(string $termino): array
    {
        return $this->like('nombres', $termino, 'both')
            ->orderBy('nombres', 'ASC')
            ->findAll();
    }

    /**
     * Devuelve el listado completo de empleados con su sucursal (para vistas administrativas).
     *
     * @return array
     */
    public function listarTodosConSucursal(): array
    {
        return $this->db->table('Empleado e')
            ->select('e.idEmpleado, e.nombres, e.rol, s.nombre AS sucursal')
            ->join('Sucursal s', 's.idSucursal = e.idSucursal', 'inner')
            ->orderBy('s.nombre, e.nombres', 'ASC')
            ->get()
            ->getResultArray();
    }

    // ---------------------------------------------------------------
    // Autenticación JWT
    // ---------------------------------------------------------------

    /**
     * Autentica un empleado verificando username y password con bcrypt.
     * Devuelve los datos del empleado con su sucursal si las credenciales son válidas.
     *
     * @param string $username
     * @param string $password Contraseña en texto plano (se compara con hash bcrypt)
     * @return array|null  Datos del empleado con sucursal, o null si falla la autenticación
     */
    public function autenticar(string $username, string $password): ?array
    {
        $empleado = $this->db->table('Empleado e')
            ->select('e.idEmpleado, e.nombres, e.username, e.password, e.activo, e.rol, e.idSucursal, s.nombre AS sucursal')
            ->join('Sucursal s', 's.idSucursal = e.idSucursal', 'inner')
            ->where('e.username', $username)
            ->get()
            ->getRowArray();

        if ($empleado === null) {
            return null;
        }

        if (empty($empleado['password'])) {
            return null;
        }

        // Verificar hash bcrypt
        if (! password_verify($password, $empleado['password'])) {
            return null;
        }

        // REGLA 1: Verificar explícitamente que el empleado esté activo
        if ((int) $empleado['activo'] !== 1) {
            return null; // Rechaza la autenticación inmediatamente
        }

        // No exponer el hash en la respuesta
        unset($empleado['password']);

        return $empleado;
    }

    /**
     * Establece o actualiza el password de un empleado (hash bcrypt automático).
     * Usa PASSWORD_BCRYPT con cost=12 para balance seguridad/rendimiento.
     *
     * @param int    $idEmpleado
     * @param string $password Contraseña en texto plano
     * @return bool
     */
    public function setPassword(int $idEmpleado, string $password): bool
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        return $this->db->table($this->table)
            ->where('idEmpleado', $idEmpleado)
            ->update(['password' => $hash]);
    }

    /**
     * Obtiene el empleado con su sucursal, incluyendo username (sin password).
     * Sobrescribe el método heredado para incluir el JOIN con Sucursal.
     *
     * @param int $idEmpleado
     * @return array|null
     */
    public function getEmpleadoConSucursal(int $idEmpleado): ?array
    {
        return $this->db->table('Empleado e')
            ->select('e.idEmpleado, e.nombres, e.username, e.activo, e.rol, s.idSucursal, s.nombre AS sucursal, s.telefono AS telefonoSucursal')
            ->join('Sucursal s', 's.idSucursal = e.idSucursal', 'inner')
            ->where('e.idEmpleado', $idEmpleado)
            ->get()
            ->getRowArray();
    }
}
