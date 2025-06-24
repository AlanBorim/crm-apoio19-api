<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class TarefaComentario
{
    public int $id;
    public int $tarefa_id;
    public ?int $usuario_id;
    public string $comentario;
    public string $data_comentario;

    // Optional: Property for joined user name
    public ?string $usuario_nome;

    /**
     * Find all comments for a specific task.
     *
     * @param int $tarefaId
     * @return array Array of TarefaComentario objects.
     */
    public static function findByTaskId(int $tarefaId): array
    {
        $sql = "SELECT tc.*, u.nome as usuario_nome 
                FROM tarefa_comentarios tc
                LEFT JOIN usuarios u ON tc.usuario_id = u.id
                WHERE tc.tarefa_id = :tarefa_id 
                ORDER BY tc.data_comentario ASC";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
        } catch (PDOException $e) {
            error_log("Erro ao buscar comentários da tarefa ID {$tarefaId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a new comment for a task.
     *
     * @param int $tarefaId
     * @param int $usuarioId ID of the user making the comment.
     * @param string $comentario Text of the comment.
     * @return int|false The ID of the new comment or false on failure.
     */
    public static function create(int $tarefaId, int $usuarioId, string $comentario): int|false
    {
        $sql = "INSERT INTO tarefa_comentarios (tarefa_id, usuario_id, comentario) VALUES (:tarefa_id, :usuario_id, :comentario)";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $usuarioId, PDO::PARAM_INT);
            $stmt->bindParam(":comentario", $comentario);
            
            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erro ao criar comentário na tarefa ID {$tarefaId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a comment by its ID.
     *
     * @param int $id Comment ID.
     * @return bool True on success, false on failure.
     */
    public static function delete(int $id): bool
    {
        $sql = "DELETE FROM tarefa_comentarios WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar comentário ID {$id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete all comments associated with a specific task ID.
     * Useful when deleting a task, although ON DELETE CASCADE should handle this.
     *
     * @param int $tarefaId
     * @return bool True on success, false on failure.
     */
    public static function deleteByTaskId(int $tarefaId): bool
    {
        $sql = "DELETE FROM tarefa_comentarios WHERE tarefa_id = :tarefa_id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar comentários da tarefa ID {$tarefaId}: " . $e->getMessage());
            return false;
        }
    }
    
    // Update functionality for comments is usually not implemented, 
    // but could be added if required.
    // public static function update(int $id, string $comentario): bool { ... }
}

