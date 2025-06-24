<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Tarefa;
use Apoio19\Crm\Models\TarefaComentario;
use Apoio19\Crm\Middleware\AuthMiddleware;
use Apoio19\Crm\Services\NotificationService; // Import NotificationService

// Placeholder for Request/Response handling
class TarefaController
{
    private AuthMiddleware $authMiddleware;
    private NotificationService $notificationService; // Add NotificationService instance

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->notificationService = new NotificationService(); // Instantiate NotificationService
    }

    /**
     * Create a new task.
     *
     * @param array $headers Request headers.
     * @param array $requestData Task data (titulo, descricao, kanban_coluna_id, responsavel_id, etc.).
     * @return array JSON response.
     */
    public function store(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Basic Validation
        if (empty($requestData["titulo"])) {
            http_response_code(400);
            return ["error" => "O título da tarefa é obrigatório."];
        }
        if (empty($requestData["kanban_coluna_id"])) {
             http_response_code(400);
            return ["error" => "A coluna Kanban (kanban_coluna_id) é obrigatória."];
        }
        // Add more validation (check if column exists, user exists, date format etc.)

        // Set the creator ID
        $requestData["criador_id"] = $userData->userId;

        $taskId = Tarefa::create($requestData);

        if ($taskId) {
            $newTask = Tarefa::findById($taskId); // Fetch the created task with details
            
            // --- Notification --- 
            if ($newTask && $newTask->responsavel_id && $newTask->responsavel_id !== $userData->userId) { // Notify assignee if different from creator
                $this->notificationService->createNotification(
                    "nova_tarefa_atribuida",
                    "Nova Tarefa Atribuída: " . $newTask->titulo,
                    "Você foi atribuído(a) à nova tarefa: \"{$newTask->titulo}\" criada por {$userData->userName}.", // Assuming userName is available in $userData
                    [$newTask->responsavel_id],
                    "/tarefas/" . $taskId, // Example link
                    "tarefa",
                    $taskId,
                    true // Send email
                );
            }
            // --- End Notification ---
            
            http_response_code(201);
            return ["message" => "Tarefa criada com sucesso.", "tarefa" => $newTask];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao criar tarefa."];
        }
    }

    /**
     * Get details of a specific task, including its comments.
     *
     * @param array $headers Request headers.
     * @param int $taskId Task ID.
     * @return array JSON response.
     */
    public function show(array $headers, int $taskId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $tarefa = Tarefa::findById($taskId);

        if (!$tarefa) {
            http_response_code(404);
            return ["error" => "Tarefa não encontrada."];
        }

        // Fetch comments for the task
        $comentarios = TarefaComentario::findByTaskId($taskId);

        http_response_code(200);
        return ["data" => ["tarefa" => $tarefa, "comentarios" => $comentarios]];
    }

    /**
     * Update an existing task.
     *
     * @param array $headers Request headers.
     * @param int $taskId Task ID.
     * @param array $requestData Data to update.
     * @return array JSON response.
     */
    public function update(array $headers, int $taskId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Check if task exists
        $tarefa = Tarefa::findById($taskId);
        if (!$tarefa) {
            http_response_code(404);
            return ["error" => "Tarefa não encontrada para atualização."];
        }
        
        // Authorization check: Can this user update this task?
        // ... (implement authorization logic if needed)

        if (empty($requestData)) {
            http_response_code(400);
            return ["error" => "Nenhum dado fornecido para atualização."];
        }

        // --- Notification Check (Before Update) ---
        $oldAssigneeId = $tarefa->responsavel_id;
        $newAssigneeId = isset($requestData["responsavel_id"]) ? (int)$requestData["responsavel_id"] : $oldAssigneeId;
        $notifyAssignee = ($newAssigneeId !== $oldAssigneeId && $newAssigneeId !== null && $newAssigneeId !== $userData->userId);
        // --- End Notification Check ---

        // Handle marking as complete
        if (isset($requestData["concluida"]) && $requestData["concluida"] && !$tarefa->concluida) {
            $requestData["data_conclusao"] = date("Y-m-d H:i:s");
        } elseif (isset($requestData["concluida"]) && !$requestData["concluida"]) {
             $requestData["data_conclusao"] = null;
        }

        if (Tarefa::update($taskId, $requestData)) {
            $updatedTask = Tarefa::findById($taskId);
            
            // --- Notification (After Update) ---
            if ($notifyAssignee && $updatedTask) {
                 $this->notificationService->createNotification(
                    "tarefa_atribuida",
                    "Tarefa Atribuída a Você: " . $updatedTask->titulo,
                    "A tarefa \"{$updatedTask->titulo}\" foi atribuída a você por {$userData->userName}.", // Assuming userName is available
                    [$newAssigneeId],
                    "/tarefas/" . $taskId, // Example link
                    "tarefa",
                    $taskId,
                    true // Send email
                );
            }
            // TODO: Add notification for task completion, due date changes, etc. if needed
            // --- End Notification ---
            
            http_response_code(200);
            return ["message" => "Tarefa atualizada com sucesso.", "tarefa" => $updatedTask];
        } else {
            http_response_code(500); // Or 304 Not Modified?
            return ["error" => "Falha ao atualizar tarefa."];
        }
    }

    /**
     * Delete a task.
     *
     * @param array $headers Request headers.
     * @param int $taskId Task ID.
     * @return array JSON response.
     */
    public function destroy(array $headers, int $taskId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $tarefa = Tarefa::findById($taskId);
        if (!$tarefa) {
            http_response_code(404);
            return ["error" => "Tarefa não encontrada para exclusão."];
        }
        
        // Authorization check: Can this user delete this task?
        // ... (implement authorization logic if needed)

        if (Tarefa::delete($taskId)) {
            // TODO: Notify relevant users about deletion? (e.g., assignee)
            http_response_code(200); // Or 204 No Content
            return ["message" => "Tarefa excluída com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao excluir tarefa."];
        }
    }

    /**
     * Add a comment to a task.
     *
     * @param array $headers Request headers.
     * @param int $taskId Task ID.
     * @param array $requestData Expected key: comentario.
     * @return array JSON response.
     */
    public function addComment(array $headers, int $taskId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $comentario = $requestData["comentario"] ?? null;
        if (!$comentario) {
            http_response_code(400);
            return ["error" => "O texto do comentário é obrigatório."];
        }

        // Check if task exists
        $tarefa = Tarefa::findById($taskId);
        if (!$tarefa) {
            http_response_code(404);
            return ["error" => "Tarefa não encontrada para adicionar comentário."];
        }

        $commentId = TarefaComentario::create($taskId, $userData->userId, $comentario);

        if ($commentId) {
            // --- Notification ---
            $notifyUsers = [];
            if ($tarefa->criador_id && $tarefa->criador_id !== $userData->userId) {
                $notifyUsers[] = $tarefa->criador_id;
            }
            if ($tarefa->responsavel_id && $tarefa->responsavel_id !== $userData->userId && !in_array($tarefa->responsavel_id, $notifyUsers)) {
                $notifyUsers[] = $tarefa->responsavel_id;
            }
            if (!empty($notifyUsers)) {
                 $this->notificationService->createNotification(
                    "novo_comentario_tarefa",
                    "Novo Comentário na Tarefa: " . $tarefa->titulo,
                    "{$userData->userName} adicionou um novo comentário na tarefa \"{$tarefa->titulo}\".",
                    $notifyUsers,
                    "/tarefas/" . $taskId, // Link to task
                    "tarefa",
                    $taskId,
                    true // Send email
                );
            }
            // --- End Notification ---
            
            http_response_code(201);
            return ["message" => "Comentário adicionado com sucesso.", "comment_id" => $commentId];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao adicionar comentário."];
        }
    }

    /**
     * Delete a comment from a task.
     *
     * @param array $headers Request headers.
     * @param int $taskId Task ID (optional, could just use commentId).
     * @param int $commentId Comment ID.
     * @return array JSON response.
     */
    public function deleteComment(array $headers, int $taskId, int $commentId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Optional: Verify comment belongs to the task and user has permission
        // ... (implement authorization logic if needed)

        if (TarefaComentario::delete($commentId)) {
            http_response_code(200); // Or 204
            return ["message" => "Comentário excluído com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao excluir comentário."];
        }
    }
}

