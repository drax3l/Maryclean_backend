<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\PedidoValidatorService;

class RegistroPedidoRuleTest extends CIUnitTestCase
{
    protected PedidoValidatorService $validador;

    protected function setUp(): void
    {
        parent::setUp();
        // Arrange general: Instanciamos el servicio aislando la base de datos
        $this->validador = new PedidoValidatorService();
    }

    /**
     * TA-01: Prueba de una regla de negocio válida.
     */
    public function testDetallePedidoConCantidadPositivaEsValido(): void
    {
        // Arrange
        $detalleCorrecto = [
            'idPrenda' => 5,
            'cantidad' => 2
        ];

        // Act
        $resultado = $this->validador->esDetalleValido($detalleCorrecto);

        // Assert
        $this->assertTrue($resultado, 'El detalle debería ser válido porque la cantidad es 2 (> 0).');
    }

    /**
     * TA-02: Prueba de una regla de negocio inválida.
     */
    public function testDetallePedidoConCantidadNegativaEsRechazado(): void
    {
        // Arrange
        $detalleMalicioso = [
            'idPrenda' => 5,
            'cantidad' => -5
        ];

        // Act
        $resultado = $this->validador->esDetalleValido($detalleMalicioso);

        // Assert
        $this->assertFalse($resultado, 'El detalle debe ser rechazado por tener cantidad negativa.');
    }
}
