<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ClienteModel
 *
 * Gestiona la tabla `Cliente`.
 * El campo `documento` es UNIQUE en la BD (DNI/RUC del cliente).
 * El índice en `telefono` optimiza búsquedas por número de contacto.
 *
 * @package App\Models
 */
class ClienteModel extends Model
{
    protected $table            = 'Cliente';
    protected $primaryKey       = 'idCliente';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'documento',
        'nombres',
        'telefono',
        'direccion',
    ];

    protected $useTimestamps = false;

    // ---------------------------------------------------------------
    // Reglas de Validación
    // ---------------------------------------------------------------
    protected $validationRules = [
        'documento' => 'required|min_length[8]|max_length[15]|alpha_numeric|is_unique[Cliente.documento,idCliente,{idCliente}]',
        'nombres'   => 'required|min_length[3]|max_length[200]',
        'telefono'  => 'permit_empty|min_length[7]|max_length[15]|regex_match[/^\+?[0-9\s\-]+$/]',
        'direccion' => 'permit_empty|max_length[255]',
    ];

    protected $validationMessages = [
        'documento' => [
            'required'      => 'El documento del cliente es obligatorio.',
            'min_length'    => 'El documento debe tener al menos 8 caracteres.',
            'max_length'    => 'El documento no puede superar los 15 caracteres.',
            'alpha_numeric' => 'El documento solo puede contener letras y números.',
            'is_unique'     => 'Ya existe un cliente registrado con ese documento.',
        ],
        'nombres' => [
            'required'   => 'El nombre del cliente es obligatorio.',
            'min_length' => 'El nombre debe tener al menos 3 caracteres.',
            'max_length' => 'El nombre no puede superar los 200 caracteres.',
        ],
        'telefono' => [
            'regex_match' => 'El teléfono solo puede contener números, espacios, guiones y el símbolo +.',
        ],
    ];

    protected $skipValidation = false;

    // ---------------------------------------------------------------
    // Métodos Personalizados
    // ---------------------------------------------------------------

    /**
     * Busca un cliente por su número de documento exacto.
     *
     * @param string $documento DNI, RUC u otro documento de identidad
     * @return array|null
     */
    public function getClientePorDocumento(string $documento): ?array
    {
        return $this->where('documento', $documento)->first();
    }

    /**
     * Busca clientes por nombre parcial o documento.
     * Usado en el buscador rápido del recepcionista.
     *
     * @param string $termino
     * @return array
     */
    public function buscarCliente(string $termino): array
    {
        return $this->groupStart()
            ->like('nombres', $termino, 'both')
            ->orLike('documento', $termino, 'both')
            ->orLike('telefono', $termino, 'both')
            ->groupEnd()
            ->orderBy('nombres', 'ASC')
            ->findAll();
    }

    /**
     * Obtiene el historial de pedidos de un cliente.
     *
     * @param int $idCliente
     * @return array
     */
    public function getHistorialPedidos(int $idCliente): array
    {
        return $this->db->table('Pedido p')
            ->select('p.idPedido, p.codigoTicket, p.fechaRecepcion, p.fechaEntrega, p.estado, p.total')
            ->where('p.idCliente', $idCliente)
            ->orderBy('p.fechaRecepcion', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Retorna los datos del cliente formateados para la impresión del ticket.
     *
     * @param int $idCliente
     * @return array|null
     */
    public function getDatosTicket(int $idCliente): ?array
    {
        $cliente = $this->find($idCliente);

        if (! $cliente) {
            return null;
        }

        return [
            'documento' => $cliente['documento'],
            'nombres'   => strtoupper($cliente['nombres']),
            'telefono'  => $cliente['telefono'] ?? '—',
            'direccion' => $cliente['direccion'] ?? '—',
        ];
    }
}
