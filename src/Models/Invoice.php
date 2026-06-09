<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Invoice
{
    public int $id;
    public int $client_id;
    public ?int $subscription_id;
    public float $amount;
    public string $status;
    public string $due_date;
    public ?string $payment_date;
    public ?string $pagarme_order_id;
    public ?string $pagarme_charge_id;
    public ?string $boleto_url;
    public ?string $boleto_barcode;
    public ?string $nfe_id;
    public ?string $nfe_status;
    public ?string $nfe_url;
    public ?string $nfe_xml;
    public ?string $nfe_pdf;
    public string $created_at;
    public string $updated_at;
    public ?string $item_type = null;

    // Relational data
    public ?string $client_name = null;

    public static function findById(int $id): ?Invoice
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT i.*, c.corporate_name as client_name
                    FROM invoices i
                    JOIN clients c ON i.client_id = c.id
                    WHERE i.id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch();

            if ($data) {
                return self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar fatura por ID: " . $e->getMessage());
        }
        return null;
    }

    public static function findByChargeId(string $chargeId): ?Invoice
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT * FROM invoices WHERE pagarme_charge_id = :charge_id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":charge_id", $chargeId, PDO::PARAM_STR);
            $stmt->execute();
            $data = $stmt->fetch();

            if ($data) {
                return self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar fatura por charge_id: " . $e->getMessage());
        }
        return null;
    }

    public static function findAll(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $invoices = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT i.*, c.corporate_name as client_name 
                    FROM invoices i
                    JOIN clients c ON i.client_id = c.id
                    WHERE 1=1";
            
            $params = [];
            if (!empty($filters['status'])) {
                $sql .= " AND i.status = :status";
                $params[':status'] = $filters['status'];
            }
            if (!empty($filters['client_id'])) {
                $sql .= " AND i.client_id = :client_id";
                $params[':client_id'] = $filters['client_id'];
            }

            $sql .= " ORDER BY i.created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $invoices[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar faturas: " . $e->getMessage());
        }
        return $invoices;
    }

    private static array $allowedFields = [
        'client_id',
        'subscription_id',
        'amount',
        'status',
        'due_date',
        'payment_date',
        'pagarme_order_id',
        'pagarme_charge_id',
        'boleto_url',
        'boleto_barcode',
        'nfe_id',
        'nfe_status',
        'nfe_url',
        'nfe_xml',
        'nfe_pdf'
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
            $sql = "INSERT INTO invoices ({$fields}) VALUES ({$placeholders})";

            $stmt = $pdo->prepare($sql);

            foreach ($filteredData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar fatura: " . $e->getMessage());
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

            $sql = "UPDATE invoices SET " . implode(", ", $fields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            foreach ($filteredData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar fatura ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    private static function hydrate(array $data): Invoice
    {
        $inv = new self();
        $inv->id = (int)$data['id'];
        $inv->client_id = (int)$data['client_id'];
        $inv->subscription_id = isset($data['subscription_id']) ? (int)$data['subscription_id'] : null;
        $inv->amount = (float)$data['amount'];
        $inv->status = $data['status'];
        $inv->due_date = $data['due_date'];
        $inv->payment_date = $data['payment_date'] ?? null;
        $inv->pagarme_order_id = $data['pagarme_order_id'] ?? null;
        $inv->pagarme_charge_id = $data['pagarme_charge_id'] ?? null;
        $inv->boleto_url = $data['boleto_url'] ?? null;
        $inv->boleto_barcode = $data['boleto_barcode'] ?? null;
        $inv->nfe_id = $data['nfe_id'] ?? null;
        $inv->nfe_status = $data['nfe_status'] ?? null;
        $inv->nfe_url = $data['nfe_url'] ?? null;
        $inv->nfe_xml = $data['nfe_xml'] ?? null;
        $inv->nfe_pdf = $data['nfe_pdf'] ?? null;
        $inv->created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
        $inv->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');

        $inv->client_name = $data['client_name'] ?? null;

        return $inv;
    }
}
