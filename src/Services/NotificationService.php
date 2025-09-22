<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class NotificationService
{
     /**
     * Create a notification and associate it with users.
     *
     * @param string $tipo Notification type identifier.
     * @param string $titulo Notification title.
     * @param string $mensagem Notification message body.
     * @param array $userIds Array of user IDs to notify.
     * @param string|null $link Optional link related to the notification.
     * @param string|null $entidadeTipo Optional related entity type (e.g., "tarefa").
     * @param int|null $entidadeId Optional related entity ID.
     * @param bool $sendEmail Attempt to send email notification immediately.
     * @return int|false The ID of the created notification or false on failure.
     */
    public function createNotification(
        int $userId,
        string $title,
        string $message,
        string $type,
        ?string $endpoint = null, 
        string $isRead = '0',
        bool $sendEmail = true
    ): int|false 
    {

        // Validate inputs
        if (empty($title) || empty($message) || empty($type) || $userId <= 0) {
            error_log("Dados inválidos para criar notificação.");
            return false;
        }

        $sqlNotif = "INSERT INTO notifications (user_id, title, message, endpoint,  type, is_read) 
                     VALUES (:user_id, :title, :message, :endpoint, :type, :is_read)";

        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();

            // 1. Create the notification record
            $stmtNotif = $pdo->prepare($sqlNotif);
            $stmtNotif->bindParam(":user_id", $userId, PDO::PARAM_INT);
            $stmtNotif->bindParam(":title", $title);
            $stmtNotif->bindParam(":message", $message);
            $stmtNotif->bindParam(":endpoint", $endpoint);
            $stmtNotif->bindParam(":type", $type);
            $stmtNotif->bindParam(":is_read", $isRead, PDO::PARAM_BOOL);
            
            if (!$stmtNotif->execute()) {
                throw new PDOException("Falha ao criar registro de notificação.");
            }
            $notificationId = (int)$pdo->lastInsertId();

            $pdo->commit();

            return $notificationId;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Erro de banco de dados ao criar notificação: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
             $pdo->rollBack(); // Rollback on general errors too during transaction
             error_log("Erro geral ao criar notificação: " . $e->getMessage());
            return false;
        }
    }

}

