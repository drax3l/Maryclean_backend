<?php

declare(strict_types=1);

namespace Tests\App\Models;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Models\PedidoModel;
use App\Models\DetallePedidoModel;
use App\Models\PagoModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * PedidoModelTest
 *
 * Suite de pruebas de integración para PedidoModel.
 * Valida el ciclo de vida completo de un pedido, incluyendo:
 * - Gestión de estados y transición
 * - Ejecución del SP sp_registrar_recepcion
 * - Interacción con la vista v_pedidos_activos
 * - Generación del ticket imprimible
 * - Cálculo de saldo pendiente
 *
 * ENTORNO: lavanderia_test (configurado en phpunit.xml)
 * PREREQUISITO: Correr LavanderiaSeeder antes de la suite
 *
 * @package Tests\App\Models
 */
class PedidoModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /**
     * Clase de datos semilla a cargar antes de cada test.
     * @var string|null
     */
    protected $seed = 'App\Database\Seeds\LavanderiaSeeder';

    /**
     * Namespace del seeder (requerido por CI4 TestCase).
     * @var string
     */
    protected $namespace = 'App';

    protected PedidoModel $pedidoModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pedidoModel = new PedidoModel();
    }

    // ---------------------------------------------------------------
    // Tests: Gestión de Estados
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que cambiarEstado() actualiza correctamente el estado de un pedido.
     */
    public function testCambiarEstadoExitoso(): void
    {
        $resultado = $this->pedidoModel->cambiarEstado(1, 'En Proceso');
        $this->assertTrue($resultado);

        $pedido = $this->pedidoModel->find(1);
        $this->assertSame('En Proceso', $pedido['estado']);
    }

    /**
     * @test
     * Verifica que cambiarEstado() lanza InvalidArgumentException para un estado inválido.
     */
    public function testCambiarEstadoInvalidoLanzaExcepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Estado inválido/');

        $this->pedidoModel->cambiarEstado(1, 'EstadoInventado');
    }

    /**
     * @test
     * Verifica que al cambiar a 'Entregado', el trigger asigna fechaEntrega automáticamente.
     */
    public function testCambiarAEntregadoAsignaFechaEntrega(): void
    {
        // El pedido 3 está en estado 'Listo' (ver PedidoSeeder)
        $this->pedidoModel->cambiarEstado(3, 'Entregado');

        $pedido = $this->pedidoModel->find(3);
        $this->assertSame('Entregado', $pedido['estado']);
        $this->assertNotNull($pedido['fechaEntrega'], 'El trigger debió asignar fechaEntrega automáticamente.');
        $this->assertNotEmpty($pedido['fechaEntrega']);
    }

    /**
     * @test
     * Verifica que al cambiar el estado, el trigger de auditoría registra el cambio.
     */
    public function testCambioEstadoRegistraAuditoria(): void
    {
        $auditoriaModel = new \App\Models\AuditoriaEstadoModel();

        // Estado inicial: Recibido (ver PedidoSeeder, pedido 1)
        $antes = $auditoriaModel->contarCambiosPorPedido(1);

        $this->pedidoModel->cambiarEstado(1, 'En Proceso');

        $despues = $auditoriaModel->contarCambiosPorPedido(1);
        $this->assertSame($antes + 1, $despues, 'El trigger de auditoría debió registrar exactamente 1 cambio de estado.');

        $ultimo = $auditoriaModel->getUltimoEstado(1);
        $this->assertSame('Recibido', $ultimo['estadoAnterior']);
        $this->assertSame('En Proceso', $ultimo['estadoNuevo']);
    }

    /**
     * @test
     * Verifica que cancelarPedido() establece el estado 'Cancelado'.
     */
    public function testCancelarPedido(): void
    {
        $this->pedidoModel->cancelarPedido(1);
        $pedido = $this->pedidoModel->find(1);
        $this->assertSame('Cancelado', $pedido['estado']);
    }

    // ---------------------------------------------------------------
    // Tests: Vista v_pedidos_activos
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que getPedidosActivos() solo devuelve pedidos en curso.
     */
    public function testGetPedidosActivosExcluyeFinalizados(): void
    {
        $activos = $this->pedidoModel->getPedidosActivos();

        $estados = array_column($activos, 'estado');

        $this->assertNotContains('Entregado', $estados, 'La vista no debe incluir pedidos Entregados.');
        $this->assertNotContains('Cancelado', $estados, 'La vista no debe incluir pedidos Cancelados.');
    }

    /**
     * @test
     * Verifica que getPedidosActivos() devuelve datos de cliente y empleado.
     */
    public function testGetPedidosActivosTieneJoins(): void
    {
        $activos = $this->pedidoModel->getPedidosActivos();

        $this->assertNotEmpty($activos);
        $primer = $activos[0];
        $this->assertArrayHasKey('cliente', $primer);
        $this->assertArrayHasKey('empleado', $primer);
        $this->assertArrayHasKey('codigoTicket', $primer);
    }

    // ---------------------------------------------------------------
    // Tests: Cálculo de Saldo
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que getSaldoPendiente() calcula correctamente el saldo.
     * El pedido 2 tiene total de 53.00 (35+18) y un pago de 20.00.
     */
    public function testGetSaldoPendiente(): void
    {
        // Pedido 2: total = 35.00 + 18.00 = 53.00. Pago registrado: 20.00
        $saldo = $this->pedidoModel->getSaldoPendiente(2);
        $this->assertEqualsWithDelta(33.00, $saldo, 0.01, 'Saldo pendiente incorrecto para pedido 2.');
    }

    /**
     * @test
     * Verifica que getSaldoPendiente() devuelve 0 para pedidos sin detalles.
     */
    public function testSaldoPendientePedidoSinTotal(): void
    {
        // Pedido 1 no tiene pagos; saldo = total del pedido
        $total = (float) ($this->pedidoModel->find(1)['total'] ?? 0);
        $saldo = $this->pedidoModel->getSaldoPendiente(1);
        $this->assertEqualsWithDelta($total, $saldo, 0.01);
    }

    // ---------------------------------------------------------------
    // Tests: Datos para Ticket
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que getDatosTicket() devuelve la estructura correcta.
     */
    public function testGetDatosTicketEstructura(): void
    {
        $ticket = $this->pedidoModel->getDatosTicket(1);

        $this->assertArrayHasKey('encabezado', $ticket);
        $this->assertArrayHasKey('cliente', $ticket);
        $this->assertArrayHasKey('empleado', $ticket);
        $this->assertArrayHasKey('detalles', $ticket);
        $this->assertArrayHasKey('total', $ticket);
        $this->assertArrayHasKey('saldo_pendiente', $ticket);
        $this->assertArrayHasKey('estado', $ticket);

        $this->assertSame('LAVANDERÍA MARYCLEAN', $ticket['encabezado']['empresa']);
    }

    /**
     * @test
     * Verifica que getDatosTicket() lanza RuntimeException para pedido inexistente.
     */
    public function testGetDatosTicketPedidoInexistenteLanzaExcepcion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no encontrado/');

        $this->pedidoModel->getDatosTicket(99999);
    }

    // ---------------------------------------------------------------
    // Tests: Generación de Código de Ticket
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que generarCodigoTicket() produce un código con formato correcto.
     */
    public function testGenerarCodigoTicketFormato(): void
    {
        $codigo = $this->pedidoModel->generarCodigoTicket();

        $this->assertMatchesRegularExpression(
            '/^MC-\d{8}-[A-Z0-9]{5}$/',
            $codigo,
            'El código de ticket debe tener formato MC-YYYYMMDD-XXXXX.'
        );
    }

    /**
     * @test
     * Verifica que generarCodigoTicket() genera códigos únicos entre sí.
     */
    public function testGenerarCodigoTicketEsUnico(): void
    {
        $codigos = [];
        for ($i = 0; $i < 5; $i++) {
            $codigos[] = $this->pedidoModel->generarCodigoTicket();
            usleep(1000); // Pequeña pausa para variar uniqid
        }

        $this->assertSame(count($codigos), count(array_unique($codigos)), 'Los códigos generados deben ser únicos.');
    }

    // ---------------------------------------------------------------
    // Tests: Stored Procedure sp_registrar_recepcion
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que sp_registrar_recepcion() inserta el pedido y devuelve el ID.
     */
    public function testRegistrarRecepcionDevuelveIdValido(): void
    {
        $codigo     = 'MC-TEST-SP001';
        $idPedido   = $this->pedidoModel->registrarRecepcion(
            $codigo,
            date('Y-m-d H:i:s'),
            1,
            3
        );

        $this->assertIsInt($idPedido);
        $this->assertGreaterThan(0, $idPedido);

        $pedido = $this->pedidoModel->find($idPedido);
        $this->assertSame($codigo, $pedido['codigoTicket']);
        $this->assertSame('Recibido', $pedido['estado']);
    }

    /**
     * @test
     * Verifica que sp_registrar_recepcion() falla con FK inválida.
     */
    public function testRegistrarRecepcionFallaConClienteInexistente(): void
    {
        $this->expectException(DatabaseException::class);

        $this->pedidoModel->registrarRecepcion(
            'MC-TEST-SP002',
            date('Y-m-d H:i:s'),
            99999, // Cliente inexistente
            3
        );
    }
}
