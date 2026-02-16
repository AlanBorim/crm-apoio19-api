<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Client
{
    public int $id;
    public ?int $lead_id;
    public ?int $company_id;
    public ?int $contact_id;
    public string $status;
    public ?string $start_date;
    public ?string $notes;
    public string $created_at;
    public string $updated_at;

    // Helper properties for joined data
    public ?string $lead_name = null;
    public ?string $company_name = null;
    public ?string $contact_name = null;

    // Fiscal Data
    public ?string $person_type = 'PJ';
    public ?string $document = null;
    public ?string $corporate_name = null;
    public ?string $fantasy_name = null;
    public ?string $state_registration = null;
    public ?string $municipal_registration = null;
    public ?string $zip_code = null;
    public ?string $address = null;
    public ?string $address_number = null;
    public ?string $complement = null;
    public ?string $district = null;
    public ?string $city = null;
    public ?string $state = null;

    /**
     * Find a client by ID.
     *
     * @param int $id
     * @return Client|null
     */
    public static function findById(int $id): ?Client
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT c.*, l.name as lead_name, comp.name as company_name, cont.name as contact_name
                    FROM clients c
                    LEFT JOIN leads l ON c.lead_id = l.id
                    LEFT JOIN companies comp ON c.company_id = comp.id
                    LEFT JOIN contacts cont ON c.contact_id = cont.id
                    WHERE c.id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch();

            if ($data) {
                return self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar cliente por ID: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Find a client by Lead ID.
     *
     * @param int $leadId
     * @return Client|null
     */
    public static function findByLeadId(int $leadId): ?Client
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT * FROM clients WHERE lead_id = :lead_id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":lead_id", $leadId, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch();

            if ($data) {
                return self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar cliente por Lead ID: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get all clients.
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function findAll(int $limit = 25, int $offset = 0): array
    {
        $clients = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT c.*, l.name as lead_name, comp.name as company_name 
                    FROM clients c
                    LEFT JOIN leads l ON c.lead_id = l.id
                    LEFT JOIN companies comp ON c.company_id = comp.id
                    ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $clients[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar todos os clientes: " . $e->getMessage());
        }
        return $clients;
    }

    /**
     * Create a new client.
     *
     * @param array $data
     * @return int|false
     */
    public static function create(array $data): mixed
    {
        try {
            $pdo = Database::getInstance();

            $fields = implode(", ", array_keys($data));
            $placeholders = ":" . implode(", :", array_keys($data));
            $sql = "INSERT INTO clients ({$fields}) VALUES ({$placeholders})";

            $stmt = $pdo->prepare($sql);

            foreach ($data as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar cliente: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Update a client.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function update(int $id, array $data): bool
    {
        try {
            $pdo = Database::getInstance();

            $fields = [];
            foreach ($data as $key => $value) {
                $fields[] = "`{$key}` = :{$key}";
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE clients SET " . implode(", ", $fields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            $data['id'] = $id;
            foreach ($data as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar cliente ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Hydrate object.
     *
     * @param array $data
     * @return Client
     */
    private static function hydrate(array $data): Client
    {
        $client = new self();
        $client->id = (int)$data['id'];
        $client->lead_id = isset($data['lead_id']) ? (int)$data['lead_id'] : null;
        $client->company_id = isset($data['company_id']) ? (int)$data['company_id'] : null;
        $client->contact_id = isset($data['contact_id']) ? (int)$data['contact_id'] : null;
        $client->status = $data['status'] ?? 'active';
        $client->start_date = $data['start_date'] ?? null;
        $client->notes = $data['notes'] ?? null;
        $client->created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
        $client->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');

        // Optional joined fields
        $client->lead_name = $data['lead_name'] ?? null;
        $client->company_name = $data['company_name'] ?? null;
        $client->contact_name = $data['contact_name'] ?? null;

        // Fiscal Data
        $client->person_type = $data['person_type'] ?? 'PJ';
        $client->document = $data['document'] ?? null;
        $client->corporate_name = $data['corporate_name'] ?? null;
        $client->fantasy_name = $data['fantasy_name'] ?? null;
        $client->state_registration = $data['state_registration'] ?? null;
        $client->municipal_registration = $data['municipal_registration'] ?? null;
        $client->zip_code = $data['zip_code'] ?? null;
        $client->address = $data['address'] ?? null;
        $client->address_number = $data['address_number'] ?? null;
        $client->complement = $data['complement'] ?? null;
        $client->district = $data['district'] ?? null;
        $client->city = $data['city'] ?? null;
        $client->state = $data['state'] ?? null;

        return $client;
    }
}
