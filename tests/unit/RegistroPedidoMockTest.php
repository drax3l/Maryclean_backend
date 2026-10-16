<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Models\PedidoModel;
use App\Services\PedidoProcesadorService;

class RegistroPedidoMockTest extends CIUnitTestCase
{
    /**
     * TA-04: Uso de Stub o Mock con expectativa.
     */
    public function testControladorLlamaAlModeloParaRegistrar(): void
    {
        // 1. Arrange: Creamos un MOCK del Modelo (¡Nunca tocará la BD real!)
        $mockModel = $this->createMock(PedidoModel::class);

        // Expectativa: Le decimos al Mock que ESPERE ser llamado EXACTAMENTE UNA VEZ
        // a su método 'registrarRecepcion', y que cuando eso pase, devuelva un ID falso (ej. 99)
        $mockModel->expects($this->once())
                  ->method('registrarRecepcion')
                  ->willReturn(99);

        // Inyectamos el Mock en el Servicio
        $servicio = new PedidoProcesadorService($mockModel);

        // 2. Act: Llamamos al servicio
        $resultado = $servicio->procesarRegistro('MC-MOCK-001', 1, 1);

        // 3. Assert: Comprobamos que el servicio nos devolvió el ID que el Mock fabricó
        $this->assertSame(99, $resultado, 'El servicio debería devolver el ID generado por el Mock.');
    }
}
