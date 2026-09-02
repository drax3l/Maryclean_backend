<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

/**
 * CorsFilter
 *
 * Filtro CORS (Cross-Origin Resource Sharing) para la API REST de MaryClean.
 * Habilita el acceso desde los clientes desacoplados:
 *   - Frontend Web Node.js (ej: http://localhost:3000 en desarrollo)
 *   - App Móvil Expo Go (peticiones desde dispositivo físico o emulador)
 *
 * PREFLIGHT (OPTIONS):
 * Los clientes modernos envían una petición OPTIONS antes del POST/PUT real.
 * Este filtro responde a esas peticiones con los headers correctos y HTTP 204.
 *
 * CONFIGURACIÓN EN .env:
 *   cors.allowedOrigins = http://localhost:3000,http://localhost:8081,exp://192.168.1.1:8081
 *   cors.allowedMethods = GET,POST,PUT,PATCH,DELETE,OPTIONS
 *
 * REGISTRO: app/Config/Filters.php → 'cors' => CorsFilter::class
 *
 * @package App\Filters
 */
class CorsFilter implements FilterInterface
{
    /**
     * Orígenes permitidos. Configurable via .env para cada entorno.
     * Wildcards no recomendados en producción.
     */
    private array $allowedOrigins;

    /**
     * Métodos HTTP permitidos.
     */
    private string $allowedMethods;

    /**
     * Headers que el cliente puede enviar.
     */
    private string $allowedHeaders;

    /**
     * Headers que el cliente puede leer de la respuesta.
     */
    private string $exposedHeaders;

    /**
     * Tiempo de cache para la respuesta preflight (segundos).
     */
    private int $maxAge;

    public function __construct()
    {
        // Cargar orígenes desde .env o usar defaults de desarrollo
        $origensRaw = env('cors.allowedOrigins', 'http://localhost:3000,http://localhost:8081');
        $this->allowedOrigins = array_map('trim', explode(',', $origensRaw));

        $this->allowedMethods = env('cors.allowedMethods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $this->allowedHeaders = 'Authorization, Content-Type, Accept, X-Requested-With, X-CSRF-TOKEN';
        $this->exposedHeaders = 'X-Total-Count, X-Page, X-Per-Page';
        $this->maxAge         = 86400; // 24 horas
    }

    /**
     * Agrega headers CORS a la petición y responde a preflights OPTIONS.
     *
     * @param RequestInterface $request
     * @param array|null       $arguments
     * @return mixed
     */
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $origin = $request->getHeaderLine('Origin');

        // Verificar si el origen es permitido
        $origenPermitido = $this->esOrigenPermitido($origin);

        // Responder a peticiones OPTIONS (preflight)
        if ($request->getMethod() === 'options') {
            $response = service('response');
            $response->setStatusCode(204); // No Content

            if ($origenPermitido) {
                $response->setHeader('Access-Control-Allow-Origin', $origin);
                $response->setHeader('Vary', 'Origin');
            }

            $response->setHeader('Access-Control-Allow-Methods', $this->allowedMethods);
            $response->setHeader('Access-Control-Allow-Headers', $this->allowedHeaders);
            $response->setHeader('Access-Control-Max-Age', (string) $this->maxAge);
            $response->setHeader('Access-Control-Allow-Credentials', 'true');

            return $response;
        }

        // Para peticiones no-OPTIONS, los headers se agregan en after()
        return null;
    }

    /**
     * Agrega headers CORS a todas las respuestas de la API.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param array|null        $arguments
     * @return ResponseInterface
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        $origin = $request->getHeaderLine('Origin');

        if ($this->esOrigenPermitido($origin)) {
            $response->setHeader('Access-Control-Allow-Origin', $origin);
            $response->setHeader('Vary', 'Origin');
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
            $response->setHeader('Access-Control-Expose-Headers', $this->exposedHeaders);
        }

        // Siempre agregar el Content-Type JSON para las respuestas de la API
        if (! $response->hasHeader('Content-Type')) {
            $response->setHeader('Content-Type', 'application/json; charset=UTF-8');
        }

        return $response;
    }

    /**
     * Verifica si el origen de la petición está en la lista permitida.
     * Soporta wildcards de subdominio: '*.maryclean.com'.
     *
     * @param string $origin
     * @return bool
     */
    private function esOrigenPermitido(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        // Permitir todos los orígenes solo en desarrollo
        if (ENVIRONMENT === 'development' && in_array('*', $this->allowedOrigins, true)) {
            return true;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }
}
