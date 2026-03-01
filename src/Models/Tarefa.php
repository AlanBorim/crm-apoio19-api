<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use Apoio19\Crm\Models\TarefaResponsavel;
use Apoio19\Crm\Models\TarefaComentario;
use \PDO;
use \PDOException;

class Tarefa
{
    public $id;
    public $titulo;
    public $descricao;
    public $kanban_coluna_id;
    public $responsavel_id;
    public $criador_id;
    public $lead_id;
    public $contato_id;
    public $proposta_id;
    public $data_vencimento;
    public $prioridade;
    public $concluida;
    public $data_conclusao;
    public $ordem_na_coluna;
    public $tags;
    public $criado_em;
    public $atualizado_em;
    public $deleted_at;

    // Propriedades para dados relacionados
    public $responsavel_nome;
    public $criador_nome;
    public $kanban_coluna_nome;

    // Propriedades para relações carregadas dinamicamente
    public $responsaveis;
    public $comentarios;
    public $tags_array;

    /**
     * Buscar tarefas com filtros.
     *
     * @param array $filters
     * @param string $orderBy
     * @return array
     */
    public static function findBy(array $filters = [], string $orderBy = "ordem_na_coluna ASC")
    {
        $sql = "SELECT t.*, 
                       kc.nome as kanban_coluna_nome, 
                       u_resp.name as responsavel_nome, 
                       u_criador.name as criador_nome
                FROM tarefas t
                LEFT JOIN kanban_colunas kc ON t.kanban_coluna_id = kc.id
                LEFT JOIN users u_resp ON t.responsavel_id = u_resp.id
                LEFT JOIN users u_criador ON t.criador_id = u_criador.id";

        $whereClauses = ["t.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                if (in_array($key, ["id", "kanban_coluna_id", "responsavel_id", "criador_id", "lead_id", "contato_id", "proposta_id", "prioridade", "concluida"])) {
                    $paramName = ":" . $key;
                    $whereClauses[] = "t." . $key . " = " . $paramName;
                    $params[$paramName] = $value;
                } elseif ($key === "titulo_like" && is_string($value)) {
                    $whereClauses[] = "t.titulo LIKE :titulo_like";
                    $params[":titulo_like"] = "%" . $value . "%";
                }
            }
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(" AND ", $whereClauses);
            }
        }

        $sql .= " ORDER BY " . $orderBy;

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $tarefas = $stmt->fetchAll(PDO::FETCH_CLASS, self::class);

            // Adicionar responsáveis e comentários para cada tarefa
            foreach ($tarefas as $tarefa) {
                $tarefa->loadRelations();
            }

            return $tarefas;
        } catch (PDOException $e) {
            error_log("Erro ao buscar tarefas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Buscar uma tarefa por ID.
     *
     * @param int $id
     * @return Tarefa|null
     */
    public static function findById(int $id)
    {
        error_log("DEBUG: findById called for ID: " . $id);
        $sql = "SELECT t.*, 
                       kc.nome as kanban_coluna_nome, 
                       u_resp.name as responsavel_nome, 
                       u_criador.name as criador_nome
                FROM tarefas t
                LEFT JOIN kanban_colunas kc ON t.kanban_coluna_id = kc.id
                LEFT JOIN users u_resp ON t.responsavel_id = u_resp.id
                LEFT JOIN users u_criador ON t.criador_id = u_criador.id
                WHERE t.id = :id AND t.deleted_at IS NULL";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);

            error_log("DEBUG: Executing query...");
            $stmt->execute();

            error_log("DEBUG: Fetching data...");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                error_log("DEBUG: Data found. Hydrating...");
                $tarefa = new self();
                foreach ($data as $key => $value) {
                    if (property_exists($tarefa, $key)) {
                        $tarefa->$key = $value;
                    }
                }
                error_log("DEBUG: Loading relations...");
                $tarefa->loadRelations();
                error_log("DEBUG: Returning object.");
                return $tarefa;
            } else {
                error_log("DEBUG: No data found for ID: " . $id);
            }

            return null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar tarefa ID {$id}: " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            error_log("Erro genérico em findById ID {$id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Carregar relações (responsáveis e comentários).
     */
    public function loadRelations()
    {
        // Carregar responsáveis
        $this->responsaveis = TarefaResponsavel::findByTarefa($this->id);

        // Carregar comentários
        $this->comentarios = TarefaComentario::findByTaskId($this->id);

        // Decodificar tags JSON
        if ($this->tags) {
            $this->tags_array = json_decode($this->tags, true) ?? [];
        } else {
            $this->tags_array = [];
        }
    }

    /**
     * Criar uma nova tarefa.
     *
     * @param array $data
     * @return int|false
     */
    public static function create(array $data)
    {
        $sql = "INSERT INTO tarefas (titulo, descricao, kanban_coluna_id, responsavel_id, criador_id, lead_id, contato_id, proposta_id, data_vencimento, prioridade, ordem_na_coluna, tags) 
                VALUES (:titulo, :descricao, :kanban_coluna_id, :responsavel_id, :criador_id, :lead_id, :contato_id, :proposta_id, :data_vencimento, :prioridade, :ordem_na_coluna, :tags)";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            $ordem = $data["ordem_na_coluna"] ?? self::getNextOrderInColumn($data["kanban_coluna_id"] ?? null);

            // Se responsavel_id não foi enviado, mas responsaveis sim, usar o primeiro como principal
            if (empty($data["responsavel_id"]) && !empty($data["responsaveis"]) && is_array($data["responsaveis"])) {
                $data["responsavel_id"] = $data["responsaveis"][0];
            }

            // Processar tags
            $tags = null;
            if (isset($data["tags"]) && is_array($data["tags"])) {
                $tags = json_encode($data["tags"]);
            }

            $stmt->bindParam(":titulo", $data["titulo"]);
            $stmt->bindValue(":descricao", $data["descricao"] ?? null);
            $stmt->bindValue(":kanban_coluna_id", $data["kanban_coluna_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":responsavel_id", $data["responsavel_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":criador_id", $data["criador_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":lead_id", $data["lead_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":contato_id", $data["contato_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":proposta_id", $data["proposta_id"] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":data_vencimento", $data["data_vencimento"] ?? null);
            $stmt->bindValue(":prioridade", $data["prioridade"] ?? "media");
            $stmt->bindValue(":ordem_na_coluna", $ordem, PDO::PARAM_INT);
            $stmt->bindValue(":tags", $tags);

            if ($stmt->execute()) {
                $tarefaId = (int)$pdo->lastInsertId();

                // Adicionar responsáveis se fornecidos
                if (isset($data["responsaveis"]) && is_array($data["responsaveis"])) {
                    TarefaResponsavel::updateAll($tarefaId, $data["responsaveis"]);
                }

                return $tarefaId;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erro ao criar tarefa: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualizar uma tarefa.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        $setClauses = [];
        $params = [":id" => $id];

        $allowedFields = ["titulo", "descricao", "kanban_coluna_id", "responsavel_id", "lead_id", "contato_id", "proposta_id", "data_vencimento", "prioridade", "concluida", "data_conclusao", "ordem_na_coluna", "tags"];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $paramName = ":" . $key;
                $setClauses[] = $key . " = " . $paramName;

                if ($key === "concluida") {
                    $params[$paramName] = (bool)$value;
                } elseif ($key === "tags" && is_array($value)) {
                    $params[$paramName] = json_encode($value);
                } else {
                    $params[$paramName] = $value;
                }
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $sql = "UPDATE tarefas SET " . implode(", ", $setClauses) . ", atualizado_em = NOW() WHERE id = :id";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute($params);

            // Atualizar responsáveis se fornecidos
            if ($success && isset($data["responsaveis"]) && is_array($data["responsaveis"])) {
                TarefaResponsavel::updateAll($id, $data["responsaveis"]);
            }

            return $success;
        } catch (PDOException $e) {
            error_log("Erro ao atualizar tarefa ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletar uma tarefa (Soft Delete).
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        $sql = "UPDATE tarefas SET deleted_at = NOW() WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar (soft delete) tarefa ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Restaurar uma tarefa excluída.
     *
     * @param int $id
     * @return bool
     */
    public static function restore(int $id): bool
    {
        $sql = "UPDATE tarefas SET deleted_at = NULL WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao restaurar tarefa ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obter próxima ordem na coluna.
     *
     * @param int|null $kanbanColunaId
     * @return int
     */
    private static function getNextOrderInColumn(?int $kanbanColunaId): int
    {
        $sql = "SELECT MAX(ordem_na_coluna) FROM tarefas WHERE deleted_at IS NULL AND ";
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
            return 0;
        }
    }

    /**
     * Atualizar ordem das tarefas.
     *
     * @param array $taskOrder
     * @param int $kanbanColunaId
     * @return bool
     */
    public static function updateTaskOrder(array $taskOrder, int $kanbanColunaId): bool
    {
        if (empty($taskOrder)) {
            return true;
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
