<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Withdrawal
{
    public int $id;
    public int $user_id;
    public float $amount;
    public string $status;
    public ?string $bank_details;
    public string $requested_at;
    public ?string $processed_at;
    public ?string $pagarme_transfer_id;
    public string $created_at;
    public string $updated_at;

    // Relational data
    public ?string $user_name = null;

    public static function findById(int $id): ?Withdrawal
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT w.*, u.name as user_name
                    FROM withdrawals w
                    JOIN users u ON w.user_id = u.id
                    WHERE w.id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch();

            if ($data) {
                return self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar saque por ID: " . $e->getMessage());
        }
        return null;
    }

    public static function findAll(int $limit = 50, int $offset = 0): array
    {
        $withdrawals = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT w.*, u.name as user_name 
                    FROM withdrawals w
                    JOIN users u ON w.user_id = u.id
                    ORDER BY w.requested_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $withdrawals[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar saques: " . $e->getMessage());
        }
        return $withdrawals;
    }

    private static array $allowedFields = [
        'user_id',
        'amount',
        'status',
        'bank_details',
        'requested_at',
        'processed_at',
        'pagarme_transfer_id'
    ];

    public static function create(array $data): mixed
    {
        try {
            $pdo = Database::getInstance();
            $filteredData = array_intersect_key($data, array_flip(self::$allowedFields));

            if (empty($filteredData)) {
                return false;
            }

            $fields = implode(", ", array_keys($filteredData));
            $placeholders = ":" . implode(", :", array_keys($filteredData));
            $sql = "INSERT INTO withdrawals ({$fields}) VALUES ({$placeholders})";

            $stmt = $pdo->prepare($sql);

            foreach ($filteredData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar saque: " . $e->getMessage());
        }
        return false;
    }

    public static function update(int $id, array $data): bool
    {
        try {
            $pdo = Database::getInstance();
            $filteredData = array_intersect_key($data, array_flip(self::$allowedFields));

            $fields = [];
            foreach ($filteredData as $key => $value) {
                $fields[] = "`{$key}` = :{$key}";
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE withdrawals SET " . implode(", ", $fields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            foreach ($filteredData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar saque ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    private static function hydrate(array $data): Withdrawal
    {
        $wd = new self();
        $wd->id = (int)$data['id'];
        $wd->user_id = (int)$data['user_id'];
        $wd->amount = (float)$data['amount'];
        $wd->status = $data['status'];
        $wd->bank_details = $data['bank_details'] ?? null;
        $wd->requested_at = $data['requested_at'] ?? date('Y-m-d H:i:s');
        $wd->processed_at = $data['processed_at'] ?? null;
        $wd->pagarme_transfer_id = $data['pagarme_transfer_id'] ?? null;
        $wd->created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
        $wd->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');

        $wd->user_name = $data['user_name'] ?? null;

        return $wd;
    }
}
