<?php

namespace Apoio19\Crm\Models;

use PDO;
use Apoio19\Crm\Models\Database;

class WhatsappCampaignMessage
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all messages for a campaign
     */
    public function getByCampaignId(int $campaignId): array
    {
        $stmt = $this->db->prepare("
            SELECT wcm.*, 
                   wt.name as template_name, 
                   wt.template_id as meta_template_id,
                   wt.language as template_language,
                   wt.components as template_components,
                   wc.name as contact_name,
                   wc.phone_number as contact_phone,
                   (SELECT response_text FROM whatsapp_message_responses WHERE message_id = wcm.id ORDER BY received_at DESC LIMIT 1) as response_text,
                   (SELECT response_type FROM whatsapp_message_responses WHERE message_id = wcm.id ORDER BY received_at DESC LIMIT 1) as response_type,
                   (SELECT received_at FROM whatsapp_message_responses WHERE message_id = wcm.id ORDER BY received_at DESC LIMIT 1) as response_received_at,
                   (SELECT cm.message_content FROM whatsapp_chat_messages cm WHERE cm.contact_id = wcm.contact_id AND cm.direction = 'outgoing' AND cm.created_at >= (SELECT received_at FROM whatsapp_message_responses WHERE message_id = wcm.id ORDER BY received_at DESC LIMIT 1) ORDER BY cm.created_at ASC LIMIT 1) as auto_reply_text,
                   (SELECT cm.created_at FROM whatsapp_chat_messages cm WHERE cm.contact_id = wcm.contact_id AND cm.direction = 'outgoing' AND cm.created_at >= (SELECT received_at FROM whatsapp_message_responses WHERE message_id = wcm.id ORDER BY received_at DESC LIMIT 1) ORDER BY cm.created_at ASC LIMIT 1) as auto_reply_received_at
            FROM whatsapp_campaign_messages wcm
            LEFT JOIN whatsapp_templates wt ON wcm.template_id = wt.id
            LEFT JOIN whatsapp_contacts wc ON wcm.contact_id = wc.id
            WHERE wcm.campaign_id = ?
            ORDER BY wcm.created_at DESC
        ");

        $stmt->execute([$campaignId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode template components JSON and add direction
        foreach ($messages as &$message) {
            $message['direction'] = 'outgoing'; // Campaign messages are always outgoing
            if (isset($message['template_components'])) {
                $message['template_components'] = json_decode($message['template_components'], true);
            }
        }

        return $messages;
    }

    /**
     * Get single message by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT wcm.*, 
                   wt.name as template_name,
                   wt.template_id as meta_template_id,
                   wt.language as template_language,
                   wc.name as contact_name,
                   wc.phone_number as contact_phone
            FROM whatsapp_campaign_messages wcm
            LEFT JOIN whatsapp_templates wt ON wcm.template_id = wt.id
            LEFT JOIN whatsapp_contacts wc ON wcm.contact_id = wc.id
            WHERE wcm.id = ?
        ");

        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Get single message by Campaign and Contact
     */
    public function findByCampaignAndContact(int $campaignId, int $contactId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT wcm.*, 
                   wt.name as template_name,
                   wt.template_id as meta_template_id,
                   wt.language as template_language,
                   wc.name as contact_name,
                   wc.phone_number as contact_phone
            FROM whatsapp_campaign_messages wcm
            LEFT JOIN whatsapp_templates wt ON wcm.template_id = wt.id
            LEFT JOIN whatsapp_contacts wc ON wcm.contact_id = wc.id
            WHERE wcm.campaign_id = ? AND wcm.contact_id = ?
            ORDER BY wcm.id DESC LIMIT 1
        ");

        $stmt->execute([$campaignId, $contactId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Create a new campaign message
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO whatsapp_campaign_messages 
            (campaign_id, contact_id, template_id, template_params, status)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['campaign_id'],
            $data['contact_id'],
            $data['template_id'] ?? null,
            isset($data['template_params']) ? json_encode($data['template_params']) : null,
            $data['status'] ?? 'pending'
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update campaign message
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowedFields = ['template_id', 'template_params', 'status', 'contact_id'];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                if ($key === 'template_params') {
                    $fields[] = "$key = ?";
                    $values[] = is_array($value) ? json_encode($value) : $value;
                } else {
                    $fields[] = "$key = ?";
                    $values[] = $value;
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;
        $sql = 'UPDATE whatsapp_campaign_messages SET ' . implode(', ', $fields) . ' WHERE id = ?';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Update message status
     */
    public function updateStatus(int $id, string $status, ?string $messageId = null, ?string $errorMessage = null, ?string $phoneNumberId = null): bool
    {
        $fields = ['status = ?'];
        $values = [$status];

        // Set timestamp based on status
        switch ($status) {
            case 'sent':
                $fields[] = 'sent_at = NOW()';
                break;
            case 'delivered':
                $fields[] = 'delivered_at = NOW()';
                break;
            case 'read':
                $fields[] = 'read_at = NOW()';
                break;
            case 'failed':
                $fields[] = 'failed_at = NOW()';
                break;
        }

        if ($messageId !== null) {
            $fields[] = 'message_id = ?';
            $values[] = $messageId;
        }

        if ($errorMessage !== null) {
            $fields[] = 'error_message = ?';
            $values[] = $errorMessage;
        }

        if ($phoneNumberId !== null) {
            $fields[] = 'phone_number_id = ?';
            $values[] = $phoneNumberId;
        }

        $values[] = $id;

        $sql = 'UPDATE whatsapp_campaign_messages SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($values);
    }

    /**
     * Delete campaign message
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM whatsapp_campaign_messages WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function getByStatus(int $campaignId, string $status): array
    {
        $stmt = $this->db->prepare("
            SELECT wcm.*, 
                   wt.name as template_name,
                   wt.language as template_language,
                   wt.components as template_components,
                   wc.name as contact_name,
                   wc.phone_number as contact_phone
            FROM whatsapp_campaign_messages wcm
            LEFT JOIN whatsapp_templates wt ON wcm.template_id = wt.id
            LEFT JOIN whatsapp_contacts wc ON wcm.contact_id = wc.id
            WHERE wcm.campaign_id = ? AND wcm.status = ?
            ORDER BY wcm.created_at DESC
        ");

        $stmt->execute([$campaignId, $status]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode template components JSON and add direction
        foreach ($messages as &$message) {
            $message['direction'] = 'outgoing'; // Campaign messages are always outgoing
            if (isset($message['template_components'])) {
                $message['template_components'] = json_decode($message['template_components'], true);
            }
        }

        return $messages;
    }

    /**
     * Get statistics for campaign messages
     */
    public function getStatsByCampaign(int $campaignId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM whatsapp_campaign_messages
            WHERE campaign_id = ?
        ");

        $stmt->execute([$campaignId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get unique contacts for a campaign with summary
     */
    public function getCampaignContacts(int $campaignId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                wcm.contact_id as id,
                COALESCE(wc.name, CONCAT('Contato ', SUBSTRING(wcm.contact_id, -4))) as name,
                COALESCE(wc.phone_number, 'N/A') as phone_number,
                COUNT(wcm.id) as total_messages,
                MAX(wcm.sent_at) as last_interaction_at,
                (
                    SELECT status 
                    FROM whatsapp_campaign_messages 
                    WHERE contact_id = wcm.contact_id
                    AND campaign_id = ? 
                    ORDER BY id DESC 
                    LIMIT 1
                ) as last_status,
                (
                    SELECT error_message 
                    FROM whatsapp_campaign_messages 
                    WHERE contact_id = wcm.contact_id
                    AND campaign_id = ? 
                    ORDER BY id DESC 
                    LIMIT 1
                ) as last_error_message
            FROM whatsapp_campaign_messages wcm
            LEFT JOIN whatsapp_contacts wc ON wcm.contact_id = wc.id
            WHERE wcm.campaign_id = ?
            GROUP BY wcm.contact_id, wc.name, wc.phone_number
            ORDER BY last_interaction_at DESC
        ");

        $stmt->execute([$campaignId, $campaignId, $campaignId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reset message for resend (set back to pending)
     */
    public function resetForResend(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE whatsapp_campaign_messages 
            SET status = 'pending',
                message_id = NULL,
                error_message = NULL,
                sent_at = NULL,
                delivered_at = NULL,
                read_at = NULL,
                failed_at = NULL
            WHERE id = ?
        ");

        return $stmt->execute([$id]);
    }

    /**
     * Bulk create messages for contacts
     */
    public function bulkCreate(array $messages): bool
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO whatsapp_campaign_messages 
                (campaign_id, contact_id, template_id, template_params, status)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($messages as $message) {
                $stmt->execute([
                    $message['campaign_id'],
                    $message['contact_id'],
                    $message['template_id'],
                    isset($message['template_params']) ? json_encode($message['template_params']) : null,
                    $message['status'] ?? 'pending'
                ]);
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
