<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\EmpleadoModel;
use CodeIgniter\HTTP\ResponseInterface;

class EmpleadosController extends BaseApiController
{
    private EmpleadoModel $empleadoModel;

    public function __construct()
    {
        $this->empleadoModel = new EmpleadoModel();
    }

    /**
     * GET /api/v1/empleados
     * Lista todos los empleados. Admite filtro `?activo=1` o `?activo=0`.
     */
    public function index(): ResponseInterface
    {
        $activo = $this->request->getGet('activo');
        
        $builder = $this->empleadoModel->select('idEmpleado, nombres, username, rol, activo, idSucursal, created_at');
        
        if ($activo !== null) {
            $builder->where('activo', (int)$activo);
        }
        
        $empleados = $builder->orderBy('nombres', 'ASC')->findAll();
        
        return $this->respondSuccess($empleados);
    }

    /**
     * POST /api/v1/empleados
     * Crea un nuevo empleado.
     */
    public function create(): ResponseInterface
    {
        $rules = [
            'username'   => 'required|min_length[4]|is_unique[Empleado.username]',
            'nombres'    => 'required|min_length[3]|max_length[100]',
            'password'   => 'required|min_length[6]',
            'rol'        => 'required|in_list[admin,cajero,recepcionista]',
            'idSucursal' => 'required|integer|is_not_unique[Sucursal.idSucursal]'
        ];

        $body = $this->getJsonBody();

        if (! $this->validateData($body, $rules)) {
            return $this->respondValidationError($this->validator->getErrors());
        }

        // Hashear password
        $body['password'] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $body['activo']   = 1;

        try {
            $id = $this->empleadoModel->insert($body);
            
            $empleado = $this->empleadoModel->select('idEmpleado, nombres, username, rol, idSucursal')->find($id);
            return $this->respondCreated($empleado, 'Empleado creado exitosamente.');
        } catch (\Exception $e) {
            return $this->respondError('Error interno al crear el empleado.', 500);
        }
    }

    /**
     * PATCH /api/v1/empleados/{id}/estado
     * Cambia el estado de un empleado (Baja lógica / Reactivación).
     * Body: { "activo": 0 } o { "activo": 1 }
     */
    public function cambiarEstado($id = null): ResponseInterface
    {
        $id = (int)$id;
        
        // Regla Inmunidad: No se puede desactivar al id = 1
        if ($id === 1) {
            return $this->respondError('Operación denegada. No se puede modificar el estado del Administrador Principal.', 403);
        }

        $body = $this->getJsonBody();
        if (!isset($body['activo'])) {
            return $this->respondValidationError(['activo' => 'El campo activo es requerido (1 o 0).']);
        }

        $empleado = $this->empleadoModel->find($id);
        if (!$empleado) {
            return $this->respondNotFound('Empleado no encontrado.');
        }

        $activo = (int) $body['activo'] === 1 ? 1 : 0;
        
        $this->empleadoModel->update($id, ['activo' => $activo]);
        
        $mensaje = $activo === 1 ? 'Empleado reactivado.' : 'Empleado desactivado (Baja lógica).';

        return $this->respondSuccess(['idEmpleado' => $id, 'activo' => $activo], $mensaje);
    }

    /**
     * PATCH /api/v1/empleados/{id}/password
     * Cambia la contraseña de un empleado.
     * Body: { "password": "newpassword" }
     */
    public function cambiarPassword($id = null): ResponseInterface
    {
        $id = (int)$id;

        $body = $this->getJsonBody();
        if (!isset($body['password']) || strlen($body['password']) < 6) {
            return $this->respondValidationError(['password' => 'La contraseña es requerida y debe tener al menos 6 caracteres.']);
        }

        $empleado = $this->empleadoModel->find($id);
        if (!$empleado) {
            return $this->respondNotFound('Empleado no encontrado.');
        }

        $this->empleadoModel->setPassword($id, $body['password']);

        return $this->respondSuccess(null, 'Contraseña actualizada correctamente.');
    }

    /**
     * DELETE /api/v1/empleados/{id}
     * Por si el frontend insiste en llamar a DELETE. Lo mapeamos internamente a una Baja Lógica.
     */
    public function delete($id = null): ResponseInterface
    {
        $id = (int)$id;
        
        if ($id === 1) {
            return $this->respondError('Operación denegada. No se puede eliminar al Administrador Principal.', 403);
        }

        $empleado = $this->empleadoModel->find($id);
        if (!$empleado) {
            return $this->respondNotFound('Empleado no encontrado.');
        }

        $this->empleadoModel->update($id, ['activo' => 0]);

        return $this->respondSuccess(null, 'Empleado desactivado correctamente (Baja lógica aplicada).');
    }
}
