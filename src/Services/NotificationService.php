<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Database;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use \PDO;
use \PDOException;

class NotificationService
{
    private ?PHPMailer $mailer = null;
    private bool $emailConfigured = false;

    public function __construct()
    {
        // Load environment variables (simple approach)
        $envPath = __DIR__ . 

'/../../.env

'; 
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), 

'#

') === 0) continue;
                // Check if line contains '=' before exploding
                if (strpos($line, 

'=

') !== false) {
                    list($name, $value) = explode(

'=

', $line, 2);
                    $_ENV[trim($name)] = trim($value);
                }
            }
        }

        // Initialize PHPMailer if configured
        if (!empty($_ENV[

'MAIL_HOST

']) && !empty($_ENV[

'MAIL_USERNAME

'])) {
            try {
                $this->mailer = new PHPMailer(true); // Enable exceptions
                // Server settings
                // $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output for testing
                $this->mailer->isSMTP();
                $this->mailer->Host       = $_ENV[

'MAIL_HOST

'];
                $this->mailer->SMTPAuth   = true;
                $this->mailer->Username   = $_ENV[

'MAIL_USERNAME

'];
                $this->mailer->Password   = $_ENV[

'MAIL_PASSWORD

'] ?? 

''

;
                $this->mailer->SMTPSecure = $_ENV[

'MAIL_ENCRYPTION

'] === 

'ssl

' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $this->mailer->Port       = (int)($_ENV[

'MAIL_PORT

'] ?? 587);
                $this->mailer->CharSet    = PHPMailer::CHARSET_UTF8;

                // Sender
                $fromAddress = $_ENV[

'MAIL_FROM_ADDRESS

'] ?? $_ENV[

'MAIL_USERNAME

'];
                $fromName = $_ENV[

'MAIL_FROM_NAME

'] ?? 

'CRM Apoio19

';
                $this->mailer->setFrom($fromAddress, $fromName);
                
                $this->emailConfigured = true;
            } catch (PHPMailerException $e) {
                error_log("Erro ao configurar PHPMailer: " . $this->mailer->ErrorInfo);
                $this->mailer = null; // Ensure mailer is null if config fails
            }
        }
    }

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
        string $tipo, 
        string $titulo, 
        string $mensagem, 
        array $userIds, 
        ?string $link = null, 
        ?string $entidadeTipo = null, 
        ?int $entidadeId = null,
        bool $sendEmail = true
    ): int|false 
    {
        if (empty($userIds)) {
            return false; // No one to notify
        }

        $sqlNotif = "INSERT INTO notificacoes (tipo, titulo, mensagem, link, entidade_tipo, entidade_id) 
                     VALUES (:tipo, :titulo, :mensagem, :link, :entidade_tipo, :entidade_id)";
        
        $sqlUserNotif = "INSERT INTO notificacao_usuarios (notificacao_id, usuario_id) VALUES (:notificacao_id, :usuario_id)";

        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();

            // 1. Create the notification record
            $stmtNotif = $pdo->prepare($sqlNotif);
            $stmtNotif->bindParam(":tipo", $tipo);
            $stmtNotif->bindParam(":titulo", $titulo);
            $stmtNotif->bindParam(":mensagem", $mensagem);
            $stmtNotif->bindParam(":link", $link);
            $stmtNotif->bindParam(":entidade_tipo", $entidadeTipo);
            $stmtNotif->bindParam(":entidade_id", $entidadeId, PDO::PARAM_INT);
            
            if (!$stmtNotif->execute()) {
                throw new PDOException("Falha ao criar registro de notificação.");
            }
            $notificationId = (int)$pdo->lastInsertId();

            // 2. Associate notification with users
            $stmtUserNotif = $pdo->prepare($sqlUserNotif);
            $emailsToSend = [];
            foreach ($userIds as $userId) {
                $stmtUserNotif->bindParam(":notificacao_id", $notificationId, PDO::PARAM_INT);
                $stmtUserNotif->bindParam(":usuario_id", $userId, PDO::PARAM_INT);
                if (!$stmtUserNotif->execute()) {
                    // Log error but try to continue for other users?
                    error_log("Falha ao associar notificação {$notificationId} ao usuário {$userId}");
                } else {
                    // Collect user email for sending if needed
                    if ($sendEmail && $this->emailConfigured) {
                        $userEmail = $this->getUserEmail($userId);
                        if ($userEmail) {
                            $emailsToSend[$userId] = $userEmail;
                        }
                    }
                }
            }

            $pdo->commit();

            // 3. Send emails (outside transaction)
            if ($sendEmail && $this->emailConfigured && !empty($emailsToSend)) {
                $this->sendEmailNotification($notificationId, $titulo, $mensagem, $emailsToSend);
            }

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

    /**
     * Send email notifications to a list of users.
     *
     * @param int $notificationId
     * @param string $subject
     * @param string $body HTML body of the email.
     * @param array $recipients Associative array [userId => emailAddress].
     * @return void
     */
    private function sendEmailNotification(int $notificationId, string $subject, string $body, array $recipients): void
    {
        if (!$this->mailer || empty($recipients)) {
            return;
        }

        $sqlMarkSent = "UPDATE notificacao_usuarios SET enviada_email = TRUE, data_envio_email = NOW() 
                        WHERE notificacao_id = :notificacao_id AND usuario_id = :usuario_id AND enviada_email = FALSE";
        $pdo = Database::getInstance();
        $stmtMarkSent = $pdo->prepare($sqlMarkSent);

        foreach ($recipients as $userId => $email) {
            try {
                $this->mailer->clearAddresses(); // Clear previous recipients
                $this->mailer->addAddress($email);

                $this->mailer->isHTML(true);
                $this->mailer->Subject = $subject;
                $this->mailer->Body    = $body; // Consider using HTML templates
                $this->mailer->AltBody = strip_tags($body); // Plain text version

                $this->mailer->send();
                
                // Mark as sent in DB
                $stmtMarkSent->bindParam(":notificacao_id", $notificationId, PDO::PARAM_INT);
                $stmtMarkSent->bindParam(":usuario_id", $userId, PDO::PARAM_INT);
                $stmtMarkSent->execute();

            } catch (PHPMailerException $e) {
                error_log("Erro ao enviar email de notificação para {$email} (Notif ID: {$notificationId}): " . $this->mailer->ErrorInfo);
            } catch (PDOException $e) {
                 error_log("Erro ao marcar email como enviado para User ID {$userId} (Notif ID: {$notificationId}): " . $e->getMessage());
            }
        }
    }

    /**
     * Get user email by ID.
     *
     * @param int $userId
     * @return string|null
     */
    private function getUserEmail(int $userId): ?string
    {
        $sql = "SELECT email FROM usuarios WHERE id = :id AND ativo = TRUE LIMIT 1";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $userId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetchColumn();
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Erro ao buscar email do usuário ID {$userId}: " . $e->getMessage());
            return null;
        }
    }

    // TODO: Add methods for Web/Push notifications if using a service like Firebase Cloud Messaging (FCM)
    // This would typically involve storing device tokens/subscriptions per user and sending requests to the push service API.
}

