<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Libraries\JwtHelper;
use App\Models\EmpleadoModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * INC-01
 *
 * Un pedido NO debe quedar registrado cuando el pago inicial
 * solicitado es inválido por superar el total del pedido.
 */
class Inc01PagoInicialRollbackTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $seed = 'App\Database\Seeds\LavanderiaSeeder';
    protected $namespace = 'App';

    /**
     * Genera un JWT válido para el recepcionista de pruebas.
     */
    private function generarTokenRecepcionista(): string
    {
        $empleadoModel = new EmpleadoModel();

        $empleado = $empleadoModel->getEmpleadoConSucursal(3);

        $this->assertNotNull(
            $empleado,
            'Debe existir el empleado de pruebas con idEmpleado = 3.'
        );

        $jwt = new JwtHelper();

        return $jwt->generarToken($empleado);
    }

    /**
     * Ejecuta el escenario INC-01:
     *
     * Pedido:
     * - Cliente 1
     * - 1 Camisa
     * - Total S/ 5.00
     *
     * Pago inicial solicitado:
     * - S/ 20.00
     *
     * El pago inicial supera el total del pedido.
     */
    private function enviarPedidoConSobrepago()
    {
        $token = $this->generarTokenRecepcionista();

        $payload = [
            'idCliente' => 1,

            'detalles' => [
                [
                    'idPrenda'    => 1,
                    'cantidad'    => 1,
                    'descripcion' => 'Prueba PHPUnit INC-01',
                ],
            ],

            'pagoInicial' => [
                'monto'  => 20.00,
                'metodo' => 'Efectivo',
            ],
        ];

        return $this
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])
            ->withBody(
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                )
            )
            ->post('api/v1/pedidos');
    }

    /**
     * @test
     *
     * El API debe rechazar completamente el pedido
     * cuando el pago inicial supera el total.
     */
    public function testPagoInicialMayorAlTotalDebeRechazarPedido(): void
    {
        $response = $this->enviarPedidoConSobrepago();

        $json = json_decode(
            $response->getJSON(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse(
            (bool) ($json['success'] ?? true),
            'INC-01: el pedido no debe responder success=true cuando el pago inicial supera el total.'
        );
    }

    /**
     * @test
     *
     * Si el pago inicial es rechazado, el pedido tampoco
     * debe permanecer almacenado en la base de datos.
     */
    public function testPagoInicialMayorAlTotalNoDebePersistirPedido(): void
    {
        $db = db_connect();

        $cantidadAntes = $db
            ->table('Pedido')
            ->countAllResults();

        $this->enviarPedidoConSobrepago();

        $cantidadDespues = $db
            ->table('Pedido')
            ->countAllResults();

        $this->assertSame(
            $cantidadAntes,
            $cantidadDespues,
            'INC-01: el pedido fue persistido aunque el pago inicial fue rechazado.'
        );
    }
}