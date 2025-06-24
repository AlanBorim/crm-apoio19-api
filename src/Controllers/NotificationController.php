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
}

