<?php
require __DIR__ . '/../src/Models/Database.php';
try {
    $pdo = \Apoio19\Crm\Models\Database::getInstance();
    $pdo->exec('ALTER TABLE nfe_tax_rules ADD COLUMN use_portal_nacional TINYINT(1) DEFAULT 0');
    echo 'Column added successfully.';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
