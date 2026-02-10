<?php

namespace Apoio19\Crm\Models;

use PDO;
use PDOException;

class WhatsappChatMessage
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new chat message
     *
     * @param array $data Message data
     * @return int|false Message ID or false on failure
     */
    public function create(array $data)
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO whatsapp_chat_messages 
                (contact_id, user_id, direction, message_type, message_content, media_url, 
                 whatsapp_message_id, status, sent_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $data['contact_id'],
                $data['user_id'],
                $data['direction'], // 'incoming' or 'outgoing'
                $data['message_type'] ?? 'text',
                $data['message_content'],
                $data['media_url'] ?? null,
                $data['whatsapp_message_id'] ?? null,
                $data['status'] ?? 'pending',
                $data['sent_at'] ?? date('Y-m-d H:i:s')
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error creating chat message: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get conversation messages for a contact
     *
     * @param int $contactId Contact ID
     * @param int $limit Number of messages to retrieve
     * @param int $offset Offset for pagination
     * @return array Messages array
     */
    public function getConversation(int $contactId, int $limit = 100, int $offset = 0): array
    {
        try {
            // Exclude messages that are part of campaigns
            $stmt = $this->db->prepare('
                SELECT wcm.*, u.name as user_name
                FROM whatsapp_chat_messages wcm
                LEFT JOIN users u ON wcm.user_id = u.id
                WHERE wcm.contact_id = ?
                AND NOT EXISTS (
                    SELECT 1 
                    FROM whatsapp_campaign_messages wccm 
                    WHERE wccm.message_id = wcm.whatsapp_message_id
                )
                ORDER BY wcm.created_at DESC
                LIMIT ? OFFSET ?
            ');

            $stmt->execute([$contactId, $limit, $offset]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Reverse to show oldest first
            return array_reverse($messages);
        } catch (PDOException $e) {
            error_log("Error getting conversation: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update message status
     *
     * @param string $whatsappMessageId WhatsApp message ID from API
     * @param string $status New status (sent, delivered, read, failed)
     * @return bool Success status
     */
    public function updateStatus(string $whatsappMessageId, string $status): bool
    {
        try {
            $statusColumn = null;
            switch ($status) {
                case 'delivered':
                    $statusColumn = 'delivered_at';
                    break;
                case 'read':
                    $statusColumn = 'read_at';
                    break;
            }

            $sql = 'UPDATE whatsapp_chat_messages SET status = ?';
            $params = [$status];

            if ($statusColumn) {
                $sql .= ", $statusColumn = NOW()";
            }

            $sql .= ' WHERE whatsapp_message_id = ?';
            $params[] = $whatsappMessageId;

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating message status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update message status by internal ID
     *
     * @param int $messageId Internal message ID
     * @param string $status New status
     * @param string|null $errorMessage Error message if failed
     * @return bool
     */
    public function updateStatusById(int $messageId, string $status, ?string $errorMessage = null): bool
    {
        try {
            $sql = 'UPDATE whatsapp_chat_messages SET status = ?, error_message = ? WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$status, $errorMessage, $messageId]);
        } catch (PDOException $e) {
            error_log("Error updating message status by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark all messages from a contact as read
     *
     * @param int $contactId Contact ID
     * @return bool Success status
     */
    public function markAsRead(int $contactId): bool
    {
        try {
            $stmt = $this->db->prepare('
                UPDATE whatsapp_chat_messages 
                SET status = "read", read_at = NOW() 
                WHERE contact_id = ? 
                AND direction = "incoming" 
                AND status != "read"
            ');

            return $stmt->execute([$contactId]);
        } catch (PDOException $e) {
            error_log("Error marking messages as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get unread message count for a contact
     *
     * @param int $contactId Contact ID
     * @return int Unread count
     */
    public function getUnreadCount(int $contactId): int
    {
        try {
            $stmt = $this->db->prepare('
                SELECT COUNT(*) as count
                FROM whatsapp_chat_messages
                WHERE contact_id = ?
                AND direction = "incoming"
                AND status != "read"
            ');

            $stmt->execute([$contactId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error getting unread count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get last message for a contact
     *
     * @param int $contactId Contact ID
     * @return array|null Last message or null
     */
    public function getLastMessage(int $contactId): ?array
    {
        try {
            // Exclude messages that are part of a campaign
            $stmt = $this->db->prepare('
                SELECT wcm.*
                FROM whatsapp_chat_messages wcm
                WHERE wcm.contact_id = ?
                AND NOT EXISTS (
                    SELECT 1 
                    FROM whatsapp_campaign_messages wccm 
                    WHERE wccm.message_id = wcm.whatsapp_message_id
                )
                ORDER BY wcm.created_at DESC
                LIMIT 1
            ');

            $stmt->execute([$contactId]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);

            return $message ?: null;
        } catch (PDOException $e) {
            error_log("Error getting last message: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find message by WhatsApp message ID
     *
     * @param string $whatsappMessageId
     * @return array|null
     */
    public function findByWhatsappMessageId(string $whatsappMessageId): ?array
    {
        try {
            $stmt = $this->db->prepare('
                SELECT *
                FROM whatsapp_chat_messages
                WHERE whatsapp_message_id = ?
                LIMIT 1
            ');

            $stmt->execute([$whatsappMessageId]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);

            return $message ?: null;
        } catch (PDOException $e) {
            error_log("Error finding message by WhatsApp ID: " . $e->getMessage());
            return null;
        }
    }
}
