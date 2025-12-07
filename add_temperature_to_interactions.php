<?php

require_once __DIR__ . '/vendor/autoload.php';

use Apoio19\Crm\Models\Database;

try {
    // Load .env
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $db = Database::getInstance();

    echo "Adding temperature column to historico_interacoes...\n";

    $sql = "ALTER TABLE historico_interacoes ADD COLUMN temperature ENUM('frio', 'morno', 'quente') DEFAULT NULL AFTER observacao";

    $db->exec($sql);

    echo "Column 'temperature' added successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Column 'temperature' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
