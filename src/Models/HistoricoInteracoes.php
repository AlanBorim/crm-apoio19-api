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
    public ?string $temperatura; // Added temperature property

    public static function logAction(int $leadId, ?int $contatoId, int $usuarioId, string $acao, ?string $observacao = null, ?string $temperatura = null): bool
    {
        try {
            $db = Database::getInstance();

            $stmt = $db->prepare("
                INSERT INTO historico_interacoes 
                (lead_id, contato_id, usuario_id, acao, observacao, data, temperatura)
                VALUES 
                (:lead_id, :contato_id, :usuario_id, :acao, :observacao, :data, :temperatura)
            ");

            return $stmt->execute([
                'lead_id'    => $leadId,
                'contato_id' => $contatoId,
                'usuario_id' => $usuarioId,
                'acao'       => $acao,
                'observacao' => $observacao,
                'data'       => date('Y-m-d H:i:s'),
                'temperatura' => $temperatura
            ]);
        } catch (PDOException $e) {
            error_log("Erro ao registrar histórico: " . $e->getMessage());
            return false;
        }
    }

    public function getHistoryByLeadId(int $leadId): array|bool
    {
        try {
            $db = Database::getInstance();

            $stmt = $db->prepare("
                SELECT
                    h.id,
                    h.lead_id,
                    h.contato_id,
                    u.name AS usuario_nome,
                    h.acao,
                    h.observacao,
                    h.data,
                    h.temperatura
                FROM historico_interacoes h
                LEFT JOIN users AS u ON u.id = h.usuario_id
                WHERE h.lead_id = :lead_id 
                ORDER BY data DESC
            ");

            $stmt->execute(['lead_id' => $leadId]);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!$result) {
                // Ocorreu um erro ao buscar os dados
                return false;
            }

            if (empty($result)) {
                // A consulta foi bem-sucedida, mas não retornou nenhum registro
                return [];
            }

            return $result;
        } catch (PDOException $e) {
            error_log("Erro ao obter histórico: " . $e->getMessage());
            return [];
        }
    }
}
