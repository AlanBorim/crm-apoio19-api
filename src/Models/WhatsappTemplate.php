<?php

namespace Apoio19\Crm\Models;

use PDO;

class WhatsappTemplate
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_templates (template_id, name, language, category, status, components) 
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $data['template_id'],
            $data['name'],
            $data['language'] ?? 'pt_BR',
            $data['category'],
            $data['status'] ?? 'PENDING',
            json_encode($data['components'] ?? [])
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowedFields = ['template_id', 'name', 'language', 'category', 'status', 'components'];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "$key = ?";
                $values[] = ($key === 'components') ? json_encode($value) : $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;
        $sql = 'UPDATE whatsapp_templates SET ' . implode(', ', $fields) . ' WHERE id = ?';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_templates WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($template && isset($template['components'])) {
            $template['components'] = json_decode($template['components'], true);
        }

        return $template ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_templates WHERE name = ? AND deleted_at IS NULL');
        $stmt->execute([$name]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($template && isset($template['components'])) {
            $template['components'] = json_decode($template['components'], true);
        }

        return $template ?: null;
    }

    public function getAll(array $filters = []): array
    {
        $sql = 'SELECT * FROM whatsapp_templates WHERE deleted_at IS NULL';
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['category'])) {
            $sql .= ' AND category = ?';
            $params[] = $filters['category'];
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($templates as &$template) {
            if (isset($template['components'])) {
                $template['components'] = json_decode($template['components'], true);
            }
        }

        return $templates;
    }

    public function getApproved(): array
    {
        return $this->getAll(['status' => 'APPROVED']);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE whatsapp_templates SET deleted_at = NOW() WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function restore(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE whatsapp_templates SET deleted_at = NULL WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare('UPDATE whatsapp_templates SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $id]);
    }
}
