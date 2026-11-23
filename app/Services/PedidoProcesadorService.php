<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Pedidos as PedidosConfig;
use App\Models\DetallePedidoModel;
use App\Models\PagoModel;
use App\Models\PedidoModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\ResponseInterface;

class PedidoProcesadorService
{
    private PedidoModel $pedidoModel;
    private ?DetallePedidoModel $detalleModel;
    private ?PagoModel $pagoModel;
    private ?PedidosConfig $config;

    public function __construct(
        ?PedidoModel $pedidoModel = null,
        ?DetallePedidoModel $detalleModel = null,
        ?PagoModel $pagoModel = null,
        ?PedidosConfig $config = null
    ) {
        $this->pedidoModel  = $pedidoModel ?? new PedidoModel();
        $this->detalleModel = $detalleModel;
        $this->pagoModel    = $pagoModel;
        $this->config       = $config;
    }

    /**
     * Método preservado para compatibilidad con pruebas unitarias (TA-04).
     */
    public function procesarRegistro(string $codigo, int $idCliente, int $idSucursal): int
    {
        return $this->pedidoModel->registrarRecepcion($codigo, date('Y-m-d H:i:s'), $idCliente, $idSucursal);
    }

    /**
     * Orquesta el registro completo del pedido, sus detalles y el pago inicial opcional,
     * garantizando rollback atómico ante fallos de detalle, SC-01 o INC-01.
     *
     * @param array<int, array<string, mixed>>        $detalles
     * @param array{monto: float, metodo: string}|null $pagoInicial
     * @return array{
     *     success: bool,
     *     status: int,
     *     message: string,
     *     data: ?array<string, mixed>,
     *     errors: ?array<string, mixed>
     * }
     */
    public function registrarPedidoCompleto(
        int $idCliente,
        int $idEmpleado,
        array $detalles,
        ?array $pagoInicial = null
    ): array {
        $codigoTicket   = $this->pedidoModel->generarCodigoTicket();
        $fechaRecepcion = date('Y-m-d H:i:s');

        $idPedido = $this->pedidoModel->registrarRecepcion(
            $codigoTicket,
            $fechaRecepcion,
            $idCliente,
            $idEmpleado
        );

        $erroresDetalle = $this->insertarDetallesPedido($idPedido, $detalles);
        if ($erroresDetalle !== []) {
            $this->pedidoModel->delete($idPedido);

            return [
                'success' => false,
                'status'  => ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'No se pudo registrar completamente el pedido. La operación fue revertida.',
                'data'    => null,
                'errors'  => [
                    'detalles' => $erroresDetalle,
                ],
            ];
        }

        if ($pagoInicial !== null) {
            $errorPago = $this->procesarPagoInicial($idPedido, $pagoInicial);
            if ($errorPago !== null) {
                return $errorPago;
            }
        }

        $ticket = $this->pedidoModel->getDatosTicket($idPedido);

        return [
            'success' => true,
            'status'  => ResponseInterface::HTTP_CREATED,
            'message' => 'Pedido registrado exitosamente.',
            'data'    => [
                'idPedido'     => $idPedido,
                'codigoTicket' => $codigoTicket,
                'ticket'       => $ticket,
            ],
            'errors' => null,
        ];
    }

    /**
     * Inserta las prendas del pedido y retorna los errores ocurridos.
     *
     * @param array<int, array<string, mixed>> $detalles
     * @return array<int, string>
     */
    private function insertarDetallesPedido(int $idPedido, array $detalles): array
    {
        $errores = [];

        foreach ($detalles as $i => $detalle) {
            $resultado = $this->detalleModel()->insertarDetalle(
                $idPedido,
                (int) $detalle['idPrenda'],
                (float) $detalle['cantidad'],
                trim((string) ($detalle['descripcion'] ?? ''))
            );

            if ($resultado === false) {
                $errores[] = sprintf(
                    'Prenda #%d (línea %d): no se pudo registrar.',
                    (int) $detalle['idPrenda'],
                    ((int) $i) + 1
                );
            }
        }

        return $errores;
    }

    /**
     * Evalúa la política SC-01 y registra el pago inicial con protección INC-01.
     *
     * @param array{monto: float, metodo: string} $pagoInicial
     * @return array{success: bool, status: int, message: string, data: null, errors: array<string, mixed>}|null
     */
    private function procesarPagoInicial(int $idPedido, array $pagoInicial): ?array
    {
        $errorMinimo = $this->validarPoliticaPagoInicial($idPedido, $pagoInicial['monto']);
        if ($errorMinimo !== null) {
            $this->pedidoModel->delete($idPedido);

            return $errorMinimo;
        }

        $resultadoPago = $this->pagoModel()->registrarPago(
            $idPedido,
            $pagoInicial['monto'],
            $pagoInicial['metodo']
        );

        if (! ($resultadoPago['success'] ?? false)) {
            $this->pedidoModel->delete($idPedido);

            return $this->construirErrorPagoRechazado($resultadoPago);
        }

        return null;
    }

    /**
     * Verifica que el pago inicial alcance el porcentaje mínimo configurado (SC-01).
     *
     * @return array{success: bool, status: int, message: string, data: null, errors: array<string, mixed>}|null
     */
    private function validarPoliticaPagoInicial(int $idPedido, float $montoPago): ?array
    {
        $porcentajeMinimo = $this->obtenerPorcentajeMinimoPagoInicial();
        if ($porcentajeMinimo <= 0.0) {
            return null;
        }

        $pedidoActual = $this->pedidoModel->find($idPedido);
        $totalPedido  = round((float) ($pedidoActual['total'] ?? 0), 2);
        $montoMinimo  = round($totalPedido * ($porcentajeMinimo / 100), 2);

        if ($montoPago < $montoMinimo) {
            return [
                'success' => false,
                'status'  => ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'No se pudo registrar el pedido porque el pago inicial no alcanza el mínimo requerido.',
                'data'    => null,
                'errors'  => [
                    'pagoInicial' => sprintf(
                        'El pago inicial debe cubrir como mínimo el %.2f%% del total del pedido. Monto mínimo: S/ %.2f.',
                        $porcentajeMinimo,
                        $montoMinimo
                    ),
                    'codigo_error' => 'PAGO_INICIAL_INSUFICIENTE',
                ],
            ];
        }

        return null;
    }

    /**
     * Obtiene el porcentaje mínimo desde configuración tipada y entorno, acotado a [0, 100].
     */
    private function obtenerPorcentajeMinimoPagoInicial(): float
    {
        /** @var PedidosConfig $configPedidos */
        $configPedidos = $this->config ?? Factories::config('Pedidos');

        $valorEnv = $_ENV['pedidos.porcentajePagoInicialMinimo']
            ?? $_SERVER['pedidos.porcentajePagoInicialMinimo']
            ?? getenv('pedidos.porcentajePagoInicialMinimo');

        $porcentaje = ($valorEnv !== false && $valorEnv !== '')
            ? (float) $valorEnv
            : $configPedidos->porcentajePagoInicialMinimo;

        return max(0.0, min(100.0, $porcentaje));
    }

    /**
     * Traduce el rechazo devuelto por PagoModel a la estructura de error HTTP (INC-01).
     *
     * @param array<string, mixed> $resultadoPago
     * @return array{success: bool, status: int, message: string, data: null, errors: array<string, mixed>}
     */
    private function construirErrorPagoRechazado(array $resultadoPago): array
    {
        $codigoError = (string) ($resultadoPago['codigo_error'] ?? 'PAGO_INICIAL_INVALIDO');

        $codigoHttp = match ($codigoError) {
            'PEDIDO_NO_EXISTE' => ResponseInterface::HTTP_NOT_FOUND,
            default            => ResponseInterface::HTTP_UNPROCESSABLE_ENTITY,
        };

        return [
            'success' => false,
            'status'  => $codigoHttp,
            'message' => 'No se pudo registrar el pedido porque el pago inicial es inválido.',
            'data'    => null,
            'errors'  => [
                'pagoInicial'  => $resultadoPago['mensaje'] ?? 'Pago inicial inválido.',
                'codigo_error' => $codigoError,
            ],
        ];
    }

    private function detalleModel(): DetallePedidoModel
    {
        if ($this->detalleModel === null) {
            $this->detalleModel = new DetallePedidoModel();
        }

        return $this->detalleModel;
    }

    private function pagoModel(): PagoModel
    {
        if ($this->pagoModel === null) {
            $this->pagoModel = new PagoModel();
        }

        return $this->pagoModel;
    }
}