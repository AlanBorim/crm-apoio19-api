<?php

namespace Apoio19\Crm\Models;

use PDO;

class WhatsappCampaign
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_campaigns (user_id, phone_number_id, name, description, status, scheduled_at) 
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        
        $stmt->execute([
            $data['user_id'],
            $data['phone_number_id'] ?? null,
            $data['name'],
            $data['description'] ?? null,
            $data['status'] ?? 'draft',
            $data['scheduled_at'] ?? null
        ]);
        
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'description', 'status', 'scheduled_at', 'started_at', 'completed_at', 'phone_number_id'];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "$key = ?";
                $values[] = $value;
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        $sql = 'UPDATE whatsapp_campaigns SET ' . implode(', ', $fields) . ' WHERE id = ?';
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT wc.*, u.nome as user_name, wpn.name as phone_name, wpn.phone_number
            FROM whatsapp_campaigns wc
            LEFT JOIN users u ON wc.user_id = u.id
            LEFT JOIN whatsapp_phone_numbers wpn ON wc.phone_number_id = wpn.id
            WHERE wc.id = ?
        ');
        $stmt->execute([$id]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $campaign ?: null;
    }

    public function getAll(array $filters = []): array
    {
        $sql = 'SELECT wc.*, u.nome as user_name, wpn.name as phone_name,
                (SELECT COUNT(*) FROM whatsapp_campaign_messages WHERE campaign_id = wc.id) as total_messages,
                (SELECT COUNT(*) FROM whatsapp_campaign_messages WHERE campaign_id = wc.id AND status = "sent") as sent_count,
                (SELECT COUNT(*) FROM whatsapp_campaign_messages WHERE campaign_id = wc.id AND status = "delivered") as delivered_count,
                (SELECT COUNT(*) FROM whatsapp_campaign_messages WHERE campaign_id = wc.id AND status = "read") as read_count,
                (SELECT COUNT(*) FROM whatsapp_campaign_messages WHERE campaign_id = wc.id AND status = "failed") as failed_count
                FROM whatsapp_campaigns wc 
                LEFT JOIN users u ON wc.user_id = u.id 
                LEFT JOIN whatsapp_phone_numbers wpn ON wc.phone_number_id = wpn.id
                WHERE 1=1';
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $sql .= ' AND wc.user_id = ?';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= ' AND wc.status = ?';
            $params[] = $filters['status'];
        }
        
        $sql .= ' ORDER BY wc.created_at DESC';
        
        if (!empty($filters['limit'])) {
            $sql .= ' LIMIT ?';
            $params[] = (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM whatsapp_campaigns WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function getStats(int $campaignId): array
    {
        $stmt = $this->db->prepare('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = "read" THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending
            FROM whatsapp_campaign_messages 
            WHERE campaign_id = ?
        ');
        
        $stmt->execute([$campaignId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function grantAccess(int $campaignId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO whatsapp_campaign_access (campaign_id, user_id) VALUES (?, ?)'
        );
        
        return $stmt->execute([$campaignId, $userId]);
    }

    public function revokeAccess(int $campaignId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM whatsapp_campaign_access WHERE campaign_id = ? AND user_id = ?'
        );
        
        return $stmt->execute([$campaignId, $userId]);
    }

    public function getAccessList(int $campaignId): array
    {
        $stmt = $this->db->prepare('
            SELECT u.id, u.nome as name, u.email, wca.created_at as granted_at
            FROM whatsapp_campaign_access wca
            JOIN users u ON wca.user_id = u.id
            WHERE wca.campaign_id = ?
            ORDER BY wca.created_at DESC
        ');
        
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare('UPDATE whatsapp_campaigns SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $id]);
    }

    public function markAsStarted(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE whatsapp_campaigns 
            SET status = "processing", started_at = NOW() 
            WHERE id = ?
        ');
        return $stmt->execute([$id]);
    }

    public function markAsCompleted(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE whatsapp_campaigns 
            SET status = "completed", completed_at = NOW() 
            WHERE id = ?
        ');
        return $stmt->execute([$id]);
    }
}
