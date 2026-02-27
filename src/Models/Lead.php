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
    public ?string $source_extra;
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
    public string $updated_at;
    public ?string $responsavelNome; // Nullable for optional updates
    public string $atualizado_em;
    public int $active; // Default to active if not set

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
            $stmt = $pdo->prepare("SELECT l.*,u.name AS responsavelNome FROM leads l
                                    LEFT JOIN users AS u on u.id = l.assigned_to
                                    WHERE l.id = :id AND l.deleted_at IS NULL LIMIT 1");
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
            $stmt = $pdo->prepare("SELECT * FROM leads WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
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
            $stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL");
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
    public static function create(array $data): mixed
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
        // se o id estiver desativado impede o update
        $lead = self::findById($id);
        if (!$lead || $lead->active === 0) {
            return false;
        }

        // Build the SET part of the SQL query dynamically
        $fields = [];
        $params = [":id" => $id];
        foreach ($data as $key => $value) {
            // Allow updating only specific fields
            if (in_array($key, ['name', 'company', 'address', 'cep', 'city', 'state', 'position', 'stage', 'email', 'phone', 'source', 'source_extra', 'interest', 'next_contact', 'temperature', 'assigned_to', 'value'])) {
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
     * Delete a lead (Soft Delete).
     *
     * @param int $id Lead ID.
     * @return bool True on success, false on failure.
     */
    public static function delete(int $id): bool
    {
        $sql = "UPDATE leads SET deleted_at = NOW(), active = :active WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->bindValue(":active", '0', PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar (soft delete) lead ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Restore a deleted lead.
     *
     * @param int $id Lead ID.
     * @return bool True on success, false on failure.
     */
    public static function restore(int $id): bool
    {
        $sql = "UPDATE leads SET deleted_at = NULL, active = :active WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->bindValue(":active", '1', PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao restaurar lead ID {$id}: " . $e->getMessage());
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
        $sql = "SELECT hi.*, u.name as usuario_nome 
                FROM historico_interacoes hi
                LEFT JOIN users u ON hi.usuario_id = u.id
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
            $stmtTotal = $pdo->query("SELECT COUNT(*) FROM leads WHERE active = '1'");
            $total = (int) $stmtTotal->fetchColumn();

            // Leads de hoje
            $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE active = '1' AND DATE(created_at) = CURDATE()");
            $stmtToday->execute();
            $today = (int) $stmtToday->fetchColumn();

            // Leads de ontem
            $stmtYesterday = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE active = '1' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY");
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

    public static function findAllWithWhere(string $where = '', array $params = []): array
    {
        $pdo = Database::getInstance();
        $sql = "SELECT * FROM leads {$where} ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Carregar configurações de leads com filtro opcional por tipo
     *
     * @param string|null $type Tipo de configuração (source, stage, temperature)
     * @return array Array de configurações
     */
    public function loadLeadSettings(?string $type = null): array
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT * FROM lead_settings";
            $params = [];

            if ($type) {
                $sql .= " WHERE type = :type";
                $params[':type'] = $type;
            }

            $sql .= " ORDER BY created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $settings = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Processar meta_config de JSON string para array
            foreach ($settings as &$setting) {
                if ($setting['meta_config']) {
                    $setting['meta_config'] = json_decode($setting['meta_config'], true);
                }
            }

            return $settings;
        } catch (PDOException $e) {
            error_log("Erro ao carregar configurações de leads: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Criar nova configuração de lead
     *
     * @param array $data Dados da configuração
     * @return int|false ID da nova configuração ou false em caso de erro
     */
    public function createLeadSettings(array $data): mixed
    {
        try {
            $pdo = Database::getInstance();

            $fields = implode(", ", array_keys($data));
            $placeholders = ":" . implode(", :", array_keys($data));
            $sql = "INSERT INTO lead_settings ({$fields}) VALUES ({$placeholders})";

            $stmt = $pdo->prepare($sql);

            // Bind values
            foreach ($data as $key => $value) {
                $stmt->bindValue(":{$key}", $value, PDO::PARAM_STR);
            }

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar configuração de lead: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Buscar configuração de lead por ID
     *
     * @param int $id ID da configuração
     * @return array|null Dados da configuração ou null se não encontrada
     */
    public function findLeadSettingById(int $id): ?array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM lead_settings WHERE id = :id LIMIT 1");
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();

            $setting = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($setting) {
                // Processar meta_config de JSON string para array
                if ($setting['meta_config']) {
                    $setting['meta_config'] = json_decode($setting['meta_config'], true);
                }
                return $setting;
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar configuração de lead por ID: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Atualizar configuração de lead
     *
     * @param int $id ID da configuração
     * @param array $data Dados para atualização
     * @return bool True em caso de sucesso, false caso contrário
     */
    public function updateLeadSettings(int $id, array $data): bool
    {
        try {
            $pdo = Database::getInstance();

            // Construir campos para atualização
            $fields = [];
            $params = [":id" => $id];

            foreach ($data as $key => $value) {
                if (in_array($key, ['type', 'value', 'meta_config'])) {
                    $fields[] = "`{$key}` = :{$key}";
                    $params[":{$key}"] = $value;
                }
            }

            if (empty($fields)) {
                return false; // Nenhum campo válido para atualizar
            }

            $sql = "UPDATE lead_settings SET " . implode(", ", $fields) . " WHERE id = :id";

            $stmt = $pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar configuração de lead ID {$id}: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Excluir configuração de lead
     *
     * @param int $id ID da configuração
     * @return bool True em caso de sucesso, false caso contrário
     */
    public function deleteLeadSettings(int $id): bool
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("DELETE FROM lead_settings WHERE id = :id");
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao excluir configuração de lead ID {$id}: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Verificar se uma configuração existe
     *
     * @param string $type Tipo da configuração
     * @param string $value Valor da configuração
     * @return bool True se existe, false caso contrário
     */
    public function leadSettingExists(string $type, string $value): bool
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lead_settings WHERE type = :type AND value = :value");
            $stmt->bindValue(":type", $type, PDO::PARAM_STR);
            $stmt->bindValue(":value", $value, PDO::PARAM_STR);
            $stmt->execute();

            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Erro ao verificar existência de configuração: " . $e->getMessage());
        }

        return false;
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
        $lead->source_extra = $data['source_extra'] ?? null;
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
        $lead->responsavelNome = $data["responsavelNome"] ?? null;
        // Use current time as default for created_at if not provided
        $lead->created_at = $data["created_at"] ?? date('Y-m-d H:i:s'); // Provide default
        $lead->updated_at = $data["updated_at"] ?? date('Y-m-d H:i:s'); // Provide default
        $lead->active = $data["active"] ?? 1; // Default to active if not set
        return $lead;
    }
}
