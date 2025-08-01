<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Database;
use Apoio19\Crm\Middleware\AuthMiddleware;
use \PDO;
use \PDOException;

class NotificationController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
    }

 /**
     * List notifications with pagination and filters
     * GET /notifications
     */
    public function index(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return [
                "success" => false,
                "error" => "Autenticação necessária."
            ];
        }

        $page = max(1, (int)($queryParams['page'] ?? 1));
        $limit = min(max(1, (int)($queryParams['limit'] ?? 50)), 100);
        $offset = ($page - 1) * $limit;
        
        $type = $queryParams['type'] ?? null;
        $isRead = isset($queryParams['is_read']) ? (int)$queryParams['is_read'] : null;

        // Construir query com filtros
        $whereConditions = ["user_id = :user_id"];
        $params = [':user_id' => $userData->userId];

        if ($type) {
            $whereConditions[] = "type = :type";
            $params[':type'] = $type;
        }

        if ($isRead !== null) {
            $whereConditions[] = "is_read = :is_read";
            $params[':is_read'] = $isRead;
        }

        $whereClause = implode(' AND ', $whereConditions);

        // Query principal
        $sql = "SELECT id, user_id, title, message, type, is_read, created_at 
                FROM notifications 
                WHERE {$whereClause}
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";

        // Query para contar total
        $countSql = "SELECT COUNT(*) FROM notifications WHERE {$whereClause}";

        // Query para contar não lidas
        $unreadSql = "SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0";

        try {
            $pdo = Database::getInstance();
            
            // Buscar notificações
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Converter is_read para boolean
            foreach ($notifications as &$notification) {
                $notification['is_read'] = (bool)$notification['is_read'];
            }

            // Contar total
            $countStmt = $pdo->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            // Contar não lidas
            $unreadStmt = $pdo->prepare($unreadSql);
            $unreadStmt->bindParam(':user_id', $userData->userId, PDO::PARAM_INT);
            $unreadStmt->execute();
            $unreadCount = (int)$unreadStmt->fetchColumn();

            $lastPage = ceil($total / $limit);

            http_response_code(200);
            return [
                "success" => true,
                "data" => [
                    "notifications" => $notifications,
                    "total" => $total,
                    "unread_count" => $unreadCount,
                    "current_page" => $page,
                    "per_page" => $limit,
                    "last_page" => $lastPage
                ]
            ];

        } catch (PDOException $e) {
            error_log("Erro ao buscar notificações: " . $e->getMessage());
            http_response_code(500);
            return [
                "success" => false,
                "error" => "Erro interno ao buscar notificações"
            ];
        }
    }


    /**
     * Store a new notification.
     *
     * @param array $headers Request headers.
     * @param array $data Notification data.
     * @return array JSON response.
     */
    public function store(array $headers, array $data): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return [
                "success" => false,
                "error" => "Autenticação necessária."
            ];
        }

        // Validação dos dados de entrada
        $validation = $this->validateNotificationData($data);
        if (!$validation['valid']) {
            http_response_code(400);
            return [
                "success" => false,
                "error" => "Dados inválidos",
                "errors" => $validation['errors']
            ];
        }

        $title = $data['title'];
        $message = $data['message'] ?? '';
        $type = $data['type'] ?? 'info';
        $userId = $data['user_id'] ?? $userData->userId;

        // Inserir notificação na nova estrutura da tabela
        $sql = "INSERT INTO notifications (user_id, title, message, type, is_read) 
                VALUES (:user_id, :title, :message, :type, 0)";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":user_id", $userId, PDO::PARAM_INT);
            $stmt->bindParam(":title", $title);
            $stmt->bindParam(":message", $message);
            $stmt->bindParam(":type", $type);
            
            if ($stmt->execute()) {
                $notificationId = (int)$pdo->lastInsertId();
                
                // Buscar a notificação criada para retornar
                $selectSql = "SELECT id, user_id, title, message, type, is_read, created_at 
                             FROM notifications WHERE id = :id";
                $selectStmt = $pdo->prepare($selectSql);
                $selectStmt->bindParam(":id", $notificationId, PDO::PARAM_INT);
                $selectStmt->execute();
                $notification = $selectStmt->fetch(PDO::FETCH_ASSOC);
                
                // Converter is_read para boolean
                $notification['is_read'] = (bool)$notification['is_read'];
                
                http_response_code(201);
                return [
                    "success" => true,
                    "data" => $notification,
                    "message" => "Notificação criada com sucesso"
                ];
            } else {
                http_response_code(500);
                return [
                    "success" => false,
                    "error" => "Falha ao criar notificação"
                ];
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar notificação: " . $e->getMessage());
            http_response_code(500);
            return [
                "success" => false,
                "error" => "Erro interno ao criar notificação"
            ];
        }
    }
    
    
    
    
    /**
     * List unread notifications for the authenticated user.
     *
     * @param array $headers Request headers.
     * @param array $queryParams Optional query parameters (e.g., limit).
     * @return array JSON response.
     */
    public function listUnread(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $limit = isset($queryParams["limit"]) ? max(1, (int)$queryParams["limit"]) : 20;

        $sql = "SELECT n.id, n.tipo, n.titulo, n.mensagem, n.link, n.entidade_tipo, n.entidade_id, n.criado_em
                FROM notificacoes n
                JOIN notificacao_usuarios nu ON n.id = nu.notificacao_id
                WHERE nu.usuario_id = :usuario_id AND nu.lida = FALSE
                ORDER BY n.criado_em DESC
                LIMIT :limit";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":usuario_id", $userData->userId, PDO::PARAM_INT);
            $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
            $stmt->execute();
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Optionally, get the total unread count as well
            $countSql = "SELECT COUNT(*) FROM notificacao_usuarios WHERE usuario_id = :usuario_id AND lida = FALSE";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->bindParam(":usuario_id", $userData->userId, PDO::PARAM_INT);
            $countStmt->execute();
            $unreadCount = $countStmt->fetchColumn();

            http_response_code(200);
            return ["data" => $notifications, "unread_count" => (int)$unreadCount];

        } catch (PDOException $e) {
            error_log("Erro ao buscar notificações não lidas para usuário ID {$userData->userId}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao buscar notificações."];
        }
    }

    /**
     * Mark a specific notification as read for the authenticated user.
     *
     * @param array $headers Request headers.
     * @param int $notificationId Notification ID.
     * @return array JSON response.
     */
    public function markAsRead(array $headers, int $notificationId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $sql = "UPDATE notificacao_usuarios 
                SET lida = TRUE, data_leitura = NOW() 
                WHERE notificacao_id = :notificacao_id AND usuario_id = :usuario_id AND lida = FALSE";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":notificacao_id", $notificationId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $userData->userId, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    http_response_code(200);
                    return ["message" => "Notificação marcada como lida."];
                } else {
                    // Notification might already be read or doesn't belong to the user
                    http_response_code(200); // Or 404 if we want to be strict
                    return ["message" => "Nenhuma notificação não lida encontrada para marcar."];
                }
            } else {
                http_response_code(500);
                return ["error" => "Falha ao marcar notificação como lida."];
            }
        } catch (PDOException $e) {
            error_log("Erro ao marcar notificação {$notificationId} como lida para usuário ID {$userData->userId}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao marcar notificação como lida."];
        }
    }

    /**
     * Mark all unread notifications as read for the authenticated user.
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function markAllAsRead(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $sql = "UPDATE notificacao_usuarios 
                SET lida = TRUE, data_leitura = NOW() 
                WHERE usuario_id = :usuario_id AND lida = FALSE";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":usuario_id", $userData->userId, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $rowCount = $stmt->rowCount();
                http_response_code(200);
                return ["message" => "{$rowCount} notificações marcadas como lidas."];
            } else {
                http_response_code(500);
                return ["error" => "Falha ao marcar todas as notificações como lidas."];
            }
        } catch (PDOException $e) {
            error_log("Erro ao marcar todas as notificações como lidas para usuário ID {$userData->userId}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao marcar todas as notificações como lidas."];
        }
    }

    /**
     * Validate notification data
     */
    private function validateNotificationData(array $data): array
    {
        $errors = [];

        // Title é obrigatório
        if (empty($data['title'])) {
            $errors['title'] = ['O campo título é obrigatório'];
        } elseif (strlen($data['title']) > 255) {
            $errors['title'] = ['O título não pode ter mais de 255 caracteres'];
        }

        // Type deve ser válido
        $validTypes = ['info', 'warning', 'error', 'success'];
        if (isset($data['type']) && !in_array($data['type'], $validTypes)) {
            $errors['type'] = ['Tipo deve ser: ' . implode(', ', $validTypes)];
        }

        // User_id deve ser um número válido se fornecido
        if (isset($data['user_id']) && (!is_numeric($data['user_id']) || $data['user_id'] <= 0)) {
            $errors['user_id'] = ['ID do usuário deve ser um número válido'];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

