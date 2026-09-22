<?php

namespace App\Exceptions;

use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
use Config\Exceptions as ExceptionsConfig;
use Config\Services;

class ApiExceptionHandler implements ExceptionHandlerInterface
{
    private ExceptionHandler $defaultHandler;

    public function __construct(ExceptionsConfig $config)
    {
        $this->defaultHandler = new ExceptionHandler($config);
    }

    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode
    ): void {
        $uri = $request->getUri()->getPath();

        // Si NO es una ruta API, usar el manejador por defecto de CI4
        if (!preg_match('#^/?(index\.php/)?api/v1/#i', $uri)) {
            $this->defaultHandler->handle($exception, $request, $response, $statusCode, $exitCode);
            return;
        }

        // --- MANEJO DE EXCEPCIONES PARA LA API ---
        $isDevelopment = ENVIRONMENT === 'development';

        $data = [
            'success' => false,
            'status'  => $statusCode >= 100 && $statusCode < 600 ? $statusCode : 500,
            'message' => $isDevelopment ? $exception->getMessage() : 'Error Interno del Servidor.',
            'data'    => null,
            'errors'  => null,
        ];

        if ($isDevelopment && $statusCode !== 404) {
            $data['errors'] = [
                'type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        // Si es 404, ocultar el mensaje por defecto (que a veces es vacío)
        if ($statusCode === 404) {
            $data['message'] = 'Endpoint no encontrado en la API de MaryClean.';
        }

        $response->setStatusCode($data['status'])
                 ->setJSON($data)
                 ->send();

        // Evitar que el DebugToolbar inyecte su volcado JSON en modo desarrollo al hacer exit
        $_SERVER['CI_DEBUG'] = '0';
        exit($exitCode);
    }
}
