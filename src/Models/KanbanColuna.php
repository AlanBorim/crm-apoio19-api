<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class KanbanColuna
{
    public int $id;
    public string $nome;
    public int $ordem;
    public string $criado_em;
    public string $atualizado_em;

    /**
     * Find all Kanban columns, ordered by 'ordem'.
     *
     * @return array Array of KanbanColuna objects.
     */
    public static function findAll(): array
    {
        $sql = "SELECT * FROM kanban_colunas ORDER BY ordem ASC";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
        } catch (PDOException $e) {
            error_log("Erro ao buscar colunas Kanban: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Find a Kanban column by its ID.
     *
     * @param int $id
     * @return KanbanColuna|null
     */
    public static function findById(int $id): ?KanbanColuna
    {
        $sql = "SELECT * FROM kanban_colunas WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetchObject(self::class);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar coluna Kanban ID {$id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new Kanban column.
     *
     * @param string $nome
     * @param int $ordem
     * @return int|false The ID of the new column or false on failure.
     */
    public static function create(string $nome, int $ordem): int|false
    {
        $sql = "INSERT INTO kanban_colunas (nome, ordem) VALUES (:nome, :ordem)";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":nome", $nome);
            $stmt->bindParam(":ordem", $ordem, PDO::PARAM_INT);
            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erro ao criar coluna Kanban: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing Kanban column.
     *
     * @param int $id
     * @param string $nome
     * @param int $ordem
     * @return bool True on success, false on failure.
     */
    public static function update(int $id, string $nome, int $ordem): bool
    {
        $sql = "UPDATE kanban_colunas SET nome = :nome, ordem = :ordem, atualizado_em = NOW() WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":nome", $nome);
            $stmt->bindParam(":ordem", $ordem, PDO::PARAM_INT);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar coluna Kanban ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a Kanban column.
     * Note: Consider how to handle tasks in the deleted column (e.g., move to another column, delete them?).
     * Current implementation just deletes the column.
     *
     * @param int $id
     * @return bool True on success, false on failure.
     */
    public static function delete(int $id): bool
    {
        // Before deleting, potentially move tasks to a default column or handle them
        // Example: UPDATE tarefas SET kanban_coluna_id = NULL WHERE kanban_coluna_id = :id;

        $sql = "DELETE FROM kanban_colunas WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar coluna Kanban ID {$id}: " . $e->getMessage());
            return false;
        }
    }
}

