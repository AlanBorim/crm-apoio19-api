<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Subscription
{
    public int $id;
    public int $client_id;
    public float $amount;
    public string $billing_cycle;
    public ?string $next_billing_date;
    public string $status;
    public ?string $pagarme_subscription_id;
    public string $created_at;
    public string $updated_at;
    public ?string $item_type = null;
    public ?string $due_date = null;
    public ?string $boleto_url = null;

    // Relational data
    public ?string $client_name = null;
    public ?string $client_document = null;

    public static function findById(int $id): ?Subscription
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT s.*, c.corporate_name as client_name, c.document as client_document
                    FROM client_subscriptions s
                    JOIN clients c ON s.client_id = c.id
                    WHERE s.id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch();

            if ($data) {
                return self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar assinatura por ID: " . $e->getMessage());
        }
        return null;
    }

    public static function findByClientId(int $clientId): array
    {
        $subscriptions = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT * FROM client_subscriptions WHERE client_id = :client_id ORDER BY created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":client_id", $clientId, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $subscriptions[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar assinaturas por Client ID: " . $e->getMessage());
        }
        return $subscriptions;
    }

    public static function findAll(int $limit = 50, int $offset = 0): array
    {
        $subscriptions = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT s.*, c.corporate_name as client_name, c.document as client_document 
                    FROM client_subscriptions s
                    JOIN clients c ON s.client_id = c.id
                    ORDER BY s.created_at DESC 
                    LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $subscriptions[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar todas as assinaturas: " . $e->getMessage());
        }
        return $subscriptions;
    }

    private static array $allowedFields = [
        'client_id',
        'amount',
        'billing_cycle',
        'next_billing_date',
        'status',
        'pagarme_subscription_id'
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
            $sql = "INSERT INTO client_subscriptions ({$fields}) VALUES ({$placeholders})";

            $stmt = $pdo->prepare($sql);

            foreach ($filteredData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar assinatura: " . $e->getMessage());
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

            $sql = "UPDATE client_subscriptions SET " . implode(", ", $fields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            foreach ($filteredData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar assinatura ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    private static function hydrate(array $data): Subscription
    {
        $sub = new self();
        $sub->id = (int)$data['id'];
        $sub->client_id = (int)$data['client_id'];
        $sub->amount = (float)$data['amount'];
        $sub->billing_cycle = $data['billing_cycle'];
        $sub->next_billing_date = $data['next_billing_date'] ?? null;
        $sub->status = $data['status'];
        $sub->pagarme_subscription_id = $data['pagarme_subscription_id'] ?? null;
        $sub->created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
        $sub->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');

        $sub->client_name = $data['client_name'] ?? null;
        $sub->client_document = $data['client_document'] ?? null;

        return $sub;
    }
}
