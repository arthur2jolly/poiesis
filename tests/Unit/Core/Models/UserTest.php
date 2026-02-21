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
        $user = User::factory()->create(['name' => 'Claude Agent']);
        $this->assertEquals('Claude Agent', $user->name);
    }

    public function test_has_many_api_tokens(): void
    {
        $user = User::factory()->create();
        $token = ApiToken::generateRaw();
        ApiToken::create(['user_id' => $user->id, 'name' => 'test', 'token' => $token['hash']]);

        $this->assertCount(1, $user->apiTokens);
    }

    public function test_belongs_to_many_projects(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'role' => 'member']);

        $this->assertCount(1, $user->projects);
    }

    // ── Password Validation ──────────────────────────────────────

    public function test_password_is_hashed_on_create(): void
    {
        $rawPassword = 'SecurePassword123';
        $user = User::create(['name' => 'Agent', 'password' => $rawPassword]);

        $this->assertNotEquals($rawPassword, $user->password);
        $this->assertTrue(Hash::check($rawPassword, $user->password));
    }

    public function test_password_is_hashed_on_update(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
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

        User::create(['name' => 'Agent', 'password' => null]);
    }

    public function test_cannot_create_user_with_empty_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty.');

        User::create(['name' => 'Agent', 'password' => '']);
    }

    public function test_cannot_update_user_password_to_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty.');

        $user = User::factory()->create(['password' => 'CurrentPassword123']);
        $user->update(['password' => '']);
    }

    public function test_cannot_update_user_password_to_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty.');

        $user = User::factory()->create(['password' => 'CurrentPassword123']);
        $user->update(['password' => null]);
    }

    public function test_password_is_hidden_in_json(): void
    {
        $user = User::factory()->create(['password' => 'SecurePassword123']);

        $json = $user->toJson();
        $this->assertStringNotContainsString('password', $json);
    }
}
