<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use App\Libraries\JwtHelper;

/**
 * JwtFilter
 *
 * Filtro de autenticación JWT para los endpoints de la API REST de MaryClean.
 * Valida el token Bearer enviado en el header Authorization de cada petición.
 *
 * CLIENTES SOPORTADOS:
 * - Frontend Web Node.js: token almacenado en cookie HttpOnly o localStorage.
 * - App Móvil Expo Go: token almacenado en SecureStore.
 *
 * FLUJO:
 * 1. Extrae el token del header: Authorization: Bearer <token>
 * 2. Valida la firma y la expiración con JwtHelper.
 * 3. Inyecta el payload decodificado en $request->jwtPayload.
 * 4. Si el token es inválido/expirado → HTTP 401 JSON (no redirige a /login).
 *
 * DIFERENCIA CON AuthFilter:
 * - AuthFilter: para rutas web con sesión PHP (recepcionista en navegador).
 * - JwtFilter:  para rutas API sin estado (Node.js, Expo Go).
 *
 * REGISTRO: app/Config/Filters.php → 'jwt' => JwtFilter::class
 *
 * USO EN RUTAS:
 *   $routes->group('api/v1', ['filter' => 'cors,jwt'], function($routes) { ... });
 *
 * @package App\Filters
 */
class JwtFilter implements FilterInterface
{
    /**
     * Valida el token JWT antes de que el controlador procese la petición.
     *
     * @param RequestInterface $request
     * @param array|null       $arguments Roles requeridos (opcional): ['admin', 'cajero']
     * @return mixed
     */
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $jwt = new JwtHelper();

        // 1. Extraer el token del header Authorization
        $token = $jwt->extraerTokenDeRequest($request);

        if ($token === null) {
            return service('response')
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON([
                    'success'      => false,
                    'status'       => 401,
                    'message'      => 'Token de autenticación no proporcionado. Incluya el header: Authorization: Bearer <token>',
                    'data'         => null,
                    'errors'       => ['auth' => 'TOKEN_MISSING'],
                ]);
        }

        try {
            // 2. Validar y decodificar el token
            $payload = $jwt->validarToken($token);

            // 3. Inyectar el payload en la petición para uso en los controladores
            //    Accesible como: $this->request->jwtPayload
            $request->jwtPayload = $payload;

            // 4. Verificar rol si se pasaron argumentos al filtro
            //    Uso: ['filter' => 'jwt:admin'] o ['filter' => 'jwt:admin,cajero']
            if (! empty($arguments)) {
                $rolActual = $payload['rol'] ?? '';

                $jerarquia = [
                    'admin'         => ['admin', 'cajero', 'recepcionista'],
                    'cajero'        => ['cajero', 'recepcionista'],
                    'recepcionista' => ['recepcionista'],
                ];

                $rolesQueCubre  = $jerarquia[$rolActual] ?? [];
                $tienePermiso   = false;

                foreach ($arguments as $rolRequerido) {
                    if (in_array($rolRequerido, $rolesQueCubre, true)) {
                        $tienePermiso = true;
                        break;
                    }
                }

                if (! $tienePermiso) {
                    return service('response')
                        ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
                        ->setJSON([
                            'success'  => false,
                            'status'   => 403,
                            'message'  => "Acceso denegado. El rol '{$rolActual}' no tiene permisos para este recurso.",
                            'data'     => null,
                            'errors'   => [
                                'auth'             => 'FORBIDDEN',
                                'rol_actual'       => $rolActual,
                                'roles_requeridos' => $arguments,
                            ],
                        ]);
                }
            }

            return null; // Acceso permitido

        } catch (\RuntimeException $e) {
            $httpCode = (int) $e->getCode() ?: 401;

            return service('response')
                ->setStatusCode($httpCode)
                ->setJSON([
                    'success' => false,
                    'status'  => $httpCode,
                    'message' => $e->getMessage(),
                    'data'    => null,
                    'errors'  => ['auth' => 'TOKEN_INVALID'],
                ]);
        }
    }

    /**
     * No se requiere procesamiento posterior.
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
