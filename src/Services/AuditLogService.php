<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class AuditLogService
{
    /**
     * Log an action performed in the system.
     *
     * @param string $acao Description of the action (e.g., "login_sucesso", "lead_criado").
     * @param int|null $usuarioId ID of the user performing the action (null for system actions).
     * @param string|null $entidadeTipo Type of the entity affected (e.g., "lead", "proposta").
     * @param int|null $entidadeId ID of the entity affected.
     * @param string|null $detalhes Additional details (e.g., changed fields, error messages).
     * @param string|null $ipAddress IP address of the user.
     * @return bool True on success, false on failure.
     */
    public static function log(
        string $acao,
        ?int $usuarioId = null,
        ?string $entidadeTipo = null,
        ?int $entidadeId = null,
        ?string $detalhes = null,
        ?string $ipAddress = null
    ): bool
    {
        $sql = "INSERT INTO audit_logs (usuario_id, acao, entidade_tipo, entidade_id, detalhes, ip_address) 
                VALUES (:usuario_id, :acao, :entidade_tipo, :entidade_id, :detalhes, :ip_address)";

        // Attempt to get IP address if not provided
        if ($ipAddress === null) {
            $ipAddress = $_SERVER["REMOTE_ADDR"] ?? null;
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            $stmt->bindParam(":usuario_id", $usuarioId, PDO::PARAM_INT);
            $stmt->bindParam(":acao", $acao);
            $stmt->bindParam(":entidade_tipo", $entidadeTipo);
            $stmt->bindParam(":entidade_id", $entidadeId, PDO::PARAM_INT);
            $stmt->bindParam(":detalhes", $detalhes);
            $stmt->bindParam(":ip_address", $ipAddress);

            return $stmt->execute();

        } catch (PDOException $e) {
            // Log the error to the system error log, but don't stop the application flow
            error_log("Falha ao registrar log de auditoria: " . $e->getMessage());
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

