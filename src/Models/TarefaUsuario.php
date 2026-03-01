<?php

namespace Apoio19\Crm\Models;

use PDO;
use PDOException;
use Apoio19\Crm\Models\Database;

class TarefaUsuario
{
    public int $id;
    public string $titulo;
    public ?string $descricao;
    public ?string $data_vencimento;
    public string $prioridade;
    public string $status;
    public int $usuario_id;
    public ?int $lead_id;
    public string $created_at;
    public string $updated_at;
    public ?string $deleted_at = null;

    /**
     * Listar todas as tarefas (para admin)
     */
    public static function all()
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT t.*, u.name as usuario_nome, l.name as lead_nome 
                             FROM tarefas_usuario t 
                             LEFT JOIN users u ON t.usuario_id = u.id 
                             LEFT JOIN leads l ON t.lead_id = l.id 
                             ORDER BY t.created_at DESC");
        return $stmt->fetchAll();
    }

    /**
     * Listar tarefas de um usuário específico
     */
    public static function getByUserId(int $userId)
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT t.*, l.name as lead_nome 
                               FROM tarefas_usuario t 
                               LEFT JOIN leads l ON t.lead_id = l.id 
                               WHERE t.usuario_id = :usuario_id 
                               ORDER BY t.created_at DESC");
        $stmt->execute([':usuario_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Buscar tarefa por ID
     */
    public static function find(int $id)
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM tarefas_usuario WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetchObject(self::class);
    }

    /**
     * Criar nova tarefa
     */
    public static function create(array $data)
    {
        $pdo = Database::getInstance();
        $sql = "INSERT INTO tarefas_usuario (titulo, descricao, data_vencimento, prioridade, status, usuario_id, lead_id) 
                VALUES (:titulo, :descricao, :data_vencimento, :prioridade, :status, :usuario_id, :lead_id)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':titulo' => $data['titulo'],
            ':descricao' => $data['descricao'] ?? null,
            ':data_vencimento' => $data['data_vencimento'] ?? null,
            ':prioridade' => $data['prioridade'] ?? 'media',
            ':status' => $data['status'] ?? 'pendente',
            ':usuario_id' => $data['usuario_id'],
            ':lead_id' => $data['lead_id'] ?? null
        ]);

        return $pdo->lastInsertId();
    }

    /**
     * Atualizar tarefa
     */
    public static function update(int $id, array $data)
    {
        $pdo = Database::getInstance();

        $fields = [];
        $params = [':id' => $id];

        if (isset($data['titulo'])) {
            $fields[] = "titulo = :titulo";
            $params[':titulo'] = $data['titulo'];
        }
        if (isset($data['descricao'])) {
            $fields[] = "descricao = :descricao";
            $params[':descricao'] = $data['descricao'];
        }
        if (array_key_exists('data_vencimento', $data)) {
            $fields[] = "data_vencimento = :data_vencimento";
            $params[':data_vencimento'] = $data['data_vencimento'];
        }
        if (isset($data['prioridade'])) {
            $fields[] = "prioridade = :prioridade";
            $params[':prioridade'] = $data['prioridade'];
        }
        if (isset($data['status'])) {
            $fields[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        if (array_key_exists('lead_id', $data)) {
            $fields[] = "lead_id = :lead_id";
            $params[':lead_id'] = $data['lead_id'];
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE tarefas_usuario SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public static function delete(int $id)
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE tarefas_usuario SET deleted_at = NOW() WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Restaurar tarefa
     */
    public static function restore(int $id)
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE tarefas_usuario SET deleted_at = NULL WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
