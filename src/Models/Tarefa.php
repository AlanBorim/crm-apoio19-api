<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Tarefa
{
    public int $id;
    public string $titulo;
    public ?string $descricao;
    public ?int $kanban_coluna_id;
    public ?int $responsavel_id;
    public ?int $criador_id;
    public ?int $lead_id;
    public ?int $contato_id;
    public ?int $proposta_id;
    public ?string $data_vencimento;
    public string $prioridade;
    public bool $concluida;
    public ?string $data_conclusao;
    public int $ordem_na_coluna;
    public string $criado_em;
    public string $atualizado_em;

    // Optional: Properties for joined data (like user names)
    public ?string $responsavel_nome;
    public ?string $criador_nome;
    public ?string $kanban_coluna_nome;

    /**
     * Find tasks based on criteria (e.g., by column, user, status).
     *
     * @param array $filters Associative array of filters (e.g., ["kanban_coluna_id" => 1, "responsavel_id" => 5]).
     * @param string $orderBy SQL ORDER BY clause (e.g., "ordem_na_coluna ASC").
     * @return array Array of Tarefa objects.
     */
    public static function findBy(array $filters = [], string $orderBy = "ordem_na_coluna ASC"): array
    {
        $sql = "SELECT t.*, 
                       kc.nome as kanban_coluna_nome, 
                       u_resp.nome as responsavel_nome, 
                       u_criador.nome as criador_nome
                FROM tarefas t
                LEFT JOIN kanban_colunas kc ON t.kanban_coluna_id = kc.id
                LEFT JOIN usuarios u_resp ON t.responsavel_id = u_resp.id
                LEFT JOIN usuarios u_criador ON t.criador_id = u_criador.id";
        
        $whereClauses = [];
        $params = [];

        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                // Basic security: ensure key is a valid column name (whitelist if needed)
                if (in_array($key, ["id", "kanban_coluna_id", "responsavel_id", "criador_id", "lead_id", "contato_id", "proposta_id", "prioridade", "concluida"])) {
                    $paramName = ":" . $key;
                    $whereClauses[] = "t." . $key . " = " . $paramName;
                    $params[$paramName] = $value;
                } elseif ($key === "titulo_like" && is_string($value)) {
                     $whereClauses[] = "t.titulo LIKE :titulo_like";
                     $params[":titulo_like"] = "%" . $value . "%";
                }
                // Add more filters as needed (date ranges, etc.)
            }
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(" AND ", $whereClauses);
            }
        }

        $sql .= " ORDER BY " . $orderBy; // Be cautious with user-provided $orderBy

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            // Fetch as Tarefa objects, properties like responsavel_nome will be populated if selected
            return $stmt->fetchAll(PDO::FETCH_CLASS, self::class); 
        } catch (PDOException $e) {
            error_log("Erro ao buscar tarefas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Find a single task by its ID.
     *
     * @param int $id
     * @return Tarefa|null
     */
    public static function findById(int $id): ?Tarefa
    {
         $sql = "SELECT t.*, 
                       kc.nome as kanban_coluna_nome, 
                       u_resp.nome as responsavel_nome, 
                       u_criador.nome as criador_nome
                FROM tarefas t
                LEFT JOIN kanban_colunas kc ON t.kanban_coluna_id = kc.id
                LEFT JOIN usuarios u_resp ON t.responsavel_id = u_resp.id
                LEFT JOIN usuarios u_criador ON t.criador_id = u_criador.id
                WHERE t.id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetchObject(self::class);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar tarefa ID {$id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new task.
     *
     * @param array $data Associative array of task data.
     * @return int|false The ID of the new task or false on failure.
     */
    public static function create(array $data): int|false
    {
        $sql = "INSERT INTO tarefas (titulo, descricao, kanban_coluna_id, responsavel_id, criador_id, lead_id, contato_id, proposta_id, data_vencimento, prioridade, ordem_na_coluna) 
                VALUES (:titulo, :descricao, :kanban_coluna_id, :responsavel_id, :criador_id, :lead_id, :contato_id, :proposta_id, :data_vencimento, :prioridade, :ordem_na_coluna)";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            // Set default order (e.g., place at the top/bottom of the column)
            $ordem = $data["ordem_na_coluna"] ?? self::getNextOrderInColumn($data["kanban_coluna_id"] ?? null);

            $stmt->bindParam(":titulo", $data["titulo"]);
            $stmt->bindValue(":descricao", $data["descricao"] ?? null);
            $stmt->bindValue(":kanban_coluna_id", $data["kanban_coluna_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":responsavel_id", $data["responsavel_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":criador_id", $data["criador_id"] ?? null, PDO::PARAM_INT); // Should likely be the logged-in user ID
            $stmt->bindValue(":lead_id", $data["lead_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":contato_id", $data["contato_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":proposta_id", $data["proposta_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":data_vencimento", $data["data_vencimento"] ?? null);
            $stmt->bindValue(":prioridade", $data["prioridade"] ?? "media");
            $stmt->bindValue(":ordem_na_coluna", $ordem, PDO::PARAM_INT);

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erro ao criar tarefa: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing task.
     *
     * @param int $id Task ID.
     * @param array $data Associative array of data to update.
     * @return bool True on success, false on failure.
     */
    public static function update(int $id, array $data): bool
    {
        $setClauses = [];
        $params = [":id" => $id];

        // Build SET clauses dynamically based on provided data
        $allowedFields = ["titulo", "descricao", "kanban_coluna_id", "responsavel_id", "lead_id", "contato_id", "proposta_id", "data_vencimento", "prioridade", "concluida", "data_conclusao", "ordem_na_coluna"];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $paramName = ":" . $key;
                $setClauses[] = $key . " = " . $paramName;
                $params[$paramName] = ($key === "concluida") ? (bool)$value : $value; // Cast boolean
            }
        }

        if (empty($setClauses)) {
            return false; // Nothing to update
        }

        $sql = "UPDATE tarefas SET " . implode(", ", $setClauses) . ", atualizado_em = NOW() WHERE id = :id";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar tarefa ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a task.
     *
     * @param int $id
     * @return bool True on success, false on failure.
     */
    public static function delete(int $id): bool
    {
        // Consider deleting associated comments first if needed, though CASCADE should handle it
        // TarefaComentario::deleteByTaskId($id);
        
        $sql = "DELETE FROM tarefas WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar tarefa ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the next available order value for a task within a specific column.
     *
     * @param int|null $kanbanColunaId
     * @return int
     */
    private static function getNextOrderInColumn(?int $kanbanColunaId): int
    {
        $sql = "SELECT MAX(ordem_na_coluna) FROM tarefas WHERE ";
        $params = [];
        if ($kanbanColunaId === null) {
            $sql .= "kanban_coluna_id IS NULL";
        } else {
            $sql .= "kanban_coluna_id = :coluna_id";
            $params[":coluna_id"] = $kanbanColunaId;
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $maxOrder = $stmt->fetchColumn();
            return ($maxOrder === false || $maxOrder === null) ? 0 : (int)$maxOrder + 1;
        } catch (PDOException $e) {
            error_log("Erro ao buscar próxima ordem na coluna: " . $e->getMessage());
            return 0; // Default to 0 on error
        }
    }
    
    /**
     * Update the order of multiple tasks, typically after a drag-and-drop operation.
     *
     * @param array $taskOrder An array where keys are task IDs and values are their new order index (0-based).
     * @param int $kanbanColunaId The ID of the column these tasks belong to.
     * @return bool True if all updates were successful, false otherwise.
     */
    public static function updateTaskOrder(array $taskOrder, int $kanbanColunaId): bool
    {
        if (empty($taskOrder)) {
            return true; // Nothing to do
        }

        $sql = "UPDATE tarefas SET ordem_na_coluna = :ordem, kanban_coluna_id = :coluna_id, atualizado_em = NOW() WHERE id = :id";
        $pdo = Database::getInstance();
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);

            foreach ($taskOrder as $taskId => $order) {
                $stmt->bindValue(":ordem", (int)$order, PDO::PARAM_INT);
                $stmt->bindValue(":coluna_id", $kanbanColunaId, PDO::PARAM_INT);
                $stmt->bindValue(":id", (int)$taskId, PDO::PARAM_INT);
                if (!$stmt->execute()) {
                    throw new PDOException("Falha ao atualizar ordem da tarefa ID: " . $taskId);
                }
            }

            return $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Erro ao atualizar ordem das tarefas na coluna {$kanbanColunaId}: " . $e->getMessage());
            return false;
        }
    }
}

