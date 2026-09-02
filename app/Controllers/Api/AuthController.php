<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\EmpleadoModel;
use App\Libraries\JwtHelper;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthController
 *
 * Controlador de autenticación JWT para la API REST de MaryClean.
 * Gestiona el ciclo de vida de tokens para clientes Node.js y Expo Go.
 *
 * ENDPOINTS:
 *   POST /api/v1/auth/login    → Emite un JWT a partir de credenciales
 *   GET  /api/v1/auth/me       → Retorna datos del empleado autenticado
 *   POST /api/v1/auth/refresh  → Renueva el token sin re-autenticar (TODO futuro)
 *
 * FLUJO DE AUTENTICACIÓN:
 * 1. Expo Go / Node.js → POST /api/v1/auth/login {username, password}
 * 2. API valida contra BD → emite JWT (exp: 1h por defecto)
 * 3. Cliente almacena token (SecureStore / HttpOnly cookie)
 * 4. Cada petición: Authorization: Bearer <token>
 * 5. JwtFilter valida el token antes de llegar al controlador
 *
 * THIN CONTROLLER:
 * Este controlador NO contiene lógica de hashing ni consultas SQL directas.
 * Delega a EmpleadoModel::autenticar() y JwtHelper::generarToken().
 *
 * @package App\Controllers\Api
 */
class AuthController extends BaseApiController
{
    private EmpleadoModel $empleadoModel;
    private JwtHelper     $jwt;

    public function __construct()
    {
        $this->empleadoModel = new EmpleadoModel();
        $this->jwt           = new JwtHelper();
    }

    // ---------------------------------------------------------------
    // POST /api/v1/auth/login
    // ---------------------------------------------------------------

    /**
     * Autentica un empleado y emite un JWT.
     *
     * REQUEST (JSON):
     * {
     *   "username": "emp0001",
     *   "password": "contraseña123"
     * }
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "message": "Bienvenido, María Torres.",
     *   "data": {
     *     "token":      "eyJ0eXAiOi...",
     *     "token_type": "Bearer",
     *     "expires_in": 3600,
     *     "empleado": {
     *       "id": 1, "nombres": "María Torres", "rol": "admin", "sucursal": "MaryClean Centro"
     *     }
     *   }
     * }
     *
     * @return ResponseInterface
     */
    public function login(): ResponseInterface
    {
        // Validar campos requeridos
        $rules = [
            'username' => 'required|min_length[4]|max_length[50]',
            'password' => 'required|min_length[6]',
        ];

        $body = $this->getJsonBody();

        if (! $this->validateData($body, $rules)) {
            return $this->respondValidationError($this->validator->getErrors());
        }

        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        // Delegar autenticación al modelo
        $empleado = $this->empleadoModel->autenticar($username, $password);

        if ($empleado === null) {
            return $this->respondError(
                'Credenciales incorrectas o cuenta desactivada. Verifique su usuario y contraseña.',
                ResponseInterface::HTTP_UNAUTHORIZED
            );
        }

        // Generar el token JWT
        $token = $this->jwt->generarToken($empleado);

        return $this->respondSuccess(
            [
                'token'      => $token,
                'token_type' => 'Bearer',
                'expires_in' => $this->jwt->getTtl(),
                'empleado'   => [
                    'id'       => (int) $empleado['idEmpleado'],
                    'nombres'  => $empleado['nombres'],
                    'rol'      => $empleado['rol'],
                    'sucursal' => $empleado['sucursal'] ?? '',
                ],
            ],
            "Bienvenido, {$empleado['nombres']}."
        );
    }

    // ---------------------------------------------------------------
    // GET /api/v1/auth/me  [Requiere JWT]
    // ---------------------------------------------------------------

    /**
     * Devuelve los datos del empleado autenticado según el payload del JWT.
     * Útil para que el cliente rehidrate la sesión sin re-autenticar.
     *
     * RESPONSE 200:
     * {
     *   "success": true,
     *   "data": {
     *     "id": 1, "nombres": "María Torres Quispe",
     *     "rol": "admin", "sucursal": "MaryClean Centro",
     *     "idSucursal": 1
     *   }
     * }
     *
     * @return ResponseInterface
     */
    public function me(): ResponseInterface
    {
        $payload = $this->getAuthPayload();

        if (empty($payload)) {
            return $this->respondError('Token inválido o no presente.', ResponseInterface::HTTP_UNAUTHORIZED);
        }

        // Refrescar datos desde BD (en caso de que el empleado fue actualizado)
        $empleado = $this->empleadoModel->getEmpleadoConSucursal((int) $payload['id']);

        if ($empleado === null) {
            return $this->respondNotFound('El empleado del token ya no existe en el sistema.');
        }

        return $this->respondSuccess([
            'id'         => (int) $empleado['idEmpleado'],
            'nombres'    => $empleado['nombres'],
            'username'   => $empleado['username'] ?? null,
            'rol'        => $empleado['rol'],
            'sucursal'   => $empleado['sucursal'] ?? null,
            'idSucursal' => (int) $empleado['idSucursal'],
        ]);
    }
}
