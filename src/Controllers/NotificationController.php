<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Database;
use Apoio19\Crm\Middleware\AuthMiddleware;
use \PDO;
use \PDOException;

class NotificationController extends BaseController
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
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $page = max(1, (int)($queryParams['page'] ?? 1));
        $limit = min(max(1, (int)($queryParams['limit'] ?? 50)), 100);
        $offset = ($page - 1) * $limit;

        $type = $queryParams['type'] ?? null;
        $isRead = isset($queryParams['is_read']) ? (int)$queryParams['is_read'] : null;

        // Construir query com filtros
        $whereConditions = ["user_id = :user_id"];
        $params = [':user_id' => $userData->id];

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
                WHERE {$whereClause} AND active = '1'
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";

        // Query para contar total
        $countSql = "SELECT COUNT(*) FROM notifications WHERE {$whereClause} AND active = '1'";

        // Query para contar não lidas
        $unreadSql = "SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0 AND active = '1'";

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
            $unreadStmt->bindParam(':user_id', $userData->id, PDO::PARAM_INT);
            $unreadStmt->execute();
            $unreadCount = (int)$unreadStmt->fetchColumn();

            $lastPage = ceil($total / $limit);

            $data = [
                "notifications" => $notifications,
                "total" => $total,
                "unread_count" => $unreadCount,
                "current_page" => $page,
                "per_page" => $limit,
                "last_page" => $lastPage
            ];

            return $this->successResponse($data, null, 200, $traceId);
        } catch (PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro interno ao buscar notificações", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
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
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Validação dos dados de entrada
        $validation = $this->validateNotificationData($data);
        if (!$validation['valid']) {
            return $this->errorResponse(400, "Dados inválidos", "VALIDATION_ERROR", $traceId, $validation['errors']);
        }

        $title = $data['title'];
        $message = $data['message'] ?? '';
        $type = $data['type'] ?? 'info';
        $userId = $data['user_id'] ?? $userData->id;

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

                return $this->successResponse($notification, "Notificação criada com sucesso", 201, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao criar notificação", "CREATE_FAILED", $traceId);
            }
        } catch (PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro interno ao criar notificação", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
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
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação do CRM necessária.", "UNAUTHENTICATED", $traceId);
        }

        try {
            $pdo = Database::getInstance();

            // se já estiver lido marca como não lido
            // busca a notificação
            $sqlBusca = "SELECT is_read FROM notifications WHERE id = :notificacao_id AND user_id = :usuario_id AND active = '1'";
            $stmtBusca = $pdo->prepare($sqlBusca);
            $stmtBusca->bindParam(":notificacao_id", $notificationId, PDO::PARAM_INT);
            $stmtBusca->bindParam(":usuario_id", $userData->id, PDO::PARAM_INT);
            $stmtBusca->execute();
            $notification = $stmtBusca->fetch(PDO::FETCH_ASSOC);

            if (!$notification) {
                return $this->errorResponse(404, "Notificação não encontrada.", "NOT_FOUND", $traceId);
            }

            if ($notification['is_read'] == 1) {
                $sql = "UPDATE notifications 
                        SET is_read = 0, readed_at = NULL 
                        WHERE id = :notificacao_id AND user_id = :usuario_id AND is_read = 1";
            } else {
                $sql = "UPDATE notifications 
                        SET is_read = 1, readed_at = NOW() 
                        WHERE id = :notificacao_id AND user_id = :usuario_id AND is_read = 0";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":notificacao_id", $notificationId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $userData->id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    return $this->successResponse(null, "Notificação atualizada.", 200, $traceId);
                } else {
                    return $this->successResponse(null, "Nenhuma alteração realizada.", 200, $traceId);
                }
            } else {
                return $this->errorResponse(500, "Falha ao marcar notificação como lida.", "UPDATE_FAILED", $traceId);
            }
        } catch (PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro interno ao marcar notificação como lida.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
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
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação do CRM necessária.", "UNAUTHENTICATED", $traceId);
        }

        $sql = "UPDATE notifications 
                SET is_read = 1, readed_at = NOW() 
                WHERE user_id = :usuario_id AND is_read = 0";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":usuario_id", $userData->id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $rowCount = $stmt->rowCount();
                return $this->successResponse(null, "{$rowCount} notificações marcadas como lidas.", 200, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao marcar todas as notificações como lidas.", "UPDATE_FAILED", $traceId);
            }
        } catch (PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro interno ao marcar todas as notificações como lidas.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    public function delete(array $headers, int $notificationId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação do CRM necessária.", "UNAUTHENTICATED", $traceId);
        }

        $sql = "UPDATE notifications 
                SET active = '0'
                WHERE user_id = :usuario_id AND active = '1' AND id = :notificacao_id";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":notificacao_id", $notificationId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $userData->id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    return $this->successResponse(null, "Notificação excluída com sucesso.", 200, $traceId);
                } else {
                    return $this->errorResponse(404, "Notificação não encontrada ou já excluída.", "NOT_FOUND", $traceId);
                }
            } else {
                return $this->errorResponse(500, "Falha ao excluir notificação.", "DELETE_FAILED", $traceId);
            }
        } catch (PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro ao excluir notificação.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    public function deleteAll(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação do CRM necessária.", "UNAUTHENTICATED", $traceId);
        }

        $sql = "UPDATE notifications 
                SET active = '0' 
                WHERE user_id = :usuario_id AND active = '1'";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":usuario_id", $userData->id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    return $this->successResponse(null, "Notificações excluídas com sucesso.", 200, $traceId);
                } else {
                    return $this->errorResponse(404, "Nenhuma notificação encontrada para excluir.", "NOT_FOUND", $traceId);
                }
            } else {
                return $this->errorResponse(500, "Falha ao excluir notificações.", "DELETE_FAILED", $traceId);
            }
        } catch (PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro interno ao excluir notificações.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
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
