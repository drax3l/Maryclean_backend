<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

/**
 * AuthFilter
 *
 * Filtro de autenticación de sesión para MaryClean.
 * Verifica que el usuario tenga una sesión activa antes de acceder
 * a cualquier ruta protegida del sistema.
 *
 * REGISTRO: app/Config/Filters.php → 'auth' => AuthFilter::class
 *
 * DATOS DE SESIÓN REQUERIDOS:
 * - 'empleado_id'  : ID del empleado autenticado
 * - 'empleado_rol' : Rol del empleado ('admin', 'cajero', 'recepcionista')
 * - 'empleado_nombre': Nombre del empleado para la UI
 * - 'sucursal_id'  : ID de la sucursal asignada
 *
 * @package App\Filters
 */
class AuthFilter implements FilterInterface
{
    /**
     * Verifica la sesión activa del empleado.
     * Redirige a la pantalla de login si no está autenticado.
     *
     * @param RequestInterface $request
     * @param array|null       $arguments
     * @return mixed
     */
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $session = session();

        // Verificar que existan los datos mínimos de sesión
        if (
            ! $session->has('empleado_id') ||
            ! $session->has('empleado_rol') ||
            empty($session->get('empleado_id'))
        ) {
            // Guardar la URL original para redirigir después del login
            $session->set('url_previa', current_url());
            $session->setFlashdata('error_auth', 'Debe iniciar sesión para acceder al sistema.');

            // Determinar si la petición espera JSON (API/AJAX)
            if ($request->hasHeader('Accept') && str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON([
                        'success' => false,
                        'mensaje' => 'No autorizado. Sesión no iniciada.',
                        'codigo_error' => 'UNAUTHENTICATED',
                    ]);
            }

            return redirect()->to(base_url('login'));
        }

        // Regenerar el ID de sesión periódicamente para prevenir session fixation
        if (! $session->has('session_generada')) {
            $session->regenerate(false);
            $session->set('session_generada', time());
        } elseif ((time() - $session->get('session_generada')) > 1800) {
            // Regenerar cada 30 minutos
            $session->regenerate(false);
            $session->set('session_generada', time());
        }

        return null; // Continuar con la solicitud
    }

    /**
     * No se requiere procesamiento posterior.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param array|null        $arguments
     * @return mixed
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
