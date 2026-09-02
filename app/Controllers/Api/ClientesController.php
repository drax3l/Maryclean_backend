<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ClienteModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ClientesController
 *
 * API REST para gestión de clientes de MaryClean.
 * Sirve datos al módulo de recepción del Frontend Web y la App Móvil.
 *
 * ENDPOINTS:
 *   GET    /api/v1/clientes                      → index()   [auth]
 *   GET    /api/v1/clientes/{id}                 → show()    [auth]
 *   GET    /api/v1/clientes/documento/{doc}      → buscarPorDocumento() [auth]
 *   POST   /api/v1/clientes                      → create()  [auth]
 *   PUT    /api/v1/clientes/{id}                 → update()  [auth]
 *
 * THIN CONTROLLER:
 * Toda la lógica de búsqueda, validación y persistencia está en ClienteModel.
 * Este controlador solo coordina la recepción HTTP → Modelo → Respuesta JSON.
 *
 * @package App\Controllers\Api
 */
class ClientesController extends BaseApiController
{
    private ClienteModel $clienteModel;

    public function __construct()
    {
        $this->clienteModel = new ClienteModel();
    }

    // ---------------------------------------------------------------
    // GET /api/v1/clientes?q=<busqueda>&page=<n>&per_page=<n>
    // ---------------------------------------------------------------

    /**
     * Lista clientes con búsqueda opcional y paginación.
     *
     * QUERY PARAMS:
     *   q        → Término de búsqueda (nombre, documento o teléfono)
     *   page     → Página actual (default: 1)
     *   per_page → Registros por página (default: 15, max: 50)
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "clientes": [...],
     *     "paginacion": { "total": 120, "page": 1, "per_page": 15, "total_pages": 8 }
     *   }
     * }
     *
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        $q       = $this->request->getGet('q') ?? '';
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(50, max(1, (int) ($this->request->getGet('per_page') ?? 15)));
        $offset  = ($page - 1) * $perPage;

        if (! empty($q)) {
            $clientes = $this->clienteModel->buscarCliente($q);
            $total    = count($clientes);
            // Paginación manual para búsqueda
            $clientes = array_slice($clientes, $offset, $perPage);
        } else {
            $total    = $this->clienteModel->countAllResults(false);
            $clientes = $this->clienteModel
                ->orderBy('nombres', 'ASC')
                ->findAll($perPage, $offset);
        }

        return $this->respondSuccess([
            'clientes'  => $clientes,
            'paginacion' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // GET /api/v1/clientes/{id}
    // ---------------------------------------------------------------

    /**
     * Obtiene un cliente por su ID primario.
     *
     * @param int|string|null $id
     * @return ResponseInterface
     */
    public function show($id = null): ResponseInterface
    {
        $cliente = $this->clienteModel->find((int) $id);

        if ($cliente === null) {
            return $this->respondNotFound("Cliente con ID {$id} no encontrado.");
        }

        return $this->respondSuccess($cliente);
    }

    // ---------------------------------------------------------------
    // GET /api/v1/clientes/documento/{doc}
    // ---------------------------------------------------------------

    /**
     * Búsqueda rápida de cliente por número de documento (DNI/RUC).
     * Usado en el módulo de recepción: el recepcionista busca al cliente
     * antes de registrar un pedido.
     *
     * RESPONSE 200 (encontrado):
     * { "success": true, "data": { "idCliente": 1, "nombres": "...", ... } }
     *
     * RESPONSE 404 (no encontrado):
     * { "success": false, "message": "Cliente no encontrado con ese documento." }
     *
     * @param string|null $doc Número de documento
     * @return ResponseInterface
     */
    public function buscarPorDocumento(?string $doc = null): ResponseInterface
    {
        if (empty($doc)) {
            return $this->respondError('El número de documento es requerido.', ResponseInterface::HTTP_BAD_REQUEST);
        }

        $cliente = $this->clienteModel->getClientePorDocumento($doc);

        if ($cliente === null) {
            return $this->respondNotFound("No se encontró ningún cliente con el documento '{$doc}'.");
        }

        return $this->respondSuccess($cliente, 'Cliente encontrado.');
    }

    // ---------------------------------------------------------------
    // POST /api/v1/clientes
    // ---------------------------------------------------------------

    /**
     * Registra un nuevo cliente.
     *
     * REQUEST (JSON):
     * {
     *   "documento":  "12345678",
     *   "nombres":    "Juan Pablo Mendoza García",
     *   "telefono":   "987654321",
     *   "direccion":  "Jr. Los Pinos 234, Miraflores"  (opcional)
     * }
     *
     * RESPONSE 201:
     * { "success": true, "data": { "idCliente": 6, "documento": "12345678", ... } }
     *
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        $body = $this->getJsonBody();

        // Delegar validación al modelo
        if (! $this->clienteModel->validate($body)) {
            return $this->respondValidationError($this->clienteModel->errors());
        }

        $idCliente = $this->clienteModel->insert($body, true);

        if ($idCliente === false) {
            return $this->respondError('Error al registrar el cliente. Intente nuevamente.');
        }

        $cliente = $this->clienteModel->find((int) $idCliente);

        return $this->respondCreated($cliente, 'Cliente registrado exitosamente.');
    }

    // ---------------------------------------------------------------
    // PUT /api/v1/clientes/{id}
    // ---------------------------------------------------------------

    /**
     * Actualiza los datos de un cliente existente.
     * Solo actualiza los campos enviados en el body (actualización parcial).
     *
     * REQUEST (JSON):
     * { "telefono": "999888777", "direccion": "Nueva dirección" }
     *
     * @param int|string|null $id
     * @return ResponseInterface
     */
    public function update($id = null): ResponseInterface
    {
        $cliente = $this->clienteModel->find((int) $id);

        if ($cliente === null) {
            return $this->respondNotFound("Cliente con ID {$id} no encontrado.");
        }

        $body = $this->getJsonBody();

        if (empty($body)) {
            return $this->respondError('No se enviaron datos para actualizar.', ResponseInterface::HTTP_BAD_REQUEST);
        }

        // Inyectar el id para que la validación is_unique lo ignore (propio registro)
        $body['idCliente'] = (int) $id;

        if (! $this->clienteModel->update((int) $id, $body)) {
            $errores = $this->clienteModel->errors();
            if (! empty($errores)) {
                return $this->respondValidationError($errores);
            }
            return $this->respondError('Error al actualizar el cliente.');
        }

        $clienteActualizado = $this->clienteModel->find((int) $id);

        return $this->respondSuccess($clienteActualizado, 'Cliente actualizado exitosamente.');
    }
}
