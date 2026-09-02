<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * AuditoriaEstadoModel
 *
 * Gestiona la tabla `AuditoriaEstado`.
 *
 * INMUTABILIDAD GARANTIZADA:
 * Esta tabla es estrictamente de SOLO LECTURA desde PHP.
 * Los registros son insertados EXCLUSIVAMENTE por el trigger
 * `trg_pedido_despues_actualizar_auditoria` del motor MySQL.
 * Los métodos `insert()`, `update()`, `delete()` y `save()` están
 * bloqueados a nivel de aplicación para proteger la integridad del
 * historial de auditoría.
 *
 * @package App\Models
 */
class AuditoriaEstadoModel extends Model
{
    protected $table            = 'AuditoriaEstado';
    protected $primaryKey       = 'idAuditoria';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    /**
     * No se permiten campos escritos desde PHP. El trigger es el único
     * actor autorizado para insertar en esta tabla.
     */
    protected $allowedFields = [];

    protected $useTimestamps = false;

    // No se definen reglas de validación porque no hay operaciones de escritura
    protected $validationRules = [];

    protected $skipValidation = true;

    // ---------------------------------------------------------------
    // Métodos Bloqueados — Inmutabilidad de la Auditoría
    // ---------------------------------------------------------------

    /**
     * Bloquea cualquier intento de inserción directa desde PHP.
     * Los registros solo los crea el trigger de MySQL.
     *
     * @throws \RuntimeException
     */
    public function insert($data = null, bool $returnID = true): bool|int|string
    {
        throw new \RuntimeException(
            '[AuditoriaEstadoModel] Operación PROHIBIDA: insert(). ' .
            'Los registros de auditoría son generados exclusivamente por el trigger `trg_pedido_despues_actualizar_auditoria`.'
        );
    }

    /**
     * Bloquea cualquier modificación de registros históricos.
     *
     * @throws \RuntimeException
     */
    public function update($id = null, $data = null): bool
    {
        throw new \RuntimeException(
            '[AuditoriaEstadoModel] Operación PROHIBIDA: update(). ' .
            'Los registros de auditoría son inmutables y no pueden modificarse desde PHP.'
        );
    }

    /**
     * Bloquea cualquier eliminación del historial de auditoría.
     *
     * @throws \RuntimeException
     */
    public function delete($id = null, bool $purge = false): bool|string
    {
        throw new \RuntimeException(
            '[AuditoriaEstadoModel] Operación PROHIBIDA: delete(). ' .
            'El historial de auditoría es permanente y no puede eliminarse desde PHP.'
        );
    }

    /**
     * Bloquea el método save() que internamente llama a insert() o update().
     *
     * @throws \RuntimeException
     */
    public function save($data): bool
    {
        throw new \RuntimeException(
            '[AuditoriaEstadoModel] Operación PROHIBIDA: save(). ' .
            'Utilice update() en PedidoModel para cambiar estados. El trigger registrará el cambio automáticamente.'
        );
    }

    // ---------------------------------------------------------------
    // Métodos de Consulta (Solo Lectura)
    // ---------------------------------------------------------------

    /**
     * Obtiene el historial completo de cambios de estado de un pedido.
     *
     * @param int $idPedido
     * @return array
     */
    public function getHistorialPorPedido(int $idPedido): array
    {
        return $this->where('idPedido', $idPedido)
            ->orderBy('fechaCambio', 'ASC')
            ->findAll();
    }

    /**
     * Obtiene el último registro de auditoría para un pedido.
     *
     * @param int $idPedido
     * @return array|null
     */
    public function getUltimoEstado(int $idPedido): ?array
    {
        return $this->where('idPedido', $idPedido)
            ->orderBy('fechaCambio', 'DESC')
            ->first();
    }

    /**
     * Obtiene todos los cambios de estado registrados en un rango de fechas.
     *
     * @param string $fechaInicio Formato: Y-m-d
     * @param string $fechaFin    Formato: Y-m-d
     * @return array
     */
    public function getAuditoriaPorRango(string $fechaInicio, string $fechaFin): array
    {
        return $this->db->table('AuditoriaEstado ae')
            ->select('ae.idAuditoria, ae.idPedido, p.codigoTicket, ae.estadoAnterior, ae.estadoNuevo, ae.fechaCambio')
            ->join('Pedido p', 'p.idPedido = ae.idPedido', 'inner')
            ->where('DATE(ae.fechaCambio) >=', $fechaInicio)
            ->where('DATE(ae.fechaCambio) <=', $fechaFin)
            ->orderBy('ae.fechaCambio', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Cuenta cuántos cambios de estado ha tenido un pedido.
     *
     * @param int $idPedido
     * @return int
     */
    public function contarCambiosPorPedido(int $idPedido): int
    {
        return $this->where('idPedido', $idPedido)->countAllResults();
    }
}
