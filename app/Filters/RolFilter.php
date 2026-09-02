<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

/**
 * RolFilter
 *
 * Filtro de Control de Acceso Basado en Roles (RBAC) para MaryClean.
 * Verifica que el empleado autenticado tenga el rol necesario para
 * acceder a la ruta solicitada.
 *
 * ROLES DEL SISTEMA:
 * - 'admin'         : Acceso total. Gestión de BD, reportes, empleados, sucursales.
 * - 'cajero'        : Acceso a cobros, cierre de caja, reportes de ingresos.
 * - 'recepcionista' : Acceso a registro de pedidos, clientes y consulta de estados.
 *
 * JERARQUÍA DE ROLES:
 * admin > cajero > recepcionista
 * (admin puede hacer todo lo de cajero y recepcionista)
 *
 * USO EN RUTAS (app/Config/Routes.php):
 *   $routes->group('admin', ['filter' => 'rol:admin'], function($routes) { ... });
 *   $routes->group('caja',  ['filter' => 'rol:admin,cajero'], function($routes) { ... });
 *
 * REGISTRO: app/Config/Filters.php → 'rol' => RolFilter::class
 *
 * @package App\Filters
 */
class RolFilter implements FilterInterface
{
    /**
     * Mapa de jerarquía de roles: cada rol incluye los permisos de los inferiores.
     * Permite que 'admin' pase filtros definidos para 'cajero' y 'recepcionista'.
     */
    private const JERARQUIA = [
        'admin'          => ['admin', 'cajero', 'recepcionista'],
        'cajero'         => ['cajero', 'recepcionista'],
        'recepcionista'  => ['recepcionista'],
    ];

    /**
     * Verifica que el rol del empleado autenticado coincida con los roles
     * requeridos por la ruta (pasados como argumentos al filtro).
     *
     * @param RequestInterface $request
     * @param array|null       $arguments Roles permitidos. Ej: ['admin', 'cajero']
     * @return mixed
     */
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $session       = session();
        $rolEmpleado   = $session->get('empleado_rol');
        $rolesRequeridos = $arguments ?? [];

        // Si no se especifican roles en el filtro, solo se requiere estar autenticado
        if (empty($rolesRequeridos)) {
            return null;
        }

        // Verificar si el rol del empleado tiene permiso (con jerarquía aplicada)
        if ($rolEmpleado !== null && $this->tienePermiso($rolEmpleado, $rolesRequeridos)) {
            return null; // Acceso permitido
        }

        // Acceso denegado
        $nombreEmpleado = $session->get('empleado_nombre') ?? 'Usuario';
        $session->setFlashdata(
            'error_auth',
            "Acceso denegado. '{$nombreEmpleado}' no tiene permisos suficientes para esta sección."
        );

        // Petición AJAX/API
        if ($request->hasHeader('Accept') && str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON([
                    'success'      => false,
                    'mensaje'      => 'Acceso denegado. Permisos insuficientes.',
                    'codigo_error' => 'FORBIDDEN',
                    'rol_actual'   => $rolEmpleado,
                    'roles_requeridos' => $rolesRequeridos,
                ]);
        }

        return redirect()->to(base_url('dashboard'))->with('error_auth', 'Acceso denegado.');
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

    /**
     * Verifica si un rol tiene permiso para acceder a una ruta que requiere
     * alguno de los roles listados en $rolesRequeridos.
     * Aplica la jerarquía: admin puede acceder a rutas de cajero y recepcionista.
     *
     * @param string   $rolEmpleado     Rol del empleado autenticado
     * @param string[] $rolesRequeridos Roles permitidos en la ruta
     * @return bool
     */
    private function tienePermiso(string $rolEmpleado, array $rolesRequeridos): bool
    {
        $rolesQueAbarca = self::JERARQUIA[$rolEmpleado] ?? [$rolEmpleado];

        foreach ($rolesRequeridos as $rolRequerido) {
            if (in_array($rolRequerido, $rolesQueAbarca, true)) {
                return true;
            }
        }

        return false;
    }
}
