<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use App\Libraries\DbExceptionHandler;

/**
 * BaseApiController
 *
 * Controlador base para todos los endpoints REST de MaryClean API.
 * Proporciona el formato de respuesta JSON unificado requerido por
 * los clientes Node.js (Frontend Web) y Expo Go (App Móvil).
 *
 * FORMATO DE RESPUESTA UNIFICADO:
 * {
 *   "success": boolean,
 *   "status":  int (HTTP status code),
 *   "message": string,
 *   "data":    object|array|null,
 *   "errors":  object|null
 * }
 *
 * PRINCIPIO THIN CONTROLLER:
 * Este controlador NO contiene SQL, Query Builder ni lógica de negocio.
 * Solo define los helpers de respuesta y el manejo centralizado de excepciones.
 *
 * @package App\Controllers\Api
 */
abstract class BaseApiController extends ResourceController
{
    use DbExceptionHandler;

    /**
     * Formato de respuesta por defecto.
     * ResourceController requiere esta propiedad para el método respond().
     */
    protected $format = 'json';

    // ---------------------------------------------------------------
    // Helpers de Respuesta JSON Estandarizada
    // ---------------------------------------------------------------

    /**
     * Devuelve una respuesta HTTP exitosa con el formato unificado.
     *
     * @param mixed  $data    Datos a devolver al cliente
     * @param string $message Mensaje descriptivo del resultado
     * @param int    $code    Código HTTP (200, 201, etc.)
     * @return ResponseInterface
     */
    protected function respondSuccess(
        mixed $data = null,
        string $message = 'Operación exitosa.',
        int $code = ResponseInterface::HTTP_OK
    ): ResponseInterface {
        return $this->response
            ->setStatusCode($code)
            ->setJSON([
                'success' => true,
                'status'  => $code,
                'message' => $message,
                'data'    => $data,
                'errors'  => null,
            ]);
    }

    /**
     * Devuelve una respuesta HTTP de error con el formato unificado.
     *
     * @param string     $message Mensaje de error legible para el usuario
     * @param int        $code    Código HTTP (400, 401, 403, 404, 422, 500)
     * @param array|null $errors  Mapa de errores de validación { campo: [mensajes] }
     * @return ResponseInterface
     */
    protected function respondError(
        string $message = 'Ha ocurrido un error.',
        int $code = ResponseInterface::HTTP_BAD_REQUEST,
        ?array $errors = null
    ): ResponseInterface {
        return $this->response
            ->setStatusCode($code)
            ->setJSON([
                'success' => false,
                'status'  => $code,
                'message' => $message,
                'data'    => null,
                'errors'  => $errors,
            ]);
    }

    /**
     * Devuelve una respuesta HTTP 201 Created con el formato unificado.
     * Usado tras crear un recurso vía POST.
     *
     * @param mixed  $data
     * @param string $message
     * @return ResponseInterface
     */
    protected function respondCreated(mixed $data = null, string $message = 'Recurso creado exitosamente.'): ResponseInterface
    {
        return $this->respondSuccess($data, $message, ResponseInterface::HTTP_CREATED);
    }

    /**
     * Devuelve una respuesta HTTP 404 Not Found.
     *
     * @param string $mensaje
     * @return ResponseInterface
     */
    protected function respondNotFound(string $mensaje = 'Recurso no encontrado.'): ResponseInterface
    {
        return $this->respondError($mensaje, ResponseInterface::HTTP_NOT_FOUND);
    }

    /**
     * Devuelve una respuesta HTTP 422 Unprocessable Entity para errores de validación.
     *
     * @param array  $errors  Errores del validador de CI4
     * @param string $message
     * @return ResponseInterface
     */
    protected function respondValidationError(
        array $errors,
        string $message = 'Los datos enviados no son válidos.'
    ): ResponseInterface {
        return $this->response
            ->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)
            ->setJSON([
                'success' => false,
                'status'  => ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,
                'message' => $message,
                'data'    => null,
                'errors'  => $errors,
            ]);
    }

    // ---------------------------------------------------------------
    // Manejo Centralizado de Excepciones de BD
    // ---------------------------------------------------------------

    /**
     * Procesa una DatabaseException de forma centralizada.
     * Convierte el SQLSTATE '45000' (sobrepago) en HTTP 422.
     * Convierte violaciones de FK/CHECK en HTTP 400.
     * Registra errores internos y devuelve HTTP 500.
     *
     * @param DatabaseException $e
     * @param int               $idPedido  Para calcular saldo en errores de sobrepago
     * @return ResponseInterface
     */
    protected function handleDbException(DatabaseException $e, int $idPedido = 0): ResponseInterface
    {
        $payload = $this->excepcionAArray($e, $idPedido);

        $codigoHttp = match ($payload['codigo_error']) {
            'SOBREPAGO_TRIGGER_45000'     => ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, // 422
            'CHECK_CONSTRAINT_VIOLATION'  => ResponseInterface::HTTP_BAD_REQUEST,          // 400
            'FOREIGN_KEY_VIOLATION'       => ResponseInterface::HTTP_BAD_REQUEST,          // 400
            'DUPLICATE_ENTRY'             => ResponseInterface::HTTP_CONFLICT,             // 409
            default                       => ResponseInterface::HTTP_INTERNAL_SERVER_ERROR, // 500
        };

        return $this->response
            ->setStatusCode($codigoHttp)
            ->setJSON([
                'success'      => false,
                'status'       => $codigoHttp,
                'message'      => $payload['mensaje'],
                'data'         => null,
                'errors'       => ['codigo_error' => $payload['codigo_error']],
            ]);
    }

    // ---------------------------------------------------------------
    // Helpers de Petición
    // ---------------------------------------------------------------

    /**
     * Obtiene el cuerpo JSON de la petición como array.
     * Compatible con Content-Type: application/json (Node.js, Expo Go).
     *
     * @return array
     */
    protected function getJsonBody(): array
    {
        $body = $this->request->getJSON(true);
        return is_array($body) ? $body : [];
    }

    /**
     * Obtiene los datos del empleado autenticado del token JWT
     * (inyectados por JwtFilter en los atributos de la petición).
     *
     * @return array{id: int, rol: string, sucursal_id: int, nombres: string}
     */
    protected function getAuthPayload(): array
    {
        return $this->request->jwtPayload ?? [];
    }

    /**
     * Verifica que el empleado autenticado tiene uno de los roles indicados.
     * Aplica la jerarquía: admin puede todo, cajero puede cajero+recepcionista, etc.
     *
     * @param string ...$rolesRequeridos
     * @return bool
     */
    protected function tieneRol(string ...$rolesRequeridos): bool
    {
        $jerarquia = [
            'admin'         => ['admin', 'cajero', 'recepcionista'],
            'cajero'        => ['cajero', 'recepcionista'],
            'recepcionista' => ['recepcionista'],
        ];

        $payload = $this->getAuthPayload();
        $rolActual = $payload['rol'] ?? '';
        $rolesQueCubre = $jerarquia[$rolActual] ?? [];

        foreach ($rolesRequeridos as $rolReq) {
            if (in_array($rolReq, $rolesQueCubre, true)) {
                return true;
            }
        }

        return false;
    }
}
