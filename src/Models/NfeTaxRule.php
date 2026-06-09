<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use PDO;

class NfeTaxRule
{
    public $id;
    public $name;
    public $iss_rate;
    public $pis_rate;
    public $cofins_rate;
    public $inss_rate;
    public $ir_rate;
    public $csll_rate;
    public $cnae;
    public $lc116_code;
    public $city_tax_code;
    public $is_default;
    public $created_at;
    public $updated_at;

    /**
     * Get the default tax rule
     */
    public static function getDefault(): ?NfeTaxRule
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT * FROM nfe_tax_rules WHERE is_default = 1 LIMIT 1");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data) {
            $model = new self();
            foreach ($data as $key => $value) {
                if (property_exists($model, $key)) {
                    $model->$key = $value;
                }
            }
            return $model;
        }
        return null;
    }

    public static function findById(int $id): ?NfeTaxRule
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM nfe_tax_rules WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            $model = new self();
            foreach ($data as $key => $value) {
                if (property_exists($model, $key)) {
                    $model->$key = $value;
                }
            }
            return $model;
        }
        return null;
    }

    public static function findAll(): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT * FROM nfe_tax_rules ORDER BY id DESC");
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $model = new self();
            foreach ($row as $key => $value) {
                if (property_exists($model, $key)) {
                    $model->$key = $value;
                }
            }
            $results[] = $model;
        }

        return $results;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getInstance();
        
        // If this one is set as default, remove default from others
        if (!empty($data['is_default'])) {
            $pdo->exec("UPDATE nfe_tax_rules SET is_default = 0");
        }

        $fields = ['name', 'iss_rate', 'pis_rate', 'cofins_rate', 'inss_rate', 'ir_rate', 'csll_rate', 'cnae', 'lc116_code', 'city_tax_code', 'is_default'];
        
        $insertData = [];
        $placeholders = [];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $insertData[$field] = $data[$field];
                $placeholders[] = ":$field";
            }
        }

        $query = "INSERT INTO nfe_tax_rules (" . implode(', ', array_keys($insertData)) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($query);
        $stmt->execute($insertData);
        
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $pdo = Database::getInstance();

        // If this one is set as default, remove default from others
        if (!empty($data['is_default'])) {
            $pdo->exec("UPDATE nfe_tax_rules SET is_default = 0 WHERE id != $id");
        }

        $fields = ['name', 'iss_rate', 'pis_rate', 'cofins_rate', 'inss_rate', 'ir_rate', 'csll_rate', 'cnae', 'lc116_code', 'city_tax_code', 'is_default'];
        
        $updateData = [];
        $setParts = [];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
                $setParts[] = "$field = :$field";
            }
        }

        if (empty($setParts)) {
            return false;
        }

        $updateData['id'] = $id;
        
        $query = "UPDATE nfe_tax_rules SET " . implode(', ', $setParts) . " WHERE id = :id";
        $stmt = $pdo->prepare($query);
        return $stmt->execute($updateData);
    }
    
    public static function delete(int $id): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM nfe_tax_rules WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
}
