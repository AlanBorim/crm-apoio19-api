<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class TarefaComentario
{
    public int $id;
    public int $tarefa_id;
    public int $usuario_id;
    public string $conteudo;
    public string $criado_em;
    public string $atualizado_em;

    // Propriedades para dados relacionados
    public ?string $usuario_nome;
    public ?string $usuario_email;

    /**
     * Buscar todos os comentários de uma tarefa.
     *
     * @param int $tarefaId
     * @return array Array de objetos TarefaComentario.
     */
    public static function findByTaskId(int $tarefaId): array
    {
        $sql = "SELECT tc.*, u.name as usuario_nome, u.email as usuario_email
                FROM tarefa_comentarios tc
                LEFT JOIN users u ON tc.usuario_id = u.id
                WHERE tc.tarefa_id = :tarefa_id
                ORDER BY tc.criado_em ASC";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
        } catch (PDOException $e) {
            error_log("Erro ao buscar comentários da tarefa {$tarefaId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Buscar um comentário por ID.
     *
     * @param int $id
     * @return TarefaComentario|null
     */
    public static function findById(int $id): ?TarefaComentario
    {
        $sql = "SELECT tc.*, u.name as usuario_nome, u.email as usuario_email
                FROM tarefa_comentarios tc
                LEFT JOIN users u ON tc.usuario_id = u.id
                WHERE tc.id = :id";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetchObject(self::class);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar comentário ID {$id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Criar um novo comentário.
     *
     * @param int $tarefaId
     * @param int $usuarioId
     * @param string $conteudo
     * @return int|false ID do comentário criado ou false em caso de erro.
     */
    public static function create(int $tarefaId, int $usuarioId, string $conteudo): int|false
    {
        $sql = "INSERT INTO tarefa_comentarios (tarefa_id, usuario_id, conteudo) 
                VALUES (:tarefa_id, :usuario_id, :conteudo)";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $usuarioId, PDO::PARAM_INT);
            $stmt->bindParam(":conteudo", $conteudo);
            
            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erro ao criar comentário: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualizar um comentário.
     *
     * @param int $id
     * @param string $conteudo
     * @return bool
     */
    public static function update(int $id, string $conteudo): bool
    {
        $sql = "UPDATE tarefa_comentarios 
                SET conteudo = :conteudo, atualizado_em = NOW() 
                WHERE id = :id";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":conteudo", $conteudo);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar comentário ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletar um comentário.
     *
     * @param int $id
     * @return bool
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
     * Deletar todos os comentários de uma tarefa.
     *
     * @param int $tarefaId
     * @return bool
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
            error_log("Erro ao deletar comentários da tarefa {$tarefaId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Contar comentários de uma tarefa.
     *
     * @param int $tarefaId
     * @return int
     */
    public static function countByTarefa(int $tarefaId): int
    {
        $sql = "SELECT COUNT(*) FROM tarefa_comentarios WHERE tarefa_id = :tarefa_id";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erro ao contar comentários da tarefa {$tarefaId}: " . $e->getMessage());
            return 0;
        }
    }
}

