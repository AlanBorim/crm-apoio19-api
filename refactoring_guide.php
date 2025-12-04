<?php

/**
 * Script para refatorar controllers e substituir checks hardcoded por PermissionService
 * 
 * Este script documenta as mudanças necessárias em cada controller
 */

echo "=== REFATORAÇÃO DE CONTROLLERS - SISTEMA DE PERMISSÕES ===" . PHP_EOL . PHP_EOL;

$changes = [
    'LeadController.php' => [
        'line_80' => [
            'old' => 'if ($userData->role !== "admin") {',
            'new' => 'if (!$this->can($userData, "leads", "view")) {',
            'context' => 'index() - Verificar permissão de visualização de leads'
        ],
        'line_183' => [
            'old' => 'if ($userData->role !== "admin" && $lead->responsavel_id !== $userData->userId) {',
            'new' => 'if (!$this->can($userData, "leads", "view", $lead->responsavel_id)) {',
            'context' => 'show() - Verificar permissão de visualização com ownership'
        ],
        'line_226' => [
            'old' => 'if ($userData->role !== "admin" && $lead->responsavel_id !== $userData->userId) {',
            'new' => 'if (!$this->can($userData, "leads", "edit", $lead->responsavel_id)) {',
            'context' => 'update() - Verificar permissão de edição com ownership'
        ],
        'line_425' => [
            'old' => 'if ($userData->role !== "Admin" && $lead->responsavel_id !== $userData->userId) {',
            'new' => 'if (!$this->can($userData, "leads", "edit", $lead->responsavel_id)) {',
            'context' => 'batchUpdateStatus() - Verificar permissão de edição com ownership'
        ],
        'constructor' => [
            'add' => 'parent::__construct();',
            'context' => '__construct() - Chamar construtor do BaseController para inicializar PermissionService'
        ]
    ],

    'TarefaUsuarioController.php' => [
        'line_33' => [
            'old' => 'if ($userData->role === \'admin\') {',
            'new' => 'if ($this->can($userData, "tasks", "view")) {',
            'context' => 'index() - Admin pode ver todas as tarefas'
        ],
        'line_60' => [
            'old' => 'if ($userData->role !== \'admin\' || empty($data[\'usuario_id\'])) {',
            'new' => 'if (!$this->can($userData, "tasks", "create")) {',
            'context' => 'create() - Verificar permissão de criação'
        ],
        'line_87' => [
            'old' => 'if ($userData->role !== \'admin\' && $tarefa->usuario_id != $userData->userId) {',
            'new' => 'if (!$this->can($userData, "tasks", "view", $tarefa->usuario_id)) {',
            'context' => 'show() - Verificar ownership'
        ],
        'line_113' => [
            'old' => 'if ($userData->role !== \'admin\' && $tarefa->usuario_id != $userData->userId) {',
            'new' => 'if (!$this->can($userData, "tasks", "edit", $tarefa->usuario_id)) {',
            'context' => 'update() - Verificar permissão de edição'
        ],
        'line_139' => [
            'old' => 'if ($userData->role !== \'admin\' && $tarefa->usuario_id != $userData->userId) {',
            'new' => 'if (!$this->can($userData, "tasks", "delete", $tarefa->usuario_id)) {',
            'context' => 'delete() - Verificar permissão de exclusão'
        ]
    ],

    'WhatsappCampaignController.php' => [
        'multiple_lines' => [
            'old_pattern' => '$userData->funcao !== \'admin\'',
            'new_pattern' => '!$this->can($userData, "campaigns", "{action}", $campaign[\'user_id\'] ?? null)',
            'occurrences' => 6,
            'context' => 'Verificar permissões de campanha com ownership'
        ]
    ],

    'UserController.php' => [
        'role_validation' => [
            'old' => 'Validação hardcoded de roles',
            'new' => 'Usar PermissionService para validar permissões',
            'context' => 'Múltiplas validações de role precisam ser refatoradas'
        ]
    ]
];

foreach ($changes as $file => $fileChanges) {
    echo "📄 {$file}" . PHP_EOL;
    echo str_repeat("-", 50) . PHP_EOL;

    foreach ($fileChanges as $location => $change) {
        echo "  📍 {$location}" . PHP_EOL;
        if (isset($change['old'])) {
            echo "    ❌ Antigo: {$change['old']}" . PHP_EOL;
        }
        if (isset($change['new'])) {
            echo "    ✅ Novo: {$change['new']}" . PHP_EOL;
        }
        if (isset($change['context'])) {
            echo "    💡 Contexto: {$change['context']}" . PHP_EOL;
        }
        if (isset($change['add'])) {
            echo "    ➕ Adicionar: {$change['add']}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
    echo PHP_EOL;
}

echo "=== RESUMO ===" . PHP_EOL;
echo "Total de controllers a refatorar: " . count($changes) . PHP_EOL;
echo "Controllers: " . implode(", ", array_keys($changes)) . PHP_EOL;

echo PHP_EOL . "✨ Documento de refatoração gerado com sucesso!" . PHP_EOL;
