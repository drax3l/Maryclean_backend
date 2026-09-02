<?php

declare(strict_types=1);

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use CodeIgniter\HTTP\RequestInterface;

/**
 * JwtHelper
 *
 * Librería para generación y validación de tokens JWT.
 * Compatible con clientes Node.js (Frontend Web) y Expo Go (App Móvil).
 *
 * INSTALACIÓN:
 *   composer require firebase/php-jwt
 *
 * CONFIGURACIÓN (.env):
 *   jwt.secret   = tu_clave_secreta_de_minimo_32_caracteres
 *   jwt.ttl      = 3600    (tiempo de vida en segundos, 1 hora por defecto)
 *   jwt.issuer   = maryclean-api
 *
 * PAYLOAD DEL TOKEN:
 * {
 *   "iss": "maryclean-api",
 *   "iat": <timestamp emisión>,
 *   "exp": <timestamp expiración>,
 *   "id":       int,    ← idEmpleado
 *   "rol":      string, ← 'admin' | 'cajero' | 'recepcionista'
 *   "nombres":  string,
 *   "sucursal": int     ← idSucursal
 * }
 *
 * INTEGRACIÓN CON EXPO GO:
 * El token se almacena en SecureStore y se envía en cada petición:
 *   Authorization: Bearer <token>
 *
 * INTEGRACIÓN CON NODE.JS:
 * El token se almacena en localStorage/cookies HttpOnly y se envía:
 *   Authorization: Bearer <token>
 *
 * @package App\Libraries
 */
class JwtHelper
{
    private string $secret;
    private int    $ttl;
    private string $issuer;
    private string $algorithm = 'HS256';

    public function __construct()
    {
        $this->secret  = env('jwt.secret', 'maryclean_secret_key_change_in_production_32chars');
        // REGLA 2: Tiempo de expiración dinámico (default: 28800 segundos / 8 horas)
        $this->ttl     = (int) env('jwt.ttl', 28800);
        $this->issuer  = env('jwt.issuer', 'maryclean-api');
    }

    // ---------------------------------------------------------------
    // Generación de Token
    // ---------------------------------------------------------------

    /**
     * Genera un JWT firmado con los datos del empleado autenticado.
     *
     * @param array $empleado Fila de la tabla Empleado con datos de la sucursal
     * @return string El token JWT firmado
     */
    public function generarToken(array $empleado): string
    {
        $ahora = time();

        $payload = [
            'iss'      => $this->issuer,
            'iat'      => $ahora,
            'exp'      => $ahora + $this->ttl,
            'id'       => (int) $empleado['idEmpleado'],
            'rol'      => $empleado['rol'],
            'nombres'  => $empleado['nombres'],
            'sucursal' => (int) $empleado['idSucursal'],
        ];

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    // ---------------------------------------------------------------
    // Validación de Token
    // ---------------------------------------------------------------

    /**
     * Valida y decodifica un token JWT.
     * Devuelve el payload como array o lanza excepción si el token es inválido.
     *
     * @param string $token
     * @return array El payload decodificado del token
     * @throws \RuntimeException Si el token es inválido, expirado o mal firmado
     */
    public function validarToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
            return (array) $decoded;
        } catch (ExpiredException $e) {
            throw new \RuntimeException('El token JWT ha expirado. Por favor, inicia sesión nuevamente.', 401);
        } catch (SignatureInvalidException $e) {
            throw new \RuntimeException('La firma del token JWT es inválida.', 401);
        } catch (\UnexpectedValueException $e) {
            throw new \RuntimeException('Token JWT malformado o inválido.', 401);
        } catch (\Exception $e) {
            throw new \RuntimeException('Error al procesar el token de autenticación.', 401);
        }
    }

    // ---------------------------------------------------------------
    // Extracción del Token desde la Petición HTTP
    // ---------------------------------------------------------------

    /**
     * Extrae el token Bearer del header Authorization de la petición.
     * Compatible con peticiones de Node.js y Expo Go (fetch/axios).
     *
     * @param RequestInterface $request
     * @return string|null El token limpio sin el prefijo "Bearer ", o null si no existe
     */
    public function extraerTokenDeRequest(RequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return null;
        }

        // Verificar formato: "Bearer <token>"
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authHeader, 7));
        return empty($token) ? null : $token;
    }

    /**
     * Obtiene el tiempo de expiración configurado (en segundos).
     *
     * @return int
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }
}
