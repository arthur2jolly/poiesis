<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Support\Role;
use Illuminate\Validation\ValidationException;

class MemberTools implements McpToolInterface
{
    /** @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function tools(): array
    {
        return [
            [
                'name' => 'list_users',
                'description' => 'List platform users (manager or above)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page' => ['type' => 'integer', 'description' => 'Page number'],
                        'per_page' => ['type' => 'integer', 'description' => 'Items per page (max 100)'],
                    ],
                ],
            ],
            [
                'name' => 'create_user',
                'description' => 'Create a new platform user (administrator only)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Username'],
                        'password' => ['type' => 'string', 'description' => 'Password (min 8 characters)'],
                        'role' => ['type' => 'string', 'enum' => ['administrator', 'manager', 'developer', 'viewer'], 'description' => 'Global role (default: viewer)'],
                    ],
                    'required' => ['name', 'password'],
                ],
            ],
            [
                'name' => 'update_user',
                'description' => 'Update a user\'s password (administrator or manager only)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'string', 'description' => 'User ID'],
                        'password' => ['type' => 'string', 'description' => 'New password (min 8 characters)'],
                    ],
                    'required' => ['user_id', 'password'],
                ],
            ],
            [
                'name' => 'list_members',
                'description' => 'List members of a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_code' => ['type' => 'string'],
                    ],
                    'required' => ['project_code'],
                ],
            ],
            [
                'name' => 'add_member',
                'description' => 'Add a user to a project (project owner only)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_code' => ['type' => 'string'],
                        'user_id' => ['type' => 'string', 'description' => 'User ID'],
                        'position' => ['type' => 'string', 'enum' => ['owner', 'member'], 'description' => 'Project position (default: member)'],
                        'policy' => ['type' => 'string', 'enum' => ['administrator', 'manager', 'developer', 'viewer'], 'description' => 'Global permission level (optional, updates the user globally)'],
                    ],
                    'required' => ['project_code', 'user_id'],
                ],
            ],
            [
                'name' => 'update_member',
                'description' => 'Update a project member\'s role or global policy (project owner only)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_code' => ['type' => 'string'],
                        'user_id' => ['type' => 'string'],
                        'position' => ['type' => 'string', 'enum' => ['owner', 'member'], 'description' => 'Project position'],
                        'policy' => ['type' => 'string', 'enum' => ['administrator', 'manager', 'developer', 'viewer'], 'description' => 'Global permission level'],
                    ],
                    'required' => ['project_code', 'user_id'],
                ],
            ],
            [
                'name' => 'remove_member',
                'description' => 'Remove a user from a project (project owner only)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_code' => ['type' => 'string'],
                        'user_id' => ['type' => 'string'],
                    ],
                    'required' => ['project_code', 'user_id'],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $params */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'list_users' => $this->listUsers($params, $user),
            'create_user' => $this->createUser($params, $user),
            'update_user' => $this->updateUser($params, $user),
            'list_members' => $this->listMembers($params, $user),
            'add_member' => $this->addMember($params, $user),
            'update_member' => $this->updateMember($params, $user),
            'remove_member' => $this->removeMember($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listUsers(array $params, User $user): array
    {
        if (! Role::isManagerOrAbove($user->role)) {
            throw ValidationException::withMessages([
                'user' => ['You do not have permission to list users.'],
            ]);
        }

        $perPage = min((int) ($params['per_page'] ?? 25), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $paginator = User::orderBy('name')->paginate($perPage, ['*'], 'page', $page);

        $policies = array_change_key_case(array_flip(config('core.user_roles_int')), CASE_LOWER);

        return [
            'data' => $paginator->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'policy' => strtolower($policies[$u->role] ?? 'viewer'),
            ])->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createUser(array $params, User $user): array
    {
        if (! Role::canManageUsers($user->role)) {
            throw ValidationException::withMessages([
                'user' => ['You do not have permission to create users.'],
            ]);
        }

        $name = trim($params['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Name is required.']]);
        }

        if (User::where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => ['A user with this name already exists.']]);
        }

        $password = $params['password'];
        if (strlen($password) < 8) {
            throw ValidationException::withMessages(['password' => ['Password must be at least 8 characters.']]);
        }

        $role = isset($params['role']) ? $this->resolvePolicy($params['role']) : Role::VIEWER;

        $newUser = User::create([
            'tenant_id' => $user->tenant_id,
            'name' => $name,
            'password' => $password,
            'role' => $role,
        ]);

        return [
            'id' => $newUser->id,
            'name' => $newUser->name,
            'role' => strtolower(Role::getName($newUser->role)),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function updateUser(array $params, User $user): array
    {
        if (! Role::isManagerOrAbove($user->role)) {
            throw ValidationException::withMessages([
                'user' => ['You do not have permission to update users.'],
            ]);
        }

        /** @var User $target */
        $target = User::findOrFail($params['user_id']);

        $password = $params['password'];
        if (strlen($password) < 8) {
            throw ValidationException::withMessages(['password' => ['Password must be at least 8 characters.']]);
        }

        $target->password = $password;
        $target->save();

        return [
            'id' => $target->id,
            'name' => $target->name,
            'message' => 'Password updated successfully.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listMembers(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);

        $members = ProjectMember::with('user:id,name,role')
            ->where('project_id', $project->id)
            ->get();

        $policies = array_change_key_case(array_flip(config('core.user_roles_int')), CASE_LOWER);

        return [
            'data' => $members->map(fn (ProjectMember $m) => [
                'user_id' => $m->user->id,
                'name' => $m->user->name,
                'position' => $m->position,
                'policy' => strtolower($policies[$m->user->role] ?? 'viewer'),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function addMember(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $this->requireOwner($project, $user);

        /** @var User $target */
        $target = User::findOrFail($params['user_id']);

        if (ProjectMember::where('project_id', $project->id)->where('user_id', $target->id)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => ['User is already a member of this project.'],
            ]);
        }

        $position = $params['position'] ?? 'member';
        if (! in_array($position, config('core.project_positions'), true)) {
            throw ValidationException::withMessages(['position' => ['Invalid position.']]);
        }

        if (isset($params['policy'])) {
            $target->role = $this->resolvePolicy($params['policy']);
            $target->save();
        }

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $target->id,
            'position' => $position,
        ]);

        $policies = array_change_key_case(array_flip(config('core.user_roles_int')), CASE_LOWER);

        return [
            'user_id' => $target->id,
            'name' => $target->name,
            'position' => $position,
            'policy' => strtolower($policies[$target->role] ?? 'viewer'),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function updateMember(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $this->requireOwner($project, $user);

        /** @var User $target */
        $target = User::findOrFail($params['user_id']);

        $membership = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $target->id)
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'user_id' => ['User is not a member of this project.'],
            ]);
        }

        if (isset($params['position'])) {
            if (! in_array($params['position'], config('core.project_positions'), true)) {
                throw ValidationException::withMessages(['position' => ['Invalid position.']]);
            }

            if ($params['position'] !== 'owner' && ProjectMember::isLastOwner($project->id, $target->id)) {
                throw ValidationException::withMessages([
                    'position' => ['Cannot downgrade the last owner of the project.'],
                ]);
            }

            $membership->position = $params['position'];
            $membership->save();
        }

        if (isset($params['policy'])) {
            $target->role = $this->resolvePolicy($params['policy']);
            $target->save();
        }

        $policies = array_change_key_case(array_flip(config('core.user_roles_int')), CASE_LOWER);

        return [
            'user_id' => $target->id,
            'name' => $target->name,
            'position' => $membership->position,
            'policy' => strtolower($policies[$target->role] ?? 'viewer'),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function removeMember(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $this->requireOwner($project, $user);

        /** @var User $target */
        $target = User::findOrFail($params['user_id']);

        $membership = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $target->id)
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'user_id' => ['User is not a member of this project.'],
            ]);
        }

        if (ProjectMember::isLastOwner($project->id, $target->id)) {
            throw ValidationException::withMessages([
                'user_id' => ['Cannot remove the last owner of the project.'],
            ]);
        }

        $membership->delete();

        return ['message' => "User {$target->name} removed from project {$project->code}."];
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        $isMember = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }

        return $project;
    }

    private function requireOwner(Project $project, User $user): void
    {
        $isOwner = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('position', 'owner')
            ->exists();

        if (! $isOwner) {
            throw ValidationException::withMessages([
                'project' => ['Only a project owner can manage members.'],
            ]);
        }
    }

    private function resolvePolicy(string $policy): int
    {
        $validPolicies = array_change_key_case(config('core.user_roles_int'), CASE_LOWER);
        $roleInt = $validPolicies[strtolower($policy)] ?? null;

        if ($roleInt === null) {
            throw ValidationException::withMessages([
                'policy' => ['Invalid policy. Valid values: '.implode(', ', array_keys($validPolicies))],
            ]);
        }

        return $roleInt;
    }
}
