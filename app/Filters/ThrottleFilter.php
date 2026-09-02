<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * ThrottleFilter
 *
 * Filtro nativo para protección contra fuerza bruta (Rate Limiting).
 * Utiliza el servicio Throttler de CodeIgniter 4.
 *
 * @package App\Filters
 */
class ThrottleFilter implements FilterInterface
{
    /**
     * Limita las peticiones a un endpoint específico.
     * Por defecto: 4 intentos por minuto por IP.
     *
     * @param RequestInterface $request
     * @param array|null       $arguments
     * @return mixed
     */
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $throttler = Services::throttler();

        // Limitar a 4 peticiones por minuto basándose en la IP
        // Se puede ajustar pasando argumentos en la ruta (ej: throttler:10,60)
        $limite   = isset($arguments[0]) ? (int) $arguments[0] : 4;
        $segundos = isset($arguments[1]) ? (int) $arguments[1] : 60;

        // Se usa la IP del cliente como llave para el throttling
        $ip = $request->getIPAddress();

        if ($throttler->check($ip, $limite, $segundos) === false) {
            return Services::response()
                ->setStatusCode(ResponseInterface::HTTP_TOO_MANY_REQUESTS)
                ->setJSON([
                    'success' => false,
                    'status'  => 429,
                    'message' => 'Demasiados intentos fallidos. Por favor, intente de nuevo más tarde.',
                    'data'    => null,
                    'errors'  => ['auth' => 'RATE_LIMIT_EXCEEDED'],
                ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
