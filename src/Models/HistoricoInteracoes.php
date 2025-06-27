<?php

namespace Apoio19\Crm\Models;

use PDOException;
use Apoio19\Crm\Models\Database;

class HistoricoInteracoes
{
    public int $id;
    public int $lead_id;
    public ?int $contato_id;
    public int $usuario_id;
    public string $acao;
    public ?string $observacao;
    public string $data;

    public function __construct(array $data)
    {
        $this->lead_id     = (int)($data['lead_id'] ?? 0);
        $this->contato_id  = isset($data['contato_id']) ? (int)$data['contato_id'] : null;
        $this->usuario_id  = (int)($data['usuario_id'] ?? 0);
        $this->acao        = $data['acao'] ?? '';
        $this->observacao  = $data['observacao'] ?? null;
        $this->data        = $data['data'] ?? date('Y-m-d H:i:s');
    }

    public static function logAction(int $leadId, ?int $contatoId, int $usuarioId, string $acao, ?string $observacao = null): bool
    {
        try {
            $db = Database::getInstance();

            $stmt = $db->prepare("
                INSERT INTO historico_interacoes 
                (lead_id, contato_id, usuario_id, acao, observacao, data)
                VALUES 
                (:lead_id, :contato_id, :usuario_id, :acao, :observacao, :data)
            ");

            return $stmt->execute([
                'lead_id'    => $leadId,
                'contato_id' => $contatoId,
                'usuario_id' => $usuarioId,
                'acao'       => $acao,
                'observacao' => $observacao,
                'data'       => date('Y-m-d H:i:s')
            ]);
        } catch (PDOException $e) {
            error_log("Erro ao registrar histórico: " . $e->getMessage());
            return false;
        }
    }
}
