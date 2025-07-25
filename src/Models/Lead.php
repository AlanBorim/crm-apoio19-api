<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Lead
{
    public int $id;
    public string $name;
    public ?string $company;
    public ?string $email;
    public ?string $phone;
    public ?string $source;
    public ?string $interest;
    public string $status;
    public ?string $stage;
    public ?int $assigned_to;
    public ?int $responsavel_id = null;
    public ?string $cep;
    public ?string $city;
    public ?string $state;  
    public ?string $address;
    public int $value = 0; // Default to 0 if not set
    public ?string $last_contact;
    public ?string $next_contact;
    public ?string $position; // Added position field
    public ?string $temperature; // Added temperature field
    public ?int $contato_id;
    public ?int $empresa_id;
    public string $created_at;
    public string $atualizado_em;

     /**
     * Find a lead by ID.
     *
     * @param int $id
     * @return Lead|null
     */
    public static function findById(int $id): ?Lead
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = :id LIMIT 1");
            // Use bindValue instead of bindParam for consistency and simpler mocking
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            // Fetch using the default mode set in Database class (FETCH_ASSOC)
            $leadData = $stmt->fetch();

            if ($leadData) {
                return self::hydrate($leadData);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar lead por ID: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get all leads (with basic pagination).
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function findAll(int $limit = 25, int $offset = 0): array
    {
        $leads = [];
        try {
            $pdo = Database::getInstance();
            // Add ORDER BY for consistent results
            $stmt = $pdo->prepare("SELECT * FROM leads ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            foreach ($results as $leadData) {
                $leads[] = self::hydrate($leadData);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar todos os leads: " . $e->getMessage());
        }
        return $leads;
    }

    /**
     * Get total count of leads.
     *
     * @return int
     */
    public static function countAll(): int
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT COUNT(*) FROM leads");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erro ao contar leads: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Create a new lead.
     *
     * @param array $data Associative array of lead data.
     * @return int|false The ID of the new lead or false on failure.
     */
    public static function create(array $data): int|false
    {
        // Basic validation/sanitization should happen in the controller or service layer
        $fields = implode(", ", array_keys($data));
        $placeholders = ":" . implode(", :", array_keys($data));
        $sql = "INSERT INTO leads ({$fields}) VALUES ({$placeholders})";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            // Bind values using bindValue (avoids reference issues with mocks)
            foreach ($data as $key => $value) {
                $paramType = PDO::PARAM_STR;
                if ($key === 'assigned_to') {
                    $paramType = PDO::PARAM_INT;
                }

                // Add other type checks if necessary (e.g., for dates, booleans)
                $stmt->bindValue(":{$key}", $value, $paramType);
            }

            if ($stmt->execute()) {

                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar lead: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Update an existing lead.
     *
     * @param int $id Lead ID.
     * @param array $data Associative array of data to update.
     * @return bool True on success, false on failure.
     */
    public static function update(int $id, array $data): bool
    {
        // Build the SET part of the SQL query dynamically
        $fields = [];
        $params = [":id" => $id];
        foreach ($data as $key => $value) {
            // Allow updating only specific fields          
            if (in_array($key, ['name', 'company','address','cep','city','state','position','stage','email', 'phone', 'source', 'interest', 'next_contact', 'temperature', 'assined_to'])) {
                $fields[] = "`{$key}` = :{$key}";
                $paramType = PDO::PARAM_STR;
                if ($key === 'assigned_to' || $key === 'value') {
                    $paramType = PDO::PARAM_INT;
                    $value = empty($value) ? null : (int)$value;
                }
                // Add other type checks if necessary
                $params[":{$key}"] = $value; // Store value for execute
            }
        }

        if (empty($fields)) {
            return false; // No valid fields to update
        }

        $sql = "UPDATE leads SET " . implode(", ", $fields) . " WHERE id = :id";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            // Execute with the parameter array directly (works well with bindValue logic)
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar lead ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Delete a lead.
     *
     * @param int $id Lead ID.
     * @return bool True on success, false on failure.
     */
    public static function delete(int $id): bool
    {
        // Consider soft deletes instead of hard deletes in a real CRM
        $sql = "DELETE FROM leads WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar lead ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Add an interaction history record for this lead.
     *
     * @param int $leadId
     * @param int|null $userId
     * @param string $tipo
     * @param string $descricao
     * @return bool
     */
    public static function addInteractionHistory(int $leadId, ?int $userId, string $tipo, string $descricao): bool
    {
        $sql = "INSERT INTO historico_interacoes (lead_id, usuario_id, tipo, descricao, data_interacao) 
                VALUES (:lead_id, :usuario_id, :tipo, :descricao, NOW())";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":lead_id", $leadId, PDO::PARAM_INT);
            $stmt->bindValue(":usuario_id", $userId, PDO::PARAM_INT);
            $stmt->bindValue(":tipo", $tipo);
            $stmt->bindValue(":descricao", $descricao);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao adicionar histórico de interação para lead ID {$leadId}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Get interaction history for a specific lead.
     *
     * @param int $leadId
     * @return array
     */
    public static function getInteractionHistory(int $leadId): array
    {
        $history = [];
        $sql = "SELECT hi.*, u.nome as usuario_nome 
                FROM historico_interacoes hi
                LEFT JOIN usuarios u ON hi.usuario_id = u.id
                WHERE hi.lead_id = :lead_id 
                ORDER BY hi.data_interacao DESC";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":lead_id", $leadId, PDO::PARAM_INT);
            $stmt->execute();
            $history = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erro ao buscar histórico de interação para lead ID {$leadId}: " . $e->getMessage());
        }
        return $history;
    }

    /**
     * Get leads by status.
     *
     * @param string $status
     * @return array
     */
    public static function getStats(): array
    {
        $stats = [];
        try {
            $pdo = Database::getInstance();
            // Total de leads
            $stmtTotal = $pdo->query("SELECT COUNT(*) FROM leads");
            $total = (int) $stmtTotal->fetchColumn();

            // Leads de hoje
            $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = CURDATE()");
            $stmtToday->execute();
            $today = (int) $stmtToday->fetchColumn();

            // Leads de ontem
            $stmtYesterday = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY");
            $stmtYesterday->execute();
            $yesterday = (int) $stmtYesterday->fetchColumn();

            // Crescimento absoluto
            $growth = $today - $yesterday;

            // Crescimento percentual (com 1 casa decimal), ou null se não há base para cálculo
            $growthPercent = $yesterday > 0 ? round(($growth / $yesterday) * 100, 1) : '0.0';

            return [
                "total" => $total,
                "today" => $today,
                "growth" => $growth,
                "growth_percent" => $growthPercent
            ];
        } catch (PDOException $e) {
            error_log("Erro ao buscar estatísticas de leads: " . $e->getMessage());
        }
        return $stats;
    }




    /**
     * Hydrate a Lead object from database data.
     *
     * @param array $data
     * @return Lead
     */
    private static function hydrate(array $data): Lead
    {
        $lead = new self();
        $lead->id = (int)$data["id"];
        $lead->name = $data["name"];
        $lead->company = $data["company"] ?? null;
        $lead->email = $data["email"] ?? null;
        $lead->position = $data["position"] ?? null;
        $lead->phone = $data["phone"] ?? null;
        $lead->source = $data["source"] ?? null;
        $lead->interest = $data["interest"] ?? null;
        $lead->temperature = $data["temperature"] ?? null;
        // Ensure qualificacao has a default if null/missing from DB data, matching the property type hint
        $lead->stage = $data["stage"];
        $lead->assigned_to = isset($data["assigned_to"]) ? (int)$data["assigned_to"] : null;
        $lead->cep = $data["cep"] ?? null;
        $lead->city = $data["city"] ?? null;
        $lead->state = $data["state"] ?? null;
        $lead->address = $data["address"] ?? null;
        $lead->value = isset($data["value"]) ? (int)$data["value"] : 0; // Default to 0 if not set
        $lead->last_contact = $data["last_contact"] ?? null;
        $lead->next_contact = $data["next_contact"] ?? null;
        // Use current time as default for created_at if not provided
        $lead->created_at = $data["created_at"] ?? date('Y-m-d H:i:s'); // Provide default
        return $lead;
    }
}
