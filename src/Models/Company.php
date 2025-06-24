<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Company
{
    public int $id;
    public string $nome;
    public ?string $cnpj;
    public ?string $endereco;
    public ?string $telefone;
    public ?string $email;
    public ?string $segmento;
    public string $criado_em;
    public string $atualizado_em;

    /**
     * Find a company by ID.
     *
     * @param int $id
     * @return Company|null
     */
    public static function findById(int $id): ?Company
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = :id LIMIT 1");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $companyData = $stmt->fetch();

            if ($companyData) {
                return self::hydrate($companyData);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar empresa por ID: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get all companies (with basic pagination).
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function findAll(int $limit = 25, int $offset = 0): array
    {
        $companies = [];
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM empresas ORDER BY nome ASC LIMIT :limit OFFSET :offset");
            $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $companyData) {
                $companies[] = self::hydrate($companyData);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar todas as empresas: " . $e->getMessage());
        }
        return $companies;
    }

    /**
     * Get total count of companies.
     *
     * @return int
     */
    public static function countAll(): int
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT COUNT(*) FROM empresas");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erro ao contar empresas: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Create a new company.
     *
     * @param array $data Associative array of company data.
     * @return int|false The ID of the new company or false on failure.
     */
    public static function create(array $data): int|false
    {
        $sql = "INSERT INTO empresas (nome, cnpj, endereco, telefone, email, segmento) 
                VALUES (:nome, :cnpj, :endereco, :telefone, :email, :segmento)";
        
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            // Bind parameters
            $stmt->bindParam(":nome", $data["nome"]);
            $stmt->bindParam(":cnpj", $data["cnpj"]);
            $stmt->bindParam(":endereco", $data["endereco"]);
            $stmt->bindParam(":telefone", $data["telefone"]);
            $stmt->bindParam(":email", $data["email"]);
            $stmt->bindParam(":segmento", $data["segmento"]);

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar empresa: " . $e->getMessage());
            // Handle specific errors like duplicate CNPJ if needed
        }
        return false;
    }

    /**
     * Update an existing company.
     *
     * @param int $id Company ID.
     * @param array $data Associative array of data to update.
     * @return bool True on success, false on failure.
     */
    public static function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [":id" => $id];
        $allowedFields = ["nome", "cnpj", "endereco", "telefone", "email", "segmento"];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "`{$key}` = :{$key}";
                $params[":{$key}"] = $value;
            }
        }

        if (empty($fields)) {
            return false; // No valid fields to update
        }

        $sql = "UPDATE empresas SET " . implode(", ", $fields) . " WHERE id = :id";

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar empresa ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Delete a company.
     *
     * @param int $id Company ID.
     * @return bool True on success, false on failure.
     */
    public static function delete(int $id): bool
    {
        // Consider implications: deleting a company might affect contacts, proposals, etc.
        // FK constraints (ON DELETE SET NULL) will handle some cases, but business logic might be needed.
        $sql = "DELETE FROM empresas WHERE id = :id";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar empresa ID {$id}: " . $e->getMessage());
        }
        return false;
    }
    
    /**
     * Get contacts associated with this company.
     *
     * @param int $companyId
     * @return array
     */
    public static function getContacts(int $companyId): array
    {
        return Contact::findByCompanyId($companyId);
    }
    
    // TODO: Add methods for payment history and fiscal notes if needed directly in this model
    // Or create separate models/services for them.

    /**
     * Hydrate a Company object from database data.
     *
     * @param array $data
     * @return Company
     */
    private static function hydrate(array $data): Company
    {
        $company = new self();
        $company->id = (int)$data["id"];
        $company->nome = $data["nome"];
        $company->cnpj = $data["cnpj"];
        $company->endereco = $data["endereco"];
        $company->telefone = $data["telefone"];
        $company->email = $data["email"];
        $company->segmento = $data["segmento"];
        $company->criado_em = $data["criado_em"];
        $company->atualizado_em = $data["atualizado_em"];
        return $company;
    }
}

