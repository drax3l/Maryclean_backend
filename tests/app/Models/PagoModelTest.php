<?php

declare(strict_types=1);

namespace Tests\App\Models;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Models\PagoModel;
use App\Models\PedidoModel;

/**
 * PagoModelTest
 *
 * Suite de pruebas para PagoModel con énfasis en:
 * - Captura del error SQLSTATE '45000' del trigger de sobrepago
 * - Validaciones de monto (> 0, no supera saldo)
 * - Cambio automático a 'Pagado' tras pago completo (trigger)
 * - Formato del recibo de pago presencial
 *
 * ENTORNO: lavanderia_test
 *
 * @package Tests\App\Models
 */
class PagoModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed      = 'App\Database\Seeds\LavanderiaSeeder';
    protected $namespace = 'App';

    protected PagoModel  $pagoModel;
    protected PedidoModel $pedidoModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pagoModel   = new PagoModel();
        $this->pedidoModel = new PedidoModel();
    }

    // ---------------------------------------------------------------
    // Tests: Registro de Pago Válido
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que un pago válido se registra correctamente.
     * Pedido 1: total 27.00, sin pagos previos → abonar 10.00 debe ser válido.
     */
    public function testRegistrarPagoValido(): void
    {
        // Pedido 1 tiene total = 15+12 = 27.00, sin pagos previos
        $resultado = $this->pagoModel->registrarPago(1, 10.00, 'Efectivo');

        $this->assertTrue($resultado['success'], 'Un pago válido debe registrarse con éxito.');
        $this->assertIsInt($resultado['idPago']);
        $this->assertGreaterThan(0, $resultado['idPago']);
        $this->assertNull($resultado['codigo_error']);
    }

    /**
     * @test
     * Verifica que todos los métodos de pago presenciales son válidos.
     */
    public function testRegistrarPagoConDiferentesMetodos(): void
    {
        foreach (PagoModel::METODOS_PAGO as $metodo) {
            // Usar monto pequeño para no agotar el saldo del pedido 1
            $resultado = $this->pagoModel->registrarPago(1, 0.50, $metodo);
            $this->assertTrue($resultado['success'], "El método de pago '{$metodo}' debe ser válido.");
        }
    }

    // ---------------------------------------------------------------
    // Tests: Bloqueo de Sobrepago (SQLSTATE 45000)
    // ---------------------------------------------------------------

    /**
     * @test
     * ESCENARIO CRÍTICO: Verifica que el trigger bloquea el sobrepago.
     * Pedido 2: total=53.00, pagado=20.00, saldo=33.00.
     * Intentar pagar 50.00 debe ser rechazado por el trigger.
     */
    public function testSobrepagoBloqueadoPorTrigger(): void
    {
        // Pedido 2: saldo pendiente = 33.00. Intentamos pagar 50.00
        $resultado = $this->pagoModel->registrarPago(2, 50.00, 'Efectivo');

        $this->assertFalse($resultado['success'], 'El trigger debe bloquear el sobrepago.');
        $this->assertSame('SOBREPAGO_TRIGGER_45000', $resultado['codigo_error']);
        $this->assertStringContainsString('S/', $resultado['mensaje'], 'El mensaje debe indicar el saldo en S/.');
    }

    /**
     * @test
     * Verifica que el trigger rechaza el pago exactamente en el límite (1 sol extra).
     */
    public function testSobrepagoBloqueadoCerca(): void
    {
        $saldo = $this->pedidoModel->getSaldoPendiente(2);

        $resultado = $this->pagoModel->registrarPago(2, $saldo + 0.01, 'Efectivo');

        $this->assertFalse($resultado['success']);
        $this->assertSame('SOBREPAGO_TRIGGER_45000', $resultado['codigo_error']);
    }

    /**
     * @test
     * Verifica que el pago exacto del saldo pendiente SÍ es aceptado.
     */
    public function testPagoExactoDelSaldoEsAceptado(): void
    {
        $saldo     = $this->pedidoModel->getSaldoPendiente(2);
        $resultado = $this->pagoModel->registrarPago(2, $saldo, 'Efectivo');

        $this->assertTrue($resultado['success'], 'El pago exacto del saldo pendiente debe ser aceptado.');
    }

    // ---------------------------------------------------------------
    // Tests: Cambio Automático a 'Pagado' (Trigger)
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que al cubrir el total, el trigger cambia el estado a 'Pagado'.
     * Pedido 3: total=25.00, sin pagos previos.
     */
    public function testPagoCompletoActualizaEstadoAPagado(): void
    {
        // Pedido 3: total = 25.00 (1 abrigo), sin pagos
        $resultado = $this->pagoModel->registrarPago(3, 25.00, 'Yape/Plin');

        $this->assertTrue($resultado['success']);

        $pedido = $this->pedidoModel->find(3);
        $this->assertSame('Pagado', $pedido['estado'], 'El trigger debe cambiar el estado a Pagado al cubrir el total.');
    }

    /**
     * @test
     * Verifica que un pago parcial NO cambia el estado a 'Pagado'.
     */
    public function testPagoParcialNoActualizaEstadoAPagado(): void
    {
        // Pedido 1: total=27.00, abonamos 10.00 (parcial)
        $this->pagoModel->registrarPago(1, 10.00, 'Efectivo');

        $pedido = $this->pedidoModel->find(1);
        $this->assertNotSame('Pagado', $pedido['estado'], 'Un pago parcial no debe cambiar el estado a Pagado.');
    }

    // ---------------------------------------------------------------
    // Tests: Validaciones de Modelo
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que se rechaza un monto de 0 (CHECK CONSTRAINT y validación PHP).
     */
    public function testMontoNegativoOCeroFallaValidacion(): void
    {
        $resultado = $this->pagoModel->registrarPago(1, 0.00, 'Efectivo');

        $this->assertFalse($resultado['success']);
        $this->assertSame('VALIDATION_ERROR', $resultado['codigo_error']);
    }

    /**
     * @test
     * Verifica que un método de pago inválido falla en validación.
     */
    public function testMetodoPagoInvalidoFallaValidacion(): void
    {
        $resultado = $this->pagoModel->registrarPago(1, 5.00, 'PayPal');

        $this->assertFalse($resultado['success']);
        $this->assertSame('VALIDATION_ERROR', $resultado['codigo_error']);
    }

    // ---------------------------------------------------------------
    // Tests: Recibo de Pago
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que getDatosRecibo() devuelve la estructura correcta del recibo.
     */
    public function testGetDatosReciboEstructura(): void
    {
        // Registrar un pago para obtener su ID
        $resultado = $this->pagoModel->registrarPago(1, 5.00, 'Efectivo');
        $this->assertTrue($resultado['success']);

        $recibo = $this->pagoModel->getDatosRecibo($resultado['idPago']);

        $this->assertNotNull($recibo);
        $this->assertArrayHasKey('empresa', $recibo);
        $this->assertArrayHasKey('recibo_n', $recibo);
        $this->assertArrayHasKey('monto_abonado', $recibo);
        $this->assertArrayHasKey('metodo', $recibo);
        $this->assertArrayHasKey('cliente', $recibo);
        $this->assertSame('LAVANDERÍA MARYCLEAN', $recibo['empresa']);
        $this->assertSame('Efectivo', $recibo['metodo']);
    }

    // ---------------------------------------------------------------
    // Tests: getTotalPagado
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica getTotalPagado() para pedido con pago previo (pedido 2 tiene 20.00).
     */
    public function testGetTotalPagado(): void
    {
        $total = $this->pagoModel->getTotalPagado(2);
        $this->assertEqualsWithDelta(20.00, $total, 0.01, 'El total pagado del pedido 2 debe ser 20.00.');
    }

    /**
     * @test
     * Verifica getTotalPagado() para pedido sin pagos.
     */
    public function testGetTotalPagadoSinPagos(): void
    {
        $total = $this->pagoModel->getTotalPagado(1);
        $this->assertEqualsWithDelta(0.00, $total, 0.01, 'El pedido 1 no tiene pagos, el total debe ser 0.');
    }
}
