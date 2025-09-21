<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class AuditLogService
{
    /**
     * Registra um log de auditoria.
     *
     * @param string $acao               Ação executada (insert, update, delete, etc.)
     * @param int|null $usuarioId        ID do usuário responsável
     * @param string|null $tabelaNome    Nome da tabela afetada
     * @param int|null $registroId       ID do registro afetado
     * @param array|object|null $antigos Dados antigos (será convertido para JSON)
     * @param array|object|null $novos   Dados novos (será convertido para JSON)
     * @param string|null $ipAddress     Endereço IP (opcional, capturado automaticamente se null)
     * @param string|null $userAgent     User-Agent (opcional, capturado automaticamente se null)
     * @return bool                      true em caso de sucesso, false em caso de erro
     */
    public static function log(
        ?int $usuarioId = null,
        string $acao,
        ?string $tabelaNome = null,
        ?int $registroId = null,
        array|object|null $antigos = null,
        array|object|null $novos = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): bool {
        $sql = "INSERT INTO audit_logs (
                user_id,
                action,
                table_name,
                record_id,
                old_values,
                new_values,
                ip_address,
                user_agent
            ) VALUES (
                :user_id,
                :action,
                :table_name,
                :record_id,
                :old_values,
                :new_values,
                :ip_address,
                :user_agent
            )";

        // Captura IP se não foi fornecido
        $ipAddress ??= $_SERVER['REMOTE_ADDR'] ?? null;

        // Captura User-Agent se não foi fornecido
        $userAgent ??= $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Converte arrays/objetos para JSON
        $oldJson = $antigos !== null ? json_encode($antigos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
        $newJson = $novos   !== null ? json_encode($novos,   JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            $stmt->bindParam(':user_id',     $usuarioId,  PDO::PARAM_INT);
            $stmt->bindParam(':action',      $acao);
            $stmt->bindParam(':table_name',  $tabelaNome);
            $stmt->bindParam(':record_id',   $registroId, PDO::PARAM_INT);
            $stmt->bindParam(':old_values',  $oldJson);
            $stmt->bindParam(':new_values',  $newJson);
            $stmt->bindParam(':ip_address',  $ipAddress);
            $stmt->bindParam(':user_agent',  $userAgent);

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log('Falha ao registrar log de auditoria: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get audit logs based on filters.
     *
     * @param array $filters Associative array of filters (e.g., ["usuario_id" => 1, "entidade_tipo" => "lead"]).
     * @param int $limit Maximum number of logs to return.
     * @param int $offset Offset for pagination.
     * @param string $orderBy SQL ORDER BY clause.
     * @return array Array of log entries.
     */
    public static function findBy(array $filters = [], int $limit = 50, int $offset = 0, string $orderBy = "timestamp DESC"): array
    {
        $sql = "SELECT a.*, u.nome as usuario_nome, u.email as usuario_email 
                FROM audit_logs a 
                LEFT JOIN usuarios u ON a.usuario_id = u.id";

        $whereClauses = [];
        $params = [];

        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                // Basic security: ensure key is a valid column name
                if (in_array($key, ["id", "usuario_id", "acao", "entidade_tipo", "entidade_id", "ip_address"])) {
                    $paramName = ":" . $key;
                    $whereClauses[] = "a." . $key . " = " . $paramName;
                    $params[$paramName] = $value;
                } elseif ($key === "data_inicio" && $value) {
                    $whereClauses[] = "a.timestamp >= :data_inicio";
                    $params[":data_inicio"] = $value; // Expects YYYY-MM-DD HH:MM:SS
                } elseif ($key === "data_fim" && $value) {
                    $whereClauses[] = "a.timestamp <= :data_fim";
                    $params[":data_fim"] = $value; // Expects YYYY-MM-DD HH:MM:SS
                }
            }
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(" AND ", $whereClauses);
            }
        }

        // Validate orderBy to prevent SQL injection
        $allowedOrderBy = ["timestamp DESC", "timestamp ASC", "usuario_id ASC", "usuario_id DESC", "acao ASC", "acao DESC"];
        if (!in_array($orderBy, $allowedOrderBy)) {
            $orderBy = "timestamp DESC"; // Default safe order
        }
        $sql .= " ORDER BY " . $orderBy;

        $sql .= " LIMIT :limit OFFSET :offset";
        $params[":limit"] = $limit;
        $params[":offset"] = $offset;

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            // Bind parameters with correct types
            foreach ($params as $key => &$val) {
                $type = (str_ends_with($key, "_id") || $key === ":limit" || $key === ":offset") ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindParam($key, $val, $type);
            }
            unset($val); // Unset reference

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar logs de auditoria: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the total count of audit logs based on filters.
     *
     * @param array $filters Associative array of filters.
     * @return int Total count.
     */
    public static function countBy(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM audit_logs a";
        $whereClauses = [];
        $params = [];

        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                if (in_array($key, ["id", "usuario_id", "acao", "entidade_tipo", "entidade_id", "ip_address"])) {
                    $paramName = ":" . $key;
                    $whereClauses[] = "a." . $key . " = " . $paramName;
                    $params[$paramName] = $value;
                } elseif ($key === "data_inicio" && $value) {
                    $whereClauses[] = "a.timestamp >= :data_inicio";
                    $params[":data_inicio"] = $value;
                } elseif ($key === "data_fim" && $value) {
                    $whereClauses[] = "a.timestamp <= :data_fim";
                    $params[":data_fim"] = $value;
                }
            }
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(" AND ", $whereClauses);
            }
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erro ao contar logs de auditoria: " . $e->getMessage());
            return 0;
        }
    }
}
