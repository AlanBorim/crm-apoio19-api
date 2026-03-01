<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class ClientProject
{
    public int $id;
    public int $client_id;
    public string $name;
    public ?string $description;
    public string $status;
    public ?string $start_date;
    public ?string $end_date;
    public ?float $value;
    public string $created_at;
    public string $updated_at;
    public ?string $deleted_at = null;

    /**
     * Find project by ID.
     *
     * @param int $id
     * @return ClientProject|null
     */
    public static function findById(int $id): ?ClientProject
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT * FROM client_projects WHERE id = :id AND deleted_at IS NULL LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch();

            if ($data) {
                return self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar projeto por ID: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Find projects by Client ID.
     *
     * @param int $clientId
     * @return array
     */
    public static function findByClientId(int $clientId): array
    {
        $projects = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT * FROM client_projects WHERE client_id = :client_id AND deleted_at IS NULL ORDER BY created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":client_id", $clientId, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $projects[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar projetos do cliente ID {$clientId}: " . $e->getMessage());
        }
        return $projects;
    }

    /**
     * Create a new project.
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
            $sql = "INSERT INTO client_projects ({$fields}) VALUES ({$placeholders})";

            $stmt = $pdo->prepare($sql);

            foreach ($data as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar projeto: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Update a project.
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

            $sql = "UPDATE client_projects SET " . implode(", ", $fields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            $data['id'] = $id;
            foreach ($data as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar projeto ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Delete a project.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        try {
            $pdo = Database::getInstance();
            $sql = "UPDATE client_projects SET deleted_at = NOW() WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao deletar projeto ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Restore a project.
     *
     * @param int $id
     * @return bool
     */
    public static function restore(int $id): bool
    {
        try {
            $pdo = Database::getInstance();
            $sql = "UPDATE client_projects SET deleted_at = NULL WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao restaurar projeto ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Hydrate object.
     *
     * @param array $data
     * @return ClientProject
     */
    private static function hydrate(array $data): ClientProject
    {
        $project = new self();
        $project->id = (int)$data['id'];
        $project->client_id = (int)$data['client_id'];
        $project->name = $data['name'];
        $project->description = $data['description'] ?? null;
        $project->status = $data['status'] ?? 'pending';
        $project->start_date = $data['start_date'] ?? null;
        $project->end_date = $data['end_date'] ?? null;
        $project->value = isset($data['value']) ? (float)$data['value'] : null;
        $project->created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
        $project->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');

        return $project;
    }
}
