<?php

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Models\PedidoModel;

class RegistroPedidoDatabaseTest extends CIUnitTestCase
{
    // Esto activa la limpieza automática de la base de datos después de la prueba
    use DatabaseTestTrait;

    protected $seed = 'App\Database\Seeds\LavanderiaSeeder';
    protected $namespace = 'App';

    /**
     * TA-05: Prueba de Integración con base de datos real.
     */
    public function testRegistrarRecepcionGuardaEnBaseDeDatosReal(): void
    {
        // 1. Arrange: Preparamos el modelo real
        $modelo = new PedidoModel();
        $codigoTicket = 'MC-INT-001';

        // 2. Act: Insertamos en la base de datos de pruebas (MySQL local)
        $idPedido = $modelo->registrarRecepcion(
            $codigoTicket,
            date('Y-m-d H:i:s'),
            1, // ID Cliente que existe gracias al Seeder
            1  // ID Sucursal
        );

        // 3. Assert: Comprobamos la persistencia
        $this->assertIsInt($idPedido, 'El modelo debe retornar un ID entero autogenerado.');
        $this->assertGreaterThan(0, $idPedido);

        // Actividad 16 de tu Guía: "Además de comprobar el identificador, recupere el registro"
        $pedidoRecuperado = $modelo->find($idPedido);
        
        $this->assertNotNull($pedidoRecuperado, 'El pedido debe existir físicamente en la BD.');
        $this->assertSame($codigoTicket, $pedidoRecuperado['codigoTicket'], 'El código del ticket recuperado debe coincidir.');
    }
}
