<?php

namespace Apoio19\Crm\Models;

use PDO;

class WhatsappContact
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_contacts (phone_number, name, lead_id, contact_id, metadata) 
             VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $data['phone_number'],
            $data['name'] ?? null,
            $data['lead_id'] ?? null,
            $data['contact_id'] ?? null,
            json_encode($data['metadata'] ?? [])
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowedFields = ['phone_number', 'name', 'lead_id', 'contact_id', 'metadata'];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "$key = ?";
                $values[] = ($key === 'metadata') ? json_encode($value) : $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;
        $sql = 'UPDATE whatsapp_contacts SET ' . implode(', ', $fields) . ' WHERE id = ?';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT wc.*, l.name as lead_name, c.name as contact_name
            FROM whatsapp_contacts wc
            LEFT JOIN leads l ON wc.lead_id = l.id
            LEFT JOIN contacts c ON wc.contact_id = c.id
            WHERE wc.id = ? AND wc.deleted_at IS NULL
        ');
        $stmt->execute([$id]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact && isset($contact['metadata'])) {
            $contact['metadata'] = json_decode($contact['metadata'], true);
        }

        return $contact ?: null;
    }

    public function findByPhoneNumber(string $phoneNumber): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_contacts WHERE phone_number = ? AND deleted_at IS NULL');
        $stmt->execute([$phoneNumber]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact && isset($contact['metadata'])) {
            $contact['metadata'] = json_decode($contact['metadata'], true);
        }

        return $contact ?: null;
    }


    public function getAll(array $filters = []): array
    {
        // Filtro por phone_number_id usa INNER JOIN para retornar apenas contatos
        // que têm mensagens associadas ao número selecionado
        $joinType = !empty($filters['phone_number_id']) ? 'INNER' : 'LEFT';

        if (!empty($filters['phone_number_id'])) {
            error_log("WhatsappContact::getAll - Strict filtering (INNER JOIN) for phone_number_id: " . $filters['phone_number_id']);
        }

        // Lista contatos que têm qualquer mensagem (recebidas, enviadas ou templates)
        $sql = "SELECT wc.*, 
                       l.name as lead_name, 
                       c.name as contact_name,
                       MAX(wcm.created_at) as last_message_at
                FROM whatsapp_contacts wc
                LEFT JOIN leads l ON wc.lead_id = l.id
                LEFT JOIN contacts c ON wc.contact_id = c.id
                $joinType JOIN whatsapp_chat_messages wcm ON wc.id = wcm.contact_id
                WHERE wc.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['lead_id'])) {
            $sql .= ' AND wc.lead_id = ?';
            $params[] = $filters['lead_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (wc.name LIKE ? OR wc.phone_number LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Filter by Meta API phone_number_id (strict filter - only exact matches)
        if (!empty($filters['phone_number_id'])) {
            $sql .= ' AND wcm.phone_number_id = ?';
            $params[] = $filters['phone_number_id'];
        }

        $sql .= ' GROUP BY wc.id
                  HAVING last_message_at IS NOT NULL
                  ORDER BY last_message_at DESC';

        if (!empty($filters['limit'])) {
            $sql .= ' LIMIT ?';
            $params[] = (int)$filters['limit'];
        }

        error_log("WhatsappContact::getAll - SQL: " . $sql);
        error_log("WhatsappContact::getAll - Params: " . json_encode($params));

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("WhatsappContact::getAll - Contatos encontrados: " . count($contacts));

        foreach ($contacts as &$contact) {
            if (isset($contact['metadata'])) {
                $contact['metadata'] = json_decode($contact['metadata'], true);
            }
        }

        return $contacts;
    }

    public function getAllRaw(array $filters = []): array
    {
        $sql = "SELECT wc.*, l.name as lead_name, c.name as contact_name
                FROM whatsapp_contacts wc
                LEFT JOIN leads l ON wc.lead_id = l.id
                LEFT JOIN contacts c ON wc.contact_id = c.id
                WHERE wc.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['search'])) {
            $sql .= ' AND (wc.name LIKE ? OR wc.phone_number LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= ' ORDER BY wc.name ASC, wc.phone_number ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($contacts as &$contact) {
            if (isset($contact['metadata'])) {
                $contact['metadata'] = json_decode($contact['metadata'], true);
            }
        }

        return $contacts;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE whatsapp_contacts SET deleted_at = NOW() WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function restore(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE whatsapp_contacts SET deleted_at = NULL WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function linkToLead(int $contactId, int $leadId): bool
    {
        $stmt = $this->db->prepare('UPDATE whatsapp_contacts SET lead_id = ? WHERE id = ?');
        return $stmt->execute([$leadId, $contactId]);
    }

    public function linkToContact(int $whatsappContactId, int $contactId): bool
    {
        $stmt = $this->db->prepare('UPDATE whatsapp_contacts SET contact_id = ? WHERE id = ?');
        return $stmt->execute([$contactId, $whatsappContactId]);
    }
}
