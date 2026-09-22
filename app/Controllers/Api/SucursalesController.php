<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\SucursalModel;
use CodeIgniter\HTTP\ResponseInterface;

class SucursalesController extends BaseApiController
{
    private SucursalModel $sucursalModel;

    public function __construct()
    {
        $this->sucursalModel = new SucursalModel();
    }

    /**
     * GET /api/v1/sucursales
     */
    public function index(): ResponseInterface
    {
        $sucursales = $this->sucursalModel->findAll();
        return $this->respondSuccess($sucursales);
    }

    /**
     * GET /api/v1/sucursales/{id}
     */
    public function show($id = null): ResponseInterface
    {
        $sucursal = $this->sucursalModel->find((int)$id);
        if (!$sucursal) {
            return $this->respondNotFound('Sucursal no encontrada.');
        }
        return $this->respondSuccess($sucursal);
    }

    /**
     * POST /api/v1/sucursales
     */
    public function create(): ResponseInterface
    {
        $rules = [
            'nombre'    => 'required|min_length[3]|max_length[100]',
            'direccion' => 'permit_empty|max_length[255]',
            'telefono'  => 'permit_empty|max_length[20]',
        ];

        $body = $this->getJsonBody();

        if (! $this->validateData($body, $rules)) {
            return $this->respondValidationError($this->validator->getErrors());
        }

        try {
            $id = $this->sucursalModel->insert($body);
            $sucursal = $this->sucursalModel->find($id);
            return $this->respondCreated($sucursal, 'Sucursal creada exitosamente.');
        } catch (\Exception $e) {
            return $this->respondError('Error interno al crear la sucursal.', 500);
        }
    }

    /**
     * PUT /api/v1/sucursales/{id}
     */
    public function update($id = null): ResponseInterface
    {
        $id = (int)$id;
        $sucursal = $this->sucursalModel->find($id);
        if (!$sucursal) {
            return $this->respondNotFound('Sucursal no encontrada.');
        }

        $rules = [
            'nombre'    => 'if_exist|required|min_length[3]|max_length[100]',
            'direccion' => 'if_exist|permit_empty|max_length[255]',
            'telefono'  => 'if_exist|permit_empty|max_length[20]',
        ];

        $body = $this->getJsonBody();

        if (! $this->validateData($body, $rules)) {
            return $this->respondValidationError($this->validator->getErrors());
        }

        $this->sucursalModel->update($id, $body);
        $sucursalActualizada = $this->sucursalModel->find($id);

        return $this->respondSuccess($sucursalActualizada, 'Sucursal actualizada exitosamente.');
    }
}
