<?php

/**
 * Migration Script: Populate User Permissions
 * 
 * This script updates all existing users with default permissions
 * based on their current role.
 */

// Direct database connection
$pdo = new PDO(
    'mysql:host=69.62.90.56;port=3306;dbname=crm;charset=utf8mb4',
    'crmuser',
    'CRM@19user',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Default permission templates for each role
$rolePermissions = [
    'admin' => [
        'users' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'leads' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'proposals' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'tasks' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'campaigns' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'dashboard' => ['view' => true],
        'reports' => ['view' => true, 'export' => true],
    ],
    'gerente' => [
        'users' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'leads' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'proposals' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'tasks' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'campaigns' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'dashboard' => ['view' => true],
        'reports' => ['view' => true, 'export' => true],
    ],
    'vendedor' => [
        'users' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'leads' => ['view' => true, 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'proposals' => ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'tasks' => ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'campaigns' => ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'dashboard' => ['view' => true],
        'reports' => ['view' => false, 'export' => false],
    ],
    'comercial' => [
        'users' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'leads' => ['view' => true, 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'proposals' => ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'tasks' => ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'campaigns' => ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'dashboard' => ['view' => true],
        'reports' => ['view' => false, 'export' => false],
    ],
    'suporte' => [
        'users' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'leads' => ['view' => true, 'create' => false, 'edit' => true, 'delete' => false],
        'proposals' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'tasks' => ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'campaigns' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'dashboard' => ['view' => true],
        'reports' => ['view' => false, 'export' => false],
    ],
    'financeiro' => [
        'users' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'leads' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'proposals' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'tasks' => ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'own'],
        'campaigns' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'dashboard' => ['view' => true],
        'reports' => ['view' => true, 'export' => true],
    ],
];

echo "=== MIGRATION: Populate User Permissions ===" . PHP_EOL . PHP_EOL;

try {
    $pdo->beginTransaction();

    // Get all users
    $stmt = $pdo->query("SELECT id, name, role FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($users) . " users to update." . PHP_EOL . PHP_EOL;

    $updateStmt = $pdo->prepare("UPDATE users SET permissions = :permissions WHERE id = :id");

    $updated = 0;
    foreach ($users as $user) {
        $userRole = $user['role'];

        // Get permissions for role, fallback to comercial if role not found
        $permissions = $rolePermissions[$userRole] ?? $rolePermissions['comercial'];
        $permissionsJson = json_encode($permissions);

        $updateStmt->execute([
            ':permissions' => $permissionsJson,
            ':id' => $user['id']
        ]);

        echo sprintf(
            "✓ Updated user #%d (%s) - Role: %s" . PHP_EOL,
            $user['id'],
            $user['name'],
            $userRole
        );
        $updated++;
    }

    $pdo->commit();

    echo PHP_EOL . "=== MIGRATION COMPLETED SUCCESSFULLY ===" . PHP_EOL;
    echo "Updated " . $updated . " users with default permissions." . PHP_EOL;
} catch (Exception $e) {
    $pdo->rollBack();
    echo PHP_EOL . "✗ MIGRATION FAILED: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
