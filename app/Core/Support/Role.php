<?php

declare(strict_types=1);

namespace App\Core\Support;

class Role
{
    public const ADMINISTRATOR = 1;

    public const MANAGER = 2;

    public const DEVELOPER = 3;

    public const VIEWER = 4;

    /**
     * Get the name of a role by its integer value.
     */
    public static function getName(int $role): string
    {
        return config('core.user_roles')[$role] ?? 'Unknown';
    }

    /**
     * Get all roles as a key-value array (name => integer).
     */
    public static function all(): array
    {
        return config('core.user_roles_int', []);
    }

    /**
     * Get all roles as a key-value array (integer => name).
     */
    public static function options(): array
    {
        return config('core.user_roles', []);
    }

    /**
     * Check if a role can perform CRUD operations on artifacts.
     */
    public static function canCrudArtifacts(int $role): bool
    {
        return in_array($role, [self::ADMINISTRATOR, self::MANAGER, self::DEVELOPER], true);
    }

    /**
     * Check if a role can perform CRUD operations on projects.
     */
    public static function canCrudProjects(int $role): bool
    {
        return in_array($role, [self::ADMINISTRATOR, self::MANAGER], true);
    }

    /**
     * Check if a role can manage tokens.
     */
    public static function canManageTokens(int $role): bool
    {
        return in_array($role, [self::ADMINISTRATOR, self::MANAGER], true);
    }

    /**
     * Check if a role can manage users.
     */
    public static function canManageUsers(int $role): bool
    {
        return $role === self::ADMINISTRATOR;
    }

    /**
     * Check if a role has read-only access.
     */
    public static function isViewer(int $role): bool
    {
        return $role === self::VIEWER;
    }

    /**
     * Check if a role is at least a developer.
     */
    public static function isDeveloperOrAbove(int $role): bool
    {
        return in_array($role, [self::ADMINISTRATOR, self::MANAGER, self::DEVELOPER], true);
    }

    /**
     * Check if a role is at least a manager.
     */
    public static function isManagerOrAbove(int $role): bool
    {
        return in_array($role, [self::ADMINISTRATOR, self::MANAGER], true);
    }

    /**
     * Check if a role is administrator.
     */
    public static function isAdministrator(int $role): bool
    {
        return $role === self::ADMINISTRATOR;
    }

    /**
     * Validate if a role value is valid.
     */
    public static function isValid(int $role): bool
    {
        return in_array($role, [self::ADMINISTRATOR, self::MANAGER, self::DEVELOPER, self::VIEWER], true);
    }
}
