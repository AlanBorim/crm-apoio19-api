<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class ScheduledPayment
{
    public int $id;
    public int $client_id;
    public float $amount;
    public ?string $description;
    public string $schedule_date;
    public string $due_date;
    public int $send_email;
    public string $status;
    public string $created_at;
    public string $updated_at;
    public ?string $item_type = null;
    public ?string $boleto_url = null;

    // Relational data
    public ?string $client_name = null;
    public ?string $client_document = null;

    private static array $allowedFields = [
        'client_id',
        'amount',
        'description',
        'schedule_date',
        'due_date',
        'send_email',
        'status'
    ];

    public static function findById(int $id): ?ScheduledPayment
    {
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT sp.*, c.corporate_name as client_name, c.document as client_document
                    FROM scheduled_payments sp
                    JOIN clients c ON sp.client_id = c.id
                    WHERE sp.id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch();

            if ($data) {
                return self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar agendamento por ID: " . $e->getMessage());
        }
        return null;
    }

    public static function findByClientId(int $clientId): array
    {
        $payments = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT * FROM scheduled_payments WHERE client_id = :client_id ORDER BY schedule_date ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":client_id", $clientId, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $payments[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar agendamentos por Client ID: " . $e->getMessage());
        }
        return $payments;
    }

    public static function findAll(int $limit = 50, int $offset = 0): array
    {
        $payments = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT sp.*, c.corporate_name as client_name, c.document as client_document 
                    FROM scheduled_payments sp
                    JOIN clients c ON sp.client_id = c.id
                    ORDER BY sp.created_at DESC 
                    LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $payments[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar todos os agendamentos: " . $e->getMessage());
        }
        return $payments;
    }

    public static function getPendingPaymentsUntilToday(): array
    {
        $payments = [];
        try {
            $pdo = Database::getInstance();
            $sql = "SELECT sp.*, c.corporate_name as client_name, c.document as client_document 
                    FROM scheduled_payments sp
                    JOIN clients c ON sp.client_id = c.id
                    WHERE sp.status = 'pending' AND sp.schedule_date <= CURRENT_DATE()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $data) {
                $payments[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar pagamentos pendentes até hoje: " . $e->getMessage());
        }
        return $payments;
    }

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
            $sql = "INSERT INTO scheduled_payments ({$fields}) VALUES ({$placeholders})";

            $stmt = $pdo->prepare($sql);

            foreach ($filteredData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            if ($stmt->execute()) {
                return (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erro ao criar agendamento: " . $e->getMessage());
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

            $sql = "UPDATE scheduled_payments SET " . implode(", ", $fields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            foreach ($filteredData as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar agendamento ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    private static function hydrate(array $data): ScheduledPayment
    {
        $sp = new self();
        $sp->id = (int)$data['id'];
        $sp->client_id = (int)$data['client_id'];
        $sp->amount = (float)$data['amount'];
        $sp->description = $data['description'] ?? null;
        $sp->schedule_date = $data['schedule_date'];
        $sp->due_date = $data['due_date'];
        $sp->send_email = (int)$data['send_email'];
        $sp->status = $data['status'];
        $sp->created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
        $sp->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');

        $sp->client_name = $data['client_name'] ?? null;
        $sp->client_document = $data['client_document'] ?? null;

        return $sp;
    }
}
