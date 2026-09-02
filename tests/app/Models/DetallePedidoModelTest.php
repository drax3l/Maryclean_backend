<?php

declare(strict_types=1);

namespace Tests\App\Models;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Models\DetallePedidoModel;
use App\Models\PedidoModel;

/**
 * DetallePedidoModelTest
 *
 * Suite de pruebas para DetallePedidoModel con énfasis en:
 * - Verificación de que los triggers recalculan el total del pedido
 * - Inserción con cálculo de importe (cantidad × precio)
 * - Eliminación de detalle con actualización del total (trigger)
 * - Validaciones de cantidad e importe > 0
 *
 * ESCENARIOS DE TRIGGER VERIFICADOS:
 * - trg_detalle_insertar_total: total se suma al insertar
 * - trg_detalle_actualizar_total: total se recalcula al actualizar
 * - trg_detalle_eliminar_total: total se resta al eliminar
 *
 * @package Tests\App\Models
 */
class DetallePedidoModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed      = 'App\Database\Seeds\LavanderiaSeeder';
    protected $namespace = 'App';

    protected DetallePedidoModel $detalleModel;
    protected PedidoModel        $pedidoModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detalleModel = new DetallePedidoModel();
        $this->pedidoModel  = new PedidoModel();
    }

    // ---------------------------------------------------------------
    // Tests: Trigger trg_detalle_insertar_total
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que al insertar un detalle, el trigger actualiza el total del pedido.
     * Pedido 1 después del seeder: 3×camisa(5.00) + 2×pantalón(6.00) = 27.00
     */
    public function testTriggerInsertarActualizaTotalPedido(): void
    {
        $totalAntes = (float) ($this->pedidoModel->find(1)['total'] ?? 0);
        $this->assertEqualsWithDelta(27.00, $totalAntes, 0.01, 'Total del pedido 1 debe ser 27.00 después del seeder.');

        // Insertar 2 polos (idPrenda=3, precio=3.50 → importe=7.00)
        $idDetalle = $this->detalleModel->insertarDetalle(1, 3, 2, 'Polos azules');

        $this->assertNotFalse($idDetalle, 'La inserción del detalle debe ser exitosa.');

        $totalDespues = (float) ($this->pedidoModel->find(1)['total'] ?? 0);
        $this->assertEqualsWithDelta(34.00, $totalDespues, 0.01, 'El trigger debe sumar 7.00 al total: 27.00 + 7.00 = 34.00.');
    }

    /**
     * @test
     * Verifica que se pueden insertar múltiples detalles acumulando el total.
     */
    public function testInsertarMultiplesDetallesAcumulaTotal(): void
    {
        // Pedido 1: total inicial = 27.00
        $this->detalleModel->insertarDetalle(1, 3, 1, 'Polo rojo');   // +3.50
        $this->detalleModel->insertarDetalle(1, 4, 2, 'Ropa interior'); // +4.00

        $totalFinal = (float) ($this->pedidoModel->find(1)['total'] ?? 0);
        $this->assertEqualsWithDelta(34.50, $totalFinal, 0.01, 'Total acumulado incorrecto.');
    }

    // ---------------------------------------------------------------
    // Tests: Trigger trg_detalle_eliminar_total
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que al eliminar un detalle, el trigger resta del total del pedido.
     * Pedido 1: total=27.00. Al eliminar el detalle de pantalones (importe=12.00) → 15.00
     */
    public function testTriggerEliminarDetalleActualizaTotalPedido(): void
    {
        // Obtener el detalle del pantalón del pedido 1 (idDetalle=2 según PedidoSeeder)
        $detalles = $this->detalleModel->getDetallesPorPedido(1);
        $detallePantalon = null;
        foreach ($detalles as $d) {
            if ($d['nombrePrenda'] === 'Pantalón') {
                $detallePantalon = $d;
                break;
            }
        }

        $this->assertNotNull($detallePantalon, 'El detalle de pantalón debe existir en el pedido 1.');

        $totalAntes = (float) ($this->pedidoModel->find(1)['total'] ?? 0);

        $this->detalleModel->delete($detallePantalon['idDetalle']);

        $totalDespues = (float) ($this->pedidoModel->find(1)['total'] ?? 0);
        $expected = $totalAntes - (float) $detallePantalon['importe'];

        $this->assertEqualsWithDelta($expected, $totalDespues, 0.01, 'El trigger debe restar el importe eliminado del total.');
    }

    /**
     * @test
     * Verifica que eliminar todos los detalles deja el total en 0.
     */
    public function testEliminarTodosLosDetallesDejaTotal0(): void
    {
        $this->detalleModel->eliminarDetallesPorPedido(1);

        $total = (float) ($this->pedidoModel->find(1)['total'] ?? -1);
        $this->assertEqualsWithDelta(0.00, $total, 0.01, 'Sin detalles, el total del pedido debe ser 0.');
    }

    // ---------------------------------------------------------------
    // Tests: Trigger trg_detalle_actualizar_total
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que al actualizar el importe de un detalle, el trigger recalcula el total.
     */
    public function testTriggerActualizarDetalleRecalculaTotal(): void
    {
        $detalles = $this->detalleModel->getDetallesPorPedido(1);
        $this->assertNotEmpty($detalles);

        $primer = $detalles[0];
        $importeOriginal = (float) $primer['importe'];
        $nuevoImporte    = 20.00; // Cambiamos el importe manualmente

        $totalAntes = (float) ($this->pedidoModel->find(1)['total'] ?? 0);

        // Actualizar el importe directamente (simular cambio de cantidad)
        $this->detalleModel->db->table('DetallePedido')
            ->where('idDetalle', $primer['idDetalle'])
            ->update(['importe' => $nuevoImporte, 'cantidad' => 4]);

        $totalDespues = (float) ($this->pedidoModel->find(1)['total'] ?? 0);
        $expected = $totalAntes - $importeOriginal + $nuevoImporte;

        $this->assertEqualsWithDelta($expected, $totalDespues, 0.01, 'El trigger debe recalcular el total al actualizar el detalle.');
    }

    // ---------------------------------------------------------------
    // Tests: Validaciones del Modelo
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que insertarDetalle() rechaza cantidad = 0.
     */
    public function testInsertarDetalleCantidadCeroFalla(): void
    {
        $resultado = $this->detalleModel->insertarDetalle(1, 1, 0, 'Test');
        $this->assertFalse($resultado, 'Una cantidad de 0 no debe ser aceptada.');
    }

    /**
     * @test
     * Verifica que insertarDetalle() falla con prenda inexistente.
     */
    public function testInsertarDetallePrendaInexistenteFalla(): void
    {
        $resultado = $this->detalleModel->insertarDetalle(1, 99999, 1, 'Test');
        $this->assertFalse($resultado, 'Una prenda inexistente (precio=null) debe retornar false.');
    }

    // ---------------------------------------------------------------
    // Tests: Consultas
    // ---------------------------------------------------------------

    /**
     * @test
     * Verifica que getDetallesPorPedido() devuelve el conteo correcto.
     * El pedido 1 en el seeder tiene 2 detalles.
     */
    public function testGetDetallesPorPedidoConteo(): void
    {
        $detalles = $this->detalleModel->getDetallesPorPedido(1);
        $this->assertCount(2, $detalles, 'El pedido 1 debe tener exactamente 2 detalles según el seeder.');
    }

    /**
     * @test
     * Verifica que getDetallesPorPedido() incluye datos enriquecidos (JOIN).
     */
    public function testGetDetallesPorPedidoTieneJoins(): void
    {
        $detalles = $this->detalleModel->getDetallesPorPedido(1);
        $primero  = $detalles[0];

        $this->assertArrayHasKey('nombrePrenda', $primero);
        $this->assertArrayHasKey('precioUnitario', $primero);
        $this->assertArrayHasKey('servicio', $primero);
    }

    /**
     * @test
     * Verifica que calcularSubtotal() coincide con el total del pedido (sin pagos parciales).
     */
    public function testCalcularSubtotalCoincideConTotalPedido(): void
    {
        $subtotal   = $this->detalleModel->calcularSubtotal(1);
        $totalPedido = (float) ($this->pedidoModel->find(1)['total'] ?? 0);

        $this->assertEqualsWithDelta($totalPedido, $subtotal, 0.01, 'El subtotal calculado en PHP debe coincidir con el total en BD.');
    }

    /**
     * @test
     * Verifica que getDetallesParaTicket() devuelve formato correcto para impresión.
     */
    public function testGetDetallesParaTicketFormato(): void
    {
        $detalles = $this->detalleModel->getDetallesParaTicket(1);

        $this->assertNotEmpty($detalles);
        $primero = $detalles[0];

        $this->assertArrayHasKey('servicio', $primero);
        $this->assertArrayHasKey('prenda', $primero);
        $this->assertArrayHasKey('cantidad', $primero);
        $this->assertArrayHasKey('precio_unit', $primero);
        $this->assertArrayHasKey('importe', $primero);
    }
}
