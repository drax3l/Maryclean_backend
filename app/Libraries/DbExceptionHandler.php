<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * DbExceptionHandler
 *
 * Trait reutilizable para interceptar excepciones de base de datos lanzadas
 * por triggers, stored procedures y constraints de MySQL en los modelos de CI4.
 *
 * CASOS DE USO PRINCIPALES:
 * - SQLSTATE '45000': Lanzado por `trg_pago_antes_insertar_validar` cuando el monto
 *   de pago supera el saldo pendiente del pedido.
 * - CHECK CONSTRAINT: Violaciones de precio > 0, monto > 0, cantidad > 0.
 * - FK Constraint: Intentos de insertar con FK inexistente.
 *
 * USO:
 *   use App\Libraries\DbExceptionHandler;
 *   class MiModel extends Model {
 *       use DbExceptionHandler;
 *   }
 *
 * @package App\Libraries
 */
trait DbExceptionHandler
{
    /**
     * Analiza una DatabaseException y la re-lanza con contexto enriquecido,
     * o devuelve una DatabaseException decorada si el llamador prefiere atraparla.
     *
     * @param DatabaseException $e       La excepción original
     * @param string            $metodo  Nombre del método donde ocurrió el error
     * @return DatabaseException         La excepción decorada (para re-lanzar)
     */
    protected function envolverExcepcionDB(DatabaseException $e, string $metodo): DatabaseException
    {
        $clase   = static::class;
        $mensaje = $e->getMessage();

        log_message('error', "[{$clase}::{$metodo}] DatabaseException: {$mensaje}");

        return new DatabaseException(
            "[{$clase}::{$metodo}] " . $mensaje,
            (int) $e->getCode(),
            $e
        );
    }

    /**
     * Determina si una DatabaseException fue causada por el trigger de sobrepago
     * (SQLSTATE '45000' lanzado por `trg_pago_antes_insertar_validar`).
     *
     * @param DatabaseException $e
     * @return bool
     */
    protected function esSobrepagoTrigger(DatabaseException $e): bool
    {
        $mensaje = strtolower($e->getMessage());

        return str_contains($mensaje, '45000')
            || str_contains($mensaje, 'saldo')
            || str_contains($mensaje, 'excede')
            || str_contains($mensaje, 'supera');
    }

    /**
     * Determina si la excepción fue causada por una violación de CHECK CONSTRAINT.
     * Aplica a precios, montos y cantidades que deben ser > 0.
     *
     * @param DatabaseException $e
     * @return bool
     */
    protected function esViolacionCheck(DatabaseException $e): bool
    {
        $mensaje = strtolower($e->getMessage());

        return str_contains($mensaje, 'check constraint')
            || str_contains($mensaje, 'constraint fails')
            || str_contains($mensaje, '3819'); // MySQL error code para CHECK
    }

    /**
     * Determina si la excepción fue causada por una violación de clave foránea.
     *
     * @param DatabaseException $e
     * @return bool
     */
    protected function esViolacionFK(DatabaseException $e): bool
    {
        $mensaje = strtolower($e->getMessage());

        return str_contains($mensaje, 'foreign key constraint')
            || str_contains($mensaje, '1452')    // Cannot add/update a child row
            || str_contains($mensaje, '1451');   // Cannot delete or update a parent row
    }

    /**
     * Determina si la excepción fue causada por un valor duplicado (UNIQUE).
     *
     * @param DatabaseException $e
     * @return bool
     */
    protected function esDuplicado(DatabaseException $e): bool
    {
        $mensaje = strtolower($e->getMessage());

        return str_contains($mensaje, 'duplicate entry')
            || str_contains($mensaje, '1062');
    }

    /**
     * Convierte una DatabaseException a un array estructurado para respuestas
     * JSON o flashdata. Útil en controladores que devuelven API responses.
     *
     * @param DatabaseException $e
     * @param int               $idPedido  Opcional, para calcular saldo en sobrepago
     * @return array{
     *   success: bool,
     *   codigo_error: string,
     *   mensaje: string,
     *   detalle_tecnico: string
     * }
     */
    protected function excepcionAArray(DatabaseException $e, int $idPedido = 0): array
    {
        if ($this->esSobrepagoTrigger($e)) {
            $saldo = 0.0;
            if ($idPedido > 0) {
                try {
                    $saldo = (new \App\Models\PedidoModel())->getSaldoPendiente($idPedido);
                } catch (\Throwable) {
                    // Ignorar error secundario
                }
            }

            return [
                'success'          => false,
                'codigo_error'     => 'SOBREPAGO_TRIGGER_45000',
                'mensaje'          => sprintf(
                    'El monto supera el saldo pendiente del pedido (S/ %.2f). Por favor, ajuste el monto.',
                    $saldo
                ),
                'detalle_tecnico'  => $e->getMessage(),
            ];
        }

        if ($this->esViolacionCheck($e)) {
            return [
                'success'          => false,
                'codigo_error'     => 'CHECK_CONSTRAINT_VIOLATION',
                'mensaje'          => 'El valor ingresado viola una restricción de integridad (debe ser mayor a 0).',
                'detalle_tecnico'  => $e->getMessage(),
            ];
        }

        if ($this->esViolacionFK($e)) {
            return [
                'success'          => false,
                'codigo_error'     => 'FOREIGN_KEY_VIOLATION',
                'mensaje'          => 'El registro referenciado no existe. Verifique los datos ingresados.',
                'detalle_tecnico'  => $e->getMessage(),
            ];
        }

        if ($this->esDuplicado($e)) {
            return [
                'success'          => false,
                'codigo_error'     => 'DUPLICATE_ENTRY',
                'mensaje'          => 'Ya existe un registro con ese valor único (código, documento, etc.).',
                'detalle_tecnico'  => $e->getMessage(),
            ];
        }

        // Error genérico de base de datos
        log_message('critical', 'DbExceptionHandler — Error no clasificado: ' . $e->getMessage());

        return [
            'success'          => false,
            'codigo_error'     => 'DATABASE_ERROR',
            'mensaje'          => 'Error interno del sistema. Por favor contacte al administrador.',
            'detalle_tecnico'  => ENVIRONMENT === 'development' ? $e->getMessage() : 'Oculto en producción.',
        ];
    }
}
