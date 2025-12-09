<?php

namespace Apoio19\Crm\Models;

use PDO;
use PDOException;
use Apoio19\Crm\Models\Database;

class Whatsapp
{
    public static function getConfig()
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query('SELECT * FROM whatsapp_phone_numbers WHERE status = "active" LIMIT 1');
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                return null;
            }

            return $config;
        } catch (PDOException $e) {
            error_log("Get config error: " . $e->getMessage());
            return null;
        }
    }
}
