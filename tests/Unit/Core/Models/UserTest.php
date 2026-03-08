<?php

namespace Tests\Unit\Core\Models;

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_is_name_only(): void
    {
        $tenant = createTenant();
        $user = User::factory()->create(['name' => 'Claude Agent', 'tenant_id' => $tenant->id]);
        $this->assertEquals('Claude Agent', $user->name);
    }

    public function test_has_many_api_tokens(): void
    {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $token = ApiToken::generateRaw();
        ApiToken::create(['user_id' => $user->id, 'name' => 'test', 'token' => $token['hash'], 'tenant_id' => $tenant->id]);

        $this->assertCount(1, $user->apiTokens);
    }

    public function test_belongs_to_many_projects(): void
    {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'member']);

        $this->assertCount(1, $user->projects);
    }

    // ── Password Validation ──────────────────────────────────────

    public function test_password_is_hashed_on_create(): void
    {
        $tenant = createTenant();
        $rawPassword = 'SecurePassword123';
        $user = User::create(['name' => 'Agent', 'password' => $rawPassword, 'tenant_id' => $tenant->id]);

        $this->assertNotEquals($rawPassword, $user->password);
        $this->assertTrue(Hash::check($rawPassword, $user->password));
    }

    public function test_password_is_hashed_on_update(): void
    {
        $tenant = createTenant();
        $user = User::factory()->create(['password' => 'OldPassword123', 'tenant_id' => $tenant->id]);
        $oldPasswordHash = $user->password;

        $newPassword = 'NewPassword456';
        $user->update(['password' => $newPassword]);

        $this->assertNotEquals($oldPasswordHash, $user->password);
        $this->assertTrue(Hash::check($newPassword, $user->password));
    }

    public function test_cannot_create_user_without_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty.');

        $tenant = createTenant();
        User::create(['name' => 'Agent', 'password' => null, 'tenant_id' => $tenant->id]);
    }

    public function test_cannot_create_user_with_empty_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty.');

        $tenant = createTenant();
        User::create(['name' => 'Agent', 'password' => '', 'tenant_id' => $tenant->id]);
    }

    public function test_cannot_update_user_password_to_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty.');

        $tenant = createTenant();
        $user = User::factory()->create(['password' => 'CurrentPassword123', 'tenant_id' => $tenant->id]);
        $user->update(['password' => '']);
    }

    public function test_cannot_update_user_password_to_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty.');

        $tenant = createTenant();
        $user = User::factory()->create(['password' => 'CurrentPassword123', 'tenant_id' => $tenant->id]);
        $user->update(['password' => null]);
    }

    public function test_password_is_hidden_in_json(): void
    {
        $tenant = createTenant();
        $user = User::factory()->create(['password' => 'SecurePassword123', 'tenant_id' => $tenant->id]);

        $json = $user->toJson();
        $this->assertStringNotContainsString('password', $json);
    }
}
