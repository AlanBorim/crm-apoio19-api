<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class TarefaResponsavel
{
    public int $id;
    public int $tarefa_id;
    public int $usuario_id;
    public string $criado_em;

    // Propriedades para dados relacionados
    public ?string $usuario_nome;
    public ?string $usuario_email;

    /**
     * Buscar todos os responsáveis de uma tarefa.
     *
     * @param int $tarefaId
     * @return array Array de objetos User.
     */
    public static function findByTarefa(int $tarefaId): array
    {
        $sql = "SELECT u.id, u.nome, u.email, u.funcao as role
                FROM tarefa_responsaveis tr
                INNER JOIN usuarios u ON tr.usuario_id = u.id
                WHERE tr.tarefa_id = :tarefa_id
                ORDER BY u.nome ASC";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar responsáveis da tarefa {$tarefaId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Adicionar um responsável a uma tarefa.
     *
     * @param int $tarefaId
     * @param int $usuarioId
     * @return bool
     */
    public static function add(int $tarefaId, int $usuarioId): bool
    {
        $sql = "INSERT IGNORE INTO tarefa_responsaveis (tarefa_id, usuario_id) 
                VALUES (:tarefa_id, :usuario_id)";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $usuarioId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao adicionar responsável à tarefa {$tarefaId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remover um responsável de uma tarefa.
     *
     * @param int $tarefaId
     * @param int $usuarioId
     * @return bool
     */
    public static function remove(int $tarefaId, int $usuarioId): bool
    {
        $sql = "DELETE FROM tarefa_responsaveis 
                WHERE tarefa_id = :tarefa_id AND usuario_id = :usuario_id";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $usuarioId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao remover responsável da tarefa {$tarefaId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remover todos os responsáveis de uma tarefa.
     *
     * @param int $tarefaId
     * @return bool
     */
    public static function removeAll(int $tarefaId): bool
    {
        $sql = "DELETE FROM tarefa_responsaveis WHERE tarefa_id = :tarefa_id";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao remover todos responsáveis da tarefa {$tarefaId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualizar todos os responsáveis de uma tarefa.
     *
     * @param int $tarefaId
     * @param array $usuarioIds Array de IDs de usuários
     * @return bool
     */
    public static function updateAll(int $tarefaId, array $usuarioIds): bool
    {
        try {
            $pdo = Database::getInstance();
            $pdo->beginTransaction();

            // Remover todos os responsáveis atuais
            self::removeAll($tarefaId);

            // Adicionar novos responsáveis
            foreach ($usuarioIds as $usuarioId) {
                if (!self::add($tarefaId, (int)$usuarioId)) {
                    throw new \Exception("Falha ao adicionar responsável ID: {$usuarioId}");
                }
            }

            return $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao atualizar responsáveis da tarefa {$tarefaId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verificar se um usuário é responsável por uma tarefa.
     *
     * @param int $tarefaId
     * @param int $usuarioId
     * @return bool
     */
    public static function isResponsavel(int $tarefaId, int $usuarioId): bool
    {
        $sql = "SELECT COUNT(*) FROM tarefa_responsaveis 
                WHERE tarefa_id = :tarefa_id AND usuario_id = :usuario_id";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":tarefa_id", $tarefaId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $usuarioId, PDO::PARAM_INT);
            $stmt->execute();
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Erro ao verificar responsável da tarefa {$tarefaId}: " . $e->getMessage());
            return false;
        }
    }
}

