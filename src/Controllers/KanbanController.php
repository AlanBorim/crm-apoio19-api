<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\KanbanColuna;
use Apoio19\Crm\Models\Tarefa;
use Apoio19\Crm\Middleware\AuthMiddleware;

// Placeholder for Request/Response handling
class KanbanController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * Get all Kanban columns and their tasks.
     *
     * @param array $headers Request headers.
     * @param array $queryParams Optional filters for tasks (e.g., responsavel_id).
     * @return array JSON response.
     */
    public function getBoard(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Check permission
        $this->requirePermission($userData, 'kanban', 'view');

        try {
            $colunas = KanbanColuna::findAll();
            $boardData = [];

            // Prepare task filters (pass through relevant query params)
            $taskFilters = [];
            if (isset($queryParams["responsavel_id"])) {
                $taskFilters["responsavel_id"] = (int)$queryParams["responsavel_id"];
            }
            // Add other filters as needed (e.g., status, priority)

            foreach ($colunas as $coluna) {
                $columnFilters = $taskFilters; // Start with general filters
                $columnFilters["kanban_coluna_id"] = $coluna->id;

                $tasks = Tarefa::findBy($columnFilters, "ordem_na_coluna ASC");

                // Return raw column data matching database schema
                $boardData[] = [
                    "id" => (string)$coluna->id,
                    "nome" => $coluna->nome,
                    "ordem" => $coluna->ordem,
                    "cor" => $coluna->cor,
                    "limite_cards" => $coluna->limite_cards,
                    "tarefas" => $tasks // Frontend expects 'tarefas' with raw DB columns
                ];
            }

            http_response_code(200);
            return ["success" => true, "data" => $boardData];
        } catch (\Exception $e) {
            error_log("Erro ao buscar quadro Kanban: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao buscar quadro Kanban."];
        }
    }

    /**
     * Create a new Kanban column.
     *
     * @param array $headers Request headers.
     * @param array $requestData Expected keys: nome, ordem, cor (optional), limite_cards (optional).
     * @return array JSON response.
     */
    public function createColumn(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers); // Admins and managers can create columns
        if (!$userData) {
            http_response_code(403);
            return ["error" => "Acesso negado. Autenticação necessária."];
        }

        // Check permission (creating columns is an admin/manager task, maybe 'kanban.create' or 'kanban.edit')
        // Let's use 'kanban.create' if available, or 'kanban.edit' as it modifies the board structure.
        // Checking ROLE_PERMISSIONS: 'kanban' => ['view', 'create', 'edit', 'delete', 'assign']
        $this->requirePermission($userData, 'kanban', 'create');

        $nome = $requestData["nome"] ?? null;
        $ordem = isset($requestData["ordem"]) ? (int)$requestData["ordem"] : 0;
        $cor = $requestData["cor"] ?? null;
        $limite_cards = isset($requestData["limite_cards"]) ? (int)$requestData["limite_cards"] : null;

        if (!$nome) {
            http_response_code(400);
            return ["error" => "O nome da coluna é obrigatório."];
        }

        $columnId = KanbanColuna::create($nome, $ordem, $cor, $limite_cards);

        if ($columnId) {
            $newColumn = KanbanColuna::findById($columnId);

            // 🟢 AUDIT LOG - Log kanban column creation
            $this->logAudit($userData->id, 'create', 'kanban_colunas', $columnId, null, $newColumn);

            http_response_code(201);
            return ["message" => "Coluna Kanban criada com sucesso.", "coluna" => $newColumn];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao criar coluna Kanban."];
        }
    }


    /**
     * Update a Kanban column.
     *
     * @param array $headers Request headers.
     * @param int $columnId Column ID.
     * @param array $requestData Expected keys: nome, ordem.
     * @return array JSON response.
     */
    public function updateColumn(array $headers, int $columnId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers); // Only Admins?
        if (!$userData) {
            http_response_code(403);
            return ["error" => "Acesso negado. Autenticação necessária."];
        }

        // Check permission
        $this->requirePermission($userData, 'kanban', 'edit');

        $nome = $requestData["nome"] ?? null;
        $ordem = isset($requestData["ordem"]) ? (int)$requestData["ordem"] : null;
        $cor = $requestData["cor"] ?? null;
        $limite_cards = isset($requestData["limite_cards"]) ? (int)$requestData["limite_cards"] : null;

        // At least one field must be provided
        if ($nome === null && $ordem === null && $cor === null && $limite_cards === null) {
            http_response_code(400);
            return ["error" => "Nenhum dado fornecido para atualização."];
        }

        $column = KanbanColuna::findById($columnId);
        if (!$column) {
            http_response_code(404);
            return ["error" => "Coluna Kanban não encontrada."];
        }

        if (KanbanColuna::update($columnId, $nome, $ordem, $cor, $limite_cards)) {
            $updatedColumn = KanbanColuna::findById($columnId);

            // 🟢 AUDIT LOG - Log kanban column update
            $this->logAudit($userData->id, 'update', 'kanban_colunas', $columnId, $column, $updatedColumn);

            http_response_code(200);
            return ["message" => "Coluna Kanban atualizada com sucesso.", "coluna" => $updatedColumn];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao atualizar coluna Kanban."];
        }
    }

    /**
     * Delete a Kanban column.
     *
     * @param array $headers Request headers.
     * @param int $columnId Column ID.
     * @return array JSON response.
     */
    public function deleteColumn(array $headers, int $columnId): array
    {
        $userData = $this->authMiddleware->handle($headers); // Only Admins?
        if (!$userData) {
            http_response_code(403);
            return ["error" => "Acesso negado. Autenticação necessária."];
        }

        // Check permission
        $this->requirePermission($userData, 'kanban', 'delete');

        $column = KanbanColuna::findById($columnId);
        if (!$column) {
            http_response_code(404);
            return ["error" => "Coluna Kanban não encontrada."];
        }

        // Add logic here to handle tasks in the column before deletion
        // E.g., Check if column is empty, or move tasks to a default column.
        $tasksInColumn = Tarefa::findBy(["kanban_coluna_id" => $columnId]);
        if (!empty($tasksInColumn)) {
            http_response_code(400); // Bad Request or Conflict (409)
            return ["error" => "Não é possível excluir a coluna pois ela contém tarefas. Mova as tarefas primeiro."];
        }

        if (KanbanColuna::delete($columnId)) {
            // 🟢 AUDIT LOG - Log kanban column deletion
            $this->logAudit($userData->id, 'delete', 'kanban_colunas', $columnId, $column, null);

            http_response_code(200); // Or 204
            return ["message" => "Coluna Kanban excluída com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao excluir coluna Kanban."];
        }
    }

    /**
     * Update the order of tasks within and between columns.
     *
     * @param array $headers Request headers.
     * @param array $requestData Expected format: [ { "columnId": 1, "taskIds": [3, 1, 2] }, { "columnId": 2, "taskIds": [5, 4] } ]
     *                          Where taskIds are ordered correctly within each column.
     * @return array JSON response.
     */
    public function updateTaskOrder(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Check permission (moving tasks requires edit permission on kanban or tasks?)
        // Usually moving cards is a basic kanban operation, so 'kanban.edit' or 'kanban.view' + 'tasks.edit'.
        // Let's assume 'kanban.edit' allows board modification, but moving tasks is day-to-day.
        // Actually, moving tasks changes their status/column.
        // Let's require 'kanban.edit' for now as it changes the board state.
        // Or maybe just 'kanban.view' if we consider moving tasks as part of task management?
        // But the method is `updateTaskOrder`.
        // Let's use 'kanban.edit'.
        $this->requirePermission($userData, 'kanban', 'edit');

        if (!is_array($requestData) || empty($requestData)) {
            http_response_code(400);
            return ["error" => "Formato de dados inválido para atualização da ordem das tarefas."];
        }

        $success = true;
        $errors = [];

        // Use a transaction if the Database class supports it easily, otherwise process per column
        // $pdo = Database::getInstance();
        // $pdo->beginTransaction();

        try {
            foreach ($requestData as $columnData) {
                if (!isset($columnData["columnId"]) || !isset($columnData["taskIds"]) || !is_array($columnData["taskIds"])) {
                    throw new \InvalidArgumentException("Formato inválido para dados da coluna.");
                }

                $columnId = (int)$columnData["columnId"];
                $taskIdsInOrder = $columnData["taskIds"];

                $taskOrderMap = [];
                foreach ($taskIdsInOrder as $index => $taskId) {
                    $taskOrderMap[(int)$taskId] = $index; // Map taskId to its 0-based order
                }

                if (!Tarefa::updateTaskOrder($taskOrderMap, $columnId)) {
                    $success = false;
                    $errors[] = "Falha ao atualizar ordem na coluna ID: {$columnId}";
                    // Break or continue processing other columns?
                    // break; 
                }
            }

            // if ($success) { $pdo->commit(); } else { $pdo->rollBack(); }

        } catch (\InvalidArgumentException $e) {
            // $pdo->rollBack();
            http_response_code(400);
            return ["error" => $e->getMessage()];
        } catch (\Exception $e) {
            // $pdo->rollBack();
            error_log("Erro geral ao atualizar ordem das tarefas: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao atualizar ordem das tarefas."];
        }

        if ($success) {
            http_response_code(200);
            return ["message" => "Ordem das tarefas atualizada com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao atualizar a ordem de uma ou mais colunas.", "details" => $errors];
        }
    }
}
