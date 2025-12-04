<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\User;

/**
 * Permission Service
 * Manages user permissions and access control
 */
class PermissionService
{
    /**
     * Default permission templates for each role
     */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            'usuarios' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            'leads' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'assign' => true],
            'proposals' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'approve' => true],
            'whatsapp' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            'configuracoes' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            'relatorios' => ['view' => true, 'export' => true],
            'kanban' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'assign' => true],
            'dashboard' => ['view' => true],
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

    /**
     * Check if user has permission for a specific action on a resource
     *
     * @param object $user User object with permissions
     * @param string $resource Resource name (e.g., 'leads', 'users')
     * @param string $action Action name (e.g., 'view', 'create', 'edit', 'delete')
     * @param int|null $resourceOwnerId Owner ID of the resource (for ownership checks)
     * @return bool
     */
    public function can(object $user, string $resource, string $action, ?int $resourceOwnerId = null): bool
    {
        // Check if user has permissions property
        if (!property_exists($user, 'permissions')) {
            // If no permissions property, use role defaults
            $permissions = $this->getDefaultPermissions($user->role ?? 'comercial');
        } else {
            // Parse permissions from JSON if string
            $permissions = is_string($user->permissions)
                ? json_decode($user->permissions, true)
                : $user->permissions;

            // If no permissions set, use role defaults
            if (empty($permissions)) {
                $permissions = $this->getDefaultPermissions($user->role ?? 'comercial');
            }
        }

        // Check if resource exists in permissions
        if (!isset($permissions[$resource])) {
            return false;
        }

        // Check if action exists for resource
        if (!isset($permissions[$resource][$action])) {
            return false;
        }

        $permission = $permissions[$resource][$action];

        // Boolean permission (true/false)
        if (is_bool($permission)) {
            return $permission;
        }

        // Ownership-based permission ('own')
        if ($permission === 'own') {
            // If no owner specified, deny (can't verify ownership)
            if ($resourceOwnerId === null) {
                return false;
            }
            // Check if user is the owner
            return $user->id === $resourceOwnerId;
        }

        // Team-based permission ('team') - future implementation
        if ($permission === 'team') {
            // TODO: Implement team-based permission checks
            return false;
        }

        // Default deny
        return false;
    }

    /**
     * Get default permissions for a role
     *
     * @param string $role
     * @return array
     */
    public function getDefaultPermissions(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? self::ROLE_PERMISSIONS['comercial'];
    }

    /**
     * Get all permissions for a user
     *
     * @param object $user
     * @return array
     */
    public function getUserPermissions(object $user): array
    {
        // Check if user has permissions property
        if (!property_exists($user, 'permissions')) {
            return $this->getDefaultPermissions($user->role ?? 'comercial');
        }

        $permissions = is_string($user->permissions)
            ? json_decode($user->permissions, true)
            : $user->permissions;

        return $permissions ?? $this->getDefaultPermissions($user->role ?? 'comercial');
    }

    /**
     * Set default permissions for a user based on their role
     *
     * @param string $role
     * @return string JSON encoded permissions
     */
    public function setDefaultPermissions(string $role): string
    {
        $permissions = $this->getDefaultPermissions($role);
        return json_encode($permissions);
    }

    /**
     * Check if user is admin (has full access)
     *
     * @param object $user
     * @return bool
     */
    public function isAdmin(object $user): bool
    {
        return strtolower($user->role ?? '') === 'admin';
    }

    /**
     * Get human-readable permission description
     *
     * @param mixed $permission Permission value
     * @return string
     */
    public function getPermissionDescription($permission): string
    {
        if ($permission === true) {
            return 'Acesso total';
        }
        if ($permission === false) {
            return 'Sem acesso';
        }
        if ($permission === 'own') {
            return 'Apenas próprios';
        }
        if ($permission === 'team') {
            return 'Equipe';
        }
        return 'Não definido';
    }
}
