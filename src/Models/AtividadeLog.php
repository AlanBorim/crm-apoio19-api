<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class AtividadeLog
{
    public int $id;
    public ?int $tarefa_id;
    public ?int $coluna_id;
    public int $usuario_id;
    public string $acao;
    public string $descricao;
    public ?string $valor_antigo;
    public ?string $valor_novo;
    public string $criado_em;

    // Propriedades para dados relacionados
    public ?string $usuario_nome;
    public ?string $usuario_email;
    public ?string $tarefa_titulo;
    public ?string $coluna_nome;

    /**
     * Criar um novo log de atividade.
     *
     * @param array $data
     * @return int|false
     */
    public static function create(array $data): int|false
    {
        $sql = "INSERT INTO atividade_logs 
                (tarefa_id, coluna_id, usuario_id, acao, descricao, valor_antigo, valor_novo) 
                VALUES (:tarefa_id, :coluna_id, :usuario_id, :acao, :descricao, :valor_antigo, :valor_novo)";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            
            $stmt->bindValue(":tarefa_id", $data["tarefa_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":coluna_id", $data["coluna_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":usuario_id", $data["usuario_id"], PDO::PARAM_INT);
            $stmt->bindValue(":acao", $data["acao"]);
            $stmt->bindValue(":descricao", $data["descricao"]);
            $stmt->bindValue(":valor_antigo", isset($data["valor_antigo"]) ? json_encode($data["valor_antigo"]) : null);
            $stmt->bindValue(":valor_novo", isset($data["valor_novo"]) ? json_encode($data["valor_novo"]) : null);
            
            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erro ao criar log de atividade: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Buscar logs com filtros e paginação.
     *
     * @param array $filters
     * @param int $page
     * @param int $limit
     * @return array
     */
    public static function findAll(array $filters = [], int $page = 1, int $limit = 50): array
    {
        $whereClauses = [];
        $params = [];

        if (isset($filters["tarefa_id"])) {
            $whereClauses[] = "al.tarefa_id = :tarefa_id";
            $params[":tarefa_id"] = $filters["tarefa_id"];
        }

        if (isset($filters["coluna_id"])) {
            $whereClauses[] = "al.coluna_id = :coluna_id";
            $params[":coluna_id"] = $filters["coluna_id"];
        }

        if (isset($filters["usuario_id"])) {
            $whereClauses[] = "al.usuario_id = :usuario_id";
            $params[":usuario_id"] = $filters["usuario_id"];
        }

        if (isset($filters["acao"])) {
            $whereClauses[] = "al.acao = :acao";
            $params[":acao"] = $filters["acao"];
        }

        $whereClause = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $offset = ($page - 1) * $limit;
        $params[":limit"] = $limit;
        $params[":offset"] = $offset;

        $sql = "SELECT al.*, 
                       u.nome as usuario_nome, 
                       u.email as usuario_email,
                       t.titulo as tarefa_titulo,
                       kc.nome as coluna_nome
                FROM atividade_logs al
                LEFT JOIN usuarios u ON al.usuario_id = u.id
                LEFT JOIN tarefas t ON al.tarefa_id = t.id
                LEFT JOIN kanban_colunas kc ON al.coluna_id = kc.id
                {$whereClause}
                ORDER BY al.criado_em DESC
                LIMIT :limit OFFSET :offset";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                if ($key === ":limit" || $key === ":offset") {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
        } catch (PDOException $e) {
            error_log("Erro ao buscar logs de atividade: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Contar total de logs com filtros.
     *
     * @param array $filters
     * @return int
     */
    public static function count(array $filters = []): int
    {
        $whereClauses = [];
        $params = [];

        if (isset($filters["tarefa_id"])) {
            $whereClauses[] = "tarefa_id = :tarefa_id";
            $params[":tarefa_id"] = $filters["tarefa_id"];
        }

        if (isset($filters["coluna_id"])) {
            $whereClauses[] = "coluna_id = :coluna_id";
            $params[":coluna_id"] = $filters["coluna_id"];
        }

        if (isset($filters["usuario_id"])) {
            $whereClauses[] = "usuario_id = :usuario_id";
            $params[":usuario_id"] = $filters["usuario_id"];
        }

        if (isset($filters["acao"])) {
            $whereClauses[] = "acao = :acao";
            $params[":acao"] = $filters["acao"];
        }

        $whereClause = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $sql = "SELECT COUNT(*) FROM atividade_logs {$whereClause}";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erro ao contar logs de atividade: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Buscar logs de uma tarefa específica.
     *
     * @param int $tarefaId
     * @param int $limit
     * @return array
     */
    public static function findByTarefa(int $tarefaId, int $limit = 50): array
    {
        return self::findAll(["tarefa_id" => $tarefaId], 1, $limit);
    }

    /**
     * Buscar logs de uma coluna específica.
     *
     * @param int $colunaId
     * @param int $limit
     * @return array
     */
    public static function findByColuna(int $colunaId, int $limit = 50): array
    {
        return self::findAll(["coluna_id" => $colunaId], 1, $limit);
    }

    /**
     * Deletar logs antigos (manutenção).
     *
     * @param int $days Número de dias para manter
     * @return bool
     */
    public static function deleteOlderThan(int $days): bool
    {
        $sql = "DELETE FROM atividade_logs WHERE criado_em < DATE_SUB(NOW(), INTERVAL :days DAY)";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":days", $days, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar logs antigos: " . $e->getMessage());
            return false;
        }
    }
}

