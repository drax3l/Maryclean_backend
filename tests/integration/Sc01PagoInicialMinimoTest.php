<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Libraries\JwtHelper;
use App\Models\EmpleadoModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * SC-01
 *
 * Política configurable de porcentaje mínimo
 * para pagos iniciales.
 */
class Sc01PagoInicialMinimoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $seed = 'App\Database\Seeds\LavanderiaSeeder';
    protected $namespace = 'App';

    protected function tearDown(): void
    {
        // Restaurar política por defecto después de cada prueba.
        config('Pedidos')->porcentajePagoInicialMinimo = 20.0;

        parent::tearDown();
    }

    private function generarToken(): string
    {
        $empleadoModel = new EmpleadoModel();

        $empleado = $empleadoModel->getEmpleadoConSucursal(3);

        $this->assertNotNull($empleado);

        return (new JwtHelper())
            ->generarToken($empleado);
    }

    private function registrarPedido(
        ?float $pagoInicial = null
    ) {
        $payload = [
            'idCliente' => 1,

            'detalles' => [
                [
                    'idPrenda'    => 1,
                    'cantidad'    => 1,
                    'descripcion' => 'Prueba SC-01',
                ],
            ],
        ];

        if ($pagoInicial !== null) {
            $payload['pagoInicial'] = [
                'monto'  => $pagoInicial,
                'metodo' => 'Efectivo',
            ];
        }

        return $this
            ->withHeaders([
                'Authorization' =>
                    'Bearer ' . $this->generarToken(),

                'Content-Type' =>
                    'application/json',

                'Accept' =>
                    'application/json',
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
     * T-01
     * Con política 0 %, se mantiene el comportamiento anterior.
     */
    public function testPoliticaCeroPermitePagoInicialPequeno(): void
    {
        config('Pedidos')->porcentajePagoInicialMinimo = 0.0;

        $response = $this->registrarPedido(0.50);

        $response->assertStatus(201);
    }

    /**
     * T-02
     * El pago inicial sigue siendo opcional.
     */
    public function testPoliticaVeintePermitePedidoSinPagoInicial(): void
    {
        config('Pedidos')->porcentajePagoInicialMinimo = 20.0;

        $response = $this->registrarPedido();

        $response->assertStatus(201);
    }

    /**
     * T-03
     * Pedido S/ 5.00.
     * 20 % = S/ 1.00.
     * S/ 0.99 debe rechazarse.
     */
    public function testPoliticaVeinteRechazaMontoMenorAlMinimo(): void
    {
        config('Pedidos')->porcentajePagoInicialMinimo = 20.0;

        $response = $this->registrarPedido(0.99);

        $response->assertStatus(422);

        $json = json_decode(
            $response->getJSON(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            'PAGO_INICIAL_INSUFICIENTE',
            $json['errors']['codigo_error']
        );
    }

    /**
     * T-04
     * Exactamente 20 % debe ser aceptado.
     */
    public function testPoliticaVeinteAceptaMontoExacto(): void
    {
        config('Pedidos')->porcentajePagoInicialMinimo = 20.0;

        $response = $this->registrarPedido(1.00);

        $response->assertStatus(201);
    }

    /**
     * T-05
     * SC-01 no debe romper la protección de INC-01.
     */
    public function testSobrepagoSigueSiendoRechazado(): void
    {
        config('Pedidos')->porcentajePagoInicialMinimo = 20.0;

        $response = $this->registrarPedido(20.00);

        $response->assertStatus(422);

        $json = json_decode(
            $response->getJSON(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertContains(
            $json['errors']['codigo_error'],
            [
                'SOBREPAGO',
                'SOBREPAGO_TRIGGER_45000',
            ]
        );
    }
}