<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Contact
{
    public int $id;
    public string $nome;
    public ?int $empresa_id;
    public ?string $cargo;
    public ?string $email;
    public ?string $telefone;
    public ?string $notas_privadas;
    public string $criado_em;
    public string $atualizado_em;

    // Associated company name (optional, for display)
    public ?string $empresa_nome = null;

    /**
     * Find a contact by ID.
     *
     * @param int $id
     * @return Contact|null
     */
    public static function findById(int $id): ?Contact
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT c.*, e.name as empresa_nome 
                                   FROM contacts c 
                                   LEFT JOIN companies e ON c.company_id = e.id 
                                   WHERE c.id = :id LIMIT 1");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $contactData = $stmt->fetch();

            if ($contactData) {
                return self::hydrate($contactData);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar contato por ID: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Find contacts by Company ID.
     *
     * @param int $companyId
     * @return array
     */
    public static function findByCompanyId(int $companyId): array
    {
        $contacts = [];
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE company_id = :company_id ORDER BY name ASC");
            $stmt->bindParam(":company_id", $companyId, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $contactData) {
                $contacts[] = self::hydrate($contactData);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar contatos por ID da empresa: " . $e->getMessage());
        }
        return $contacts;
    }

    /**
     * Get all contacts (with basic pagination).
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function findAll(int $limit = 25, int $offset = 0): array
    {
        $contacts = [];
        try {
            $pdo = Database::getInstance();
            // Join with empresas to get company name
            $stmt = $pdo->prepare("SELECT c.*, e.name as empresa_nome 
                                   FROM contacts c 
                                   LEFT JOIN companies e ON c.company_id = e.id 
                                   ORDER BY c.name ASC LIMIT :limit OFFSET :offset");
            $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $contactData) {
                $contacts[] = self::hydrate($contactData);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar todos os contatos: " . $e->getMessage());
        }
        return $contacts;
    }

    /**
     * Get total count of contacts.
     *
     * @return int
     */
    public static function countAll(): int
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT COUNT(*) FROM contacts");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erro ao contar contatos: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Create a new contact.
     *
     * @param array $data Associative array of contact data.
     * @return int|false The ID of the new contact or false on failure.
     */
    public static function create(array $data): int|false
    {
        $sql = "INSERT INTO contacts (name, company_id, position, email, phone, notes) 
                VALUES (:name, :company_id, :position, :email, :phone, :notes)";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            // Bind parameters
            $companyId = isset($data["company_id"]) && !empty($data["company_id"]) ? (int)$data["company_id"] : null;
            $stmt->bindParam(":name", $data["name"]);
            $stmt->bindParam(":company_id", $companyId, PDO::PARAM_INT);
            $stmt->bindParam(":position", $data["position"]);
            $stmt->bindParam(":email", $data["email"]);
            $stmt->bindParam(":phone", $data["phone"]);
            $stmt->bindParam(":notes", $data["notes"]);

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar contato: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Update an existing contact.
     *
     * @param int $id Contact ID.
     * @param array $data Associative array of data to update.
     * @return bool True on success, false on failure.
     */
    public static function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [":id" => $id];
        $allowedFields = ["name", "company_id", "position", "email", "phone", "notes"];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "`{$key}` = :{$key}";
                $paramType = PDO::PARAM_STR;
                if ($key === "company_id") {
                    $paramType = PDO::PARAM_INT;
                    $value = empty($value) ? null : (int)$value;
                }
                $params[":{$key}"] = $value;
            }
        }

        if (empty($fields)) {
            return false; // No valid fields to update
        }

        $sql = "UPDATE contacts SET " . implode(", ", $fields) . " WHERE id = :id";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar contato ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Delete a contact.
     *
     * @param int $id Contact ID.
     * @return bool True on success, false on failure.
     */
    public static function delete(int $id): bool
    {
        // Consider implications: deleting a contact might affect leads, proposals, etc.
        // FK constraints (ON DELETE SET NULL) handle some cases.
        $sql = "DELETE FROM contacts WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar contato ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Add an interaction history record for this contact.
     * Reuses the method from Lead model for simplicity, assuming historico_interacoes table structure.
     *
     * @param int $contactId
     * @param int|null $userId
     * @param string $tipo
     * @param string $descricao
     * @return bool
     */
    public static function addInteractionHistory(int $contactId, ?int $userId, string $tipo, string $descricao): bool
    {
        $sql = "INSERT INTO historico_interacoes (contato_id, usuario_id, tipo, descricao, data_interacao) 
                VALUES (:contato_id, :usuario_id, :tipo, :descricao, NOW())";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":contato_id", $contactId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $userId, PDO::PARAM_INT);
            $stmt->bindParam(":tipo", $tipo);
            $stmt->bindParam(":descricao", $descricao);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao adicionar histórico de interação para contato ID {$contactId}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Get interaction history for a specific contact.
     *
     * @param int $contactId
     * @return array
     */
    public static function getInteractionHistory(int $contactId): array
    {
        $history = [];
        $sql = "SELECT hi.*, u.name as usuario_nome 
                FROM historico_interacoes hi
                LEFT JOIN users u ON hi.usuario_id = u.id
                WHERE hi.contato_id = :contato_id 
                ORDER BY hi.data_interacao DESC";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":contato_id", $contactId, PDO::PARAM_INT);
            $stmt->execute();
            $history = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erro ao buscar histórico de interação para contato ID {$contactId}: " . $e->getMessage());
        }
        return $history;
    }

    /**
     * Hydrate a Contact object from database data.
     *
     * @param array $data
     * @return Contact
     */
    private static function hydrate(array $data): Contact
    {
        $contact = new self();
        $contact->id = (int)$data["id"];
        $contact->nome = $data["nome"];
        $contact->empresa_id = $data["empresa_id"] ? (int)$data["empresa_id"] : null;
        $contact->cargo = $data["cargo"];
        $contact->email = $data["email"];
        $contact->telefone = $data["telefone"];
        $contact->notas_privadas = $data["notas_privadas"];
        $contact->criado_em = $data["criado_em"];
        $contact->atualizado_em = $data["atualizado_em"];
        // Include associated company name if present in the data (from joins)
        $contact->empresa_nome = $data["empresa_nome"] ?? null;
        return $contact;
    }
}
