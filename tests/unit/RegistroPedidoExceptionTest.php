<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\PedidoValidatorService;

class RegistroPedidoExceptionTest extends CIUnitTestCase
{
    /**
     * TA-03: Prueba de una excepción.
     */
    public function testRegistrarPedidoSinClienteLanzaExcepcion(): void
    {
        // Arrange
        $validador = new PedidoValidatorService();
        $jsonBodyVacio = []; // Simulamos que el frontend olvidó enviar el idCliente

        // Assert: Le decimos a PHPUnit que ESPERAMOS que se detone una Excepción
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/idCliente es obligatorio/');

        // Act: Ejecutamos el validador, esto debería causar que explote controladamente
        $validador->validarRegistroBasico($jsonBodyVacio);
    }
}
