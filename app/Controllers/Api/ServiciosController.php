<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ServicioPrendaModel;
use Exception;

/**
 * ServiciosController
 *
 * Gestiona el catálogo de prendas y servicios.
 * Endpoints protegidos por JWT.
 *
 * @package App\Controllers\Api
 */
class ServiciosController extends BaseApiController
{
    protected ServicioPrendaModel $prendaModel;

    public function __construct()
    {
        $this->prendaModel = new ServicioPrendaModel();
    }

    /**
     * GET /api/v1/servicios/prendas
     * Devuelve el listado completo de todas las prendas.
     * Incluye join con la tabla Servicio para el nombre del servicio padre.
     */
    public function indexPrendas()
    {
        try {
            $prendas = $this->prendaModel->getPrendasCompletas();
            return $this->respondSuccess($prendas, 'Catálogo de prendas obtenido correctamente.');
        } catch (Exception $e) {
            return $this->handleDbException($e, 'Error al obtener el catálogo de prendas.');
        }
    }

    /**
     * POST /api/v1/servicios/prendas
     * Crea una nueva prenda en el catálogo.
     */
    public function createPrenda()
    {
        try {
            $body = $this->getJsonBody();

            if (empty($body)) {
                return $this->respondError('No se enviaron datos.', 400);
            }

            // Sanitizar datos básicos
            $data = [
                'nombrePrenda' => trim($body['nombrePrenda'] ?? ''),
                'precio'       => isset($body['precio']) ? (float) $body['precio'] : null,
                'idServicio'   => isset($body['idServicio']) ? (int) $body['idServicio'] : null,
            ];

            if ($this->prendaModel->insert($data) === false) {
                return $this->respondValidationError($this->prendaModel->errors());
            }

            $idPrenda = $this->prendaModel->getInsertID();
            $nuevaPrenda = $this->prendaModel->getPrendaConServicio((int) $idPrenda);

            return $this->respondCreated($nuevaPrenda, 'Prenda creada exitosamente.');
        } catch (Exception $e) {
            return $this->handleDbException($e, 'Error al crear la prenda.');
        }
    }

    /**
     * PUT /api/v1/servicios/prendas/{id}
     * Actualiza el precio o el estado de una prenda existente.
     */
    public function updatePrenda($id = null)
    {
        try {
            if ($id === null) {
                return $this->respondError('ID de prenda no proporcionado.', 400);
            }

            $id = (int) $id;
            $prendaExistente = $this->prendaModel->find($id);

            if (!$prendaExistente) {
                return $this->respondNotFound('Prenda no encontrada.');
            }

            $body = $this->getJsonBody();
            if (empty($body)) {
                return $this->respondError('No se enviaron datos para actualizar.', 400);
            }

            $updateData = [];

            if (isset($body['precio'])) {
                $precio = (float) $body['precio'];
                if ($precio <= 0) {
                    return $this->respondValidationError(['precio' => 'El precio debe ser mayor a 0.']);
                }
                $updateData['precio'] = $precio;
            }

            if (isset($body['estado'])) {
                $estado = (int) $body['estado'];
                if (!in_array($estado, [0, 1], true)) {
                    return $this->respondValidationError(['estado' => 'El estado debe ser 0 o 1.']);
                }
                $updateData['estado'] = $estado;
            }

            if (isset($body['nombrePrenda'])) {
                $nombre = trim($body['nombrePrenda']);
                if (strlen($nombre) < 2) {
                    return $this->respondValidationError(['nombrePrenda' => 'El nombre debe tener al menos 2 caracteres.']);
                }
                $updateData['nombrePrenda'] = $nombre;
            }

            if (empty($updateData)) {
                return $this->respondError('No se enviaron campos válidos para actualizar (precio, estado o nombre).', 400);
            }

            // Saltar validación del modelo para updates parciales (ya validamos arriba)
            $this->prendaModel->skipValidation(true);

            if ($this->prendaModel->update($id, $updateData) === false) {
                return $this->respondError('Error al actualizar la prenda en la base de datos.', 500);
            }

            $prendaActualizada = $this->prendaModel->getPrendaConServicio($id);

            return $this->respondSuccess($prendaActualizada, 'Prenda actualizada correctamente.');
        } catch (Exception $e) {
            return $this->handleDbException($e, 'Error al actualizar la prenda.');
        }
    }
}
