<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Tarefa;
use Apoio19\Crm\Models\TarefaComentario;
use Apoio19\Crm\Models\AtividadeLog;
use Apoio19\Crm\Middleware\AuthMiddleware;
use Apoio19\Crm\Services\NotificationService; // Import NotificationService

// Placeholder for Request/Response handling
class TarefaController extends BaseController
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
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação do CRM necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Basic Validation
        if (empty($requestData["titulo"])) {
            return $this->errorResponse(400, "O título da tarefa é obrigatório.", "VALIDATION_ERROR", $traceId);
        }
        if (empty($requestData["kanban_coluna_id"])) {
            return $this->errorResponse(400, "A coluna Kanban (kanban_coluna_id) é obrigatória.", "VALIDATION_ERROR", $traceId);
        }
        // Add more validation (check if column exists, user exists, date format etc.)

        // Set the creator ID
        $requestData["criador_id"] = $userData->userId;

        try {
            $taskId = Tarefa::create($requestData);

            if ($taskId) {
                $newTask = Tarefa::findById($taskId); // Fetch the created task with details

                if (!$newTask) {
                    // DEBUG: Try the FULL query from findById to see why it fails
                    $debugInfo = "";
                    try {
                        $pdo = \Apoio19\Crm\Models\Database::getInstance();
                        $sql = "SELECT t.*, 
                               kc.nome as kanban_coluna_nome, 
                               u_resp.name as responsavel_nome, 
                               u_criador.name as criador_nome
                        FROM tarefas t
                        LEFT JOIN kanban_colunas kc ON t.kanban_coluna_id = kc.id
                        LEFT JOIN users u_resp ON t.responsavel_id = u_resp.id
                        LEFT JOIN users u_criador ON t.criador_id = u_criador.id
                        WHERE t.id = " . (int)$taskId;

                        $stmt = $pdo->query($sql);
                        $fullData = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($fullData) {
                            $debugInfo = "Full query SUCCEEDED. Data: " . json_encode($fullData);
                        } else {
                            $debugInfo = "Full query returned EMPTY for ID " . $taskId;
                        }
                    } catch (\Exception $e) {
                        $debugInfo = "Full query FAILED: " . $e->getMessage();
                    }

                    error_log("Erro: Tarefa criada com ID {$taskId} mas não encontrada pelo findById. Debug: " . $debugInfo);
                    return $this->errorResponse(500, "Tarefa criada com ID {$taskId}, mas erro ao recuperar detalhes. " . $debugInfo, "RETRIEVAL_ERROR", $traceId);
                }

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

                return $this->successResponse(["tarefa" => $newTask], "Tarefa criada com sucesso.", 201, $traceId);
            } else {
                error_log("Erro: Falha ao criar tarefa no banco de dados.");
                return $this->errorResponse(500, "Falha ao criar tarefa.", "CREATE_FAILED", $traceId);
            }
        } catch (\PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro interno ao criar tarefa.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
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
     * Get comments for a task.
     *
     * @param array $headers Request headers.
     * @param int $taskId Task ID.
     * @return array JSON response.
     */
    public function getComments(array $headers, int $taskId): array
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
            return ["error" => "Tarefa não encontrada."];
        }

        // Fetch comments for the task
        $comentarios = TarefaComentario::findByTaskId($taskId);

        http_response_code(200);
        return ["comentarios" => $comentarios];
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

    /**
     * Get activity logs with filters and pagination.
     *
     * @param array $headers Request headers.
     * @param array $queryParams Query parameters for filtering.
     * @return array JSON response.
     */
    public function getLogs(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Parse filters
        $filters = [];
        if (isset($queryParams['tarefa_id'])) {
            $filters['tarefa_id'] = (int)$queryParams['tarefa_id'];
        }
        if (isset($queryParams['coluna_id'])) {
            $filters['coluna_id'] = (int)$queryParams['coluna_id'];
        }
        if (isset($queryParams['usuario_id'])) {
            $filters['usuario_id'] = (int)$queryParams['usuario_id'];
        }
        if (isset($queryParams['acao'])) {
            $filters['acao'] = $queryParams['acao'];
        }

        // Parse pagination
        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
        $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;
        $limit = min($limit, 100); // Max 100 per page

        try {
            $logs = AtividadeLog::findAll($filters, $page, $limit);
            $total = AtividadeLog::count($filters);

            http_response_code(200);
            return [
                "success" => true,
                "data" => [
                    "data" => $logs,
                    "pagination" => [
                        "page" => $page,
                        "limit" => $limit,
                        "total" => $total,
                        "totalPages" => ceil($total / $limit)
                    ]
                ]
            ];
        } catch (\Exception $e) {
            error_log("Erro ao buscar logs: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao buscar logs de atividade."];
        }
    }

    /**
     * Create a new activity log.
     *
     * @param array $headers Request headers.
     * @param array $requestData Log data.
     * @return array JSON response.
     */
    public function createLog(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Validation
        if (empty($requestData['acao'])) {
            http_response_code(400);
            return ["error" => "O campo 'acao' é obrigatório."];
        }
        if (empty($requestData['descricao'])) {
            http_response_code(400);
            return ["error" => "O campo 'descricao' é obrigatório."];
        }

        // Prepare data for database
        $logData = [
            'tarefa_id' => isset($requestData['tarefa_id']) ? (int)$requestData['tarefa_id'] : null,
            'coluna_id' => isset($requestData['coluna_id']) ? (int)$requestData['coluna_id'] : null,
            'usuario_id' => $userData->userId,
            'acao' => $requestData['acao'],
            'descricao' => $requestData['descricao'],
            'valor_antigo' => $requestData['valor_antigo'] ?? null,
            'valor_novo' => $requestData['valor_novo'] ?? null
        ];

        try {
            $logId = AtividadeLog::create($logData);

            if ($logId) {
                http_response_code(201);
                return [
                    "success" => true,
                    "message" => "Log de atividade criado com sucesso.",
                    "log_id" => $logId
                ];
            } else {
                http_response_code(500);
                return ["error" => "Falha ao criar log de atividade."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao criar log: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao criar log de atividade."];
        }
    }
}
