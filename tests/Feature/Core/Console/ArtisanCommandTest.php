<?php

namespace Tests\Feature\Core\Console;

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtisanCommandTest extends TestCase
{
    use RefreshDatabase;

    // ── user:create ──────────────────────────────────────────

    public function test_user_create_creates_user_and_token(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Username', 'Alice')
            ->expectsQuestion('Password', 'SecurePassword123')
            ->expectsConfirmation('Generate a token now?', 'yes')
            ->expectsQuestion('Token name', 'default')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['name' => 'Alice']);

        $user = User::where('name', 'Alice')->first();
        $this->assertCount(1, $user->apiTokens);
        $this->assertTrue($user->password !== null);
    }

    public function test_user_create_without_token(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Username', 'Bob')
            ->expectsQuestion('Password', 'AnotherPassword456')
            ->expectsConfirmation('Generate a token now?', 'no')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['name' => 'Bob']);

        $user = User::where('name', 'Bob')->first();
        $this->assertCount(0, $user->apiTokens);
        $this->assertTrue($user->password !== null);
    }

    public function test_user_create_stores_hash_not_raw(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Username', 'Carol')
            ->expectsQuestion('Password', 'HashedPassword789')
            ->expectsConfirmation('Generate a token now?', 'yes')
            ->expectsQuestion('Token name', 'test-token')
            ->assertSuccessful();

        $token = ApiToken::first();
        // Raw tokens start with 'aa-', stored hash should not
        $this->assertNotNull($token->token);
        $this->assertStringNotContainsString('aa-', $token->token);
    }

    public function test_user_create_requires_password(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Username', 'NoPwd')
            ->expectsQuestion('Password', '')
            ->assertFailed();
    }

    public function test_user_create_password_too_short(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Username', 'ShortPwd')
            ->expectsQuestion('Password', 'Short1')
            ->assertFailed();
    }

    // ── user:list ────────────────────────────────────────────

    public function test_user_list_shows_all_users(): void
    {
        User::factory()->count(3)->create();

        $this->artisan('user:list')
            ->assertSuccessful();
    }

    // ── user:update ──────────────────────────────────────────

    public function test_user_update_renames_user(): void
    {
        User::factory()->create(['name' => 'OldName', 'password' => 'OldPassword123']);

        $this->artisan('user:update', ['name' => 'OldName', '--name' => 'NewName'])
            ->expectsConfirmation('Update name to "NewName" for "OldName"?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['name' => 'NewName']);
        $this->assertDatabaseMissing('users', ['name' => 'OldName']);
    }

    public function test_user_update_fails_without_option(): void
    {
        User::factory()->create(['name' => 'TestUser', 'password' => 'TestPassword123']);

        $this->artisan('user:update', ['name' => 'TestUser'])
            ->assertFailed();
    }

    public function test_user_update_password(): void
    {
        $user = User::factory()->create(['name' => 'Agent', 'password' => 'OldPassword123']);
        $oldPasswordHash = $user->password;

        $this->artisan('user:update', ['name' => 'Agent', '--password' => 'NewPassword456'])
            ->expectsConfirmation('Update password for "Agent"?', 'yes')
            ->assertSuccessful();

        $user->refresh();
        $this->assertNotEquals($oldPasswordHash, $user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword456', $user->password));
    }

    public function test_user_update_password_and_name(): void
    {
        User::factory()->create(['name' => 'OldAgent', 'password' => 'OldPassword123']);

        $this->artisan('user:update', ['name' => 'OldAgent', '--name' => 'NewAgent', '--password' => 'NewPassword456'])
            ->expectsConfirmation('Update name to "NewAgent" and password for "OldAgent"?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['name' => 'NewAgent']);
        $user = User::where('name', 'NewAgent')->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword456', $user->password));
    }

    public function test_user_update_password_empty_rejected(): void
    {
        User::factory()->create(['name' => 'Agent', 'password' => 'CurrentPassword123']);

        $this->artisan('user:update', ['name' => 'Agent', '--password' => ''])
            ->assertFailed();
    }

    public function test_user_update_password_too_short_rejected(): void
    {
        User::factory()->create(['name' => 'Agent', 'password' => 'CurrentPassword123']);

        $this->artisan('user:update', ['name' => 'Agent', '--password' => 'Short1'])
            ->assertFailed();
    }

    // ── user:delete ──────────────────────────────────────────

    public function test_user_delete_removes_user_and_tokens(): void
    {
        $user = User::factory()->create(['name' => 'Doomed', 'password' => 'DoomedPassword123']);
        $raw = ApiToken::generateRaw();
        $user->apiTokens()->create(['name' => 'tok', 'token' => $raw['hash']]);

        $this->artisan('user:delete', ['name' => 'Doomed'])
            ->expectsConfirmation('Delete this user? This action is irreversible.', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['name' => 'Doomed']);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_user_delete_not_found(): void
    {
        $this->artisan('user:delete', ['name' => 'Ghost'])
            ->assertFailed();
    }

    // ── token:create ─────────────────────────────────────────

    public function test_token_create_permanent(): void
    {
        $user = User::factory()->create(['name' => 'Agent', 'password' => 'AgentPassword123']);

        $this->artisan('token:create', ['user' => 'Agent'])
            ->assertSuccessful();

        $token = ApiToken::first();
        $this->assertNull($token->expires_at);
        $this->assertEquals('default', $token->name);
    }

    public function test_token_create_with_expiry_days(): void
    {
        $user = User::factory()->create(['name' => 'Agent', 'password' => 'AgentPassword123']);

        $this->artisan('token:create', ['user' => 'Agent', '--expires' => '30d', '--name' => 'ci'])
            ->assertSuccessful();

        $token = ApiToken::first();
        $this->assertNotNull($token->expires_at);
        $this->assertEquals('ci', $token->name);
        // Should expire approximately 30 days from now
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(29)));
        $this->assertTrue($token->expires_at->lessThan(now()->addDays(31)));
    }

    public function test_token_create_with_expiry_hours(): void
    {
        $user = User::factory()->create(['name' => 'Agent', 'password' => 'AgentPassword123']);

        $this->artisan('token:create', ['user' => 'Agent', '--expires' => '6h'])
            ->assertSuccessful();

        $token = ApiToken::first();
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addHours(5)));
        $this->assertTrue($token->expires_at->lessThan(now()->addHours(7)));
    }

    public function test_token_create_invalid_expires_format(): void
    {
        $user = User::factory()->create(['name' => 'Agent', 'password' => 'AgentPassword123']);

        $this->artisan('token:create', ['user' => 'Agent', '--expires' => 'invalid'])
            ->assertFailed();

        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_token_create_user_not_found(): void
    {
        $this->artisan('token:create', ['user' => 'Nobody'])
            ->assertFailed();
    }

    // ── token:list ───────────────────────────────────────────

    public function test_token_list_shows_tokens(): void
    {
        $user = User::factory()->create(['name' => 'Agent', 'password' => 'AgentPassword123']);
        $raw1 = ApiToken::generateRaw();
        $raw2 = ApiToken::generateRaw();
        $user->apiTokens()->create(['name' => 'tok1', 'token' => $raw1['hash']]);
        $user->apiTokens()->create(['name' => 'tok2', 'token' => $raw2['hash']]);

        $this->artisan('token:list', ['user' => 'Agent'])
            ->assertSuccessful();
    }

    public function test_token_list_user_not_found(): void
    {
        $this->artisan('token:list', ['user' => 'Ghost'])
            ->assertFailed();
    }

    // ── token:revoke ─────────────────────────────────────────

    public function test_token_revoke_deletes_token(): void
    {
        $user = User::factory()->create(['name' => 'Agent', 'password' => 'AgentPassword123']);
        $raw = ApiToken::generateRaw();
        $token = $user->apiTokens()->create(['name' => 'tok', 'token' => $raw['hash']]);

        $this->artisan('token:revoke', ['token_id' => $token->id])
            ->expectsConfirmation('Revoke this token?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_token_revoke_not_found(): void
    {
        $this->artisan('token:revoke', ['token_id' => '00000000-0000-0000-0000-000000000000'])
            ->assertFailed();
    }

    // ── project:members ──────────────────────────────────────

    public function test_project_members_shows_members(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->artisan('project:members', ['code' => $project->code])
            ->assertSuccessful();
    }

    public function test_project_members_not_found(): void
    {
        $this->artisan('project:members', ['code' => 'NONEXIST'])
            ->assertFailed();
    }

    // ── project:add-member ───────────────────────────────────

    public function test_project_add_member_adds_member(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create(['name' => 'DevBot']);

        $this->artisan('project:add-member', [
            'code' => $project->code,
            'user' => 'DevBot',
        ])->assertSuccessful();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);
    }

    public function test_project_add_member_as_owner(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create(['name' => 'Admin', 'password' => 'AdminPassword123']);

        $this->artisan('project:add-member', [
            'code' => $project->code,
            'user' => 'Admin',
            '--role' => 'owner',
        ])->assertSuccessful();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    public function test_project_add_member_duplicate_rejected(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create(['name' => 'DevBot']);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);

        $this->artisan('project:add-member', [
            'code' => $project->code,
            'user' => 'DevBot',
        ])->assertFailed();
    }

    public function test_project_add_member_invalid_role(): void
    {
        $project = Project::factory()->create();
        User::factory()->create(['name' => 'DevBot', 'password' => 'DevBotPassword123']);

        $this->artisan('project:add-member', [
            'code' => $project->code,
            'user' => 'DevBot',
            '--role' => 'admin',
        ])->assertFailed();
    }

    // ── project:remove-member ────────────────────────────────

    public function test_project_remove_member_removes_member(): void
    {
        $project = Project::factory()->create();
        $owner = User::factory()->create(['name' => 'Owner', 'password' => 'OwnerPassword123']);
        $member = User::factory()->create(['name' => 'Member', 'password' => 'MemberPassword123']);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $this->artisan('project:remove-member', [
            'code' => $project->code,
            'user' => 'Member',
        ])
            ->expectsConfirmation('Remove "Member" from "'.$project->code.'"?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_project_remove_last_owner_rejected(): void
    {
        $project = Project::factory()->create();
        $owner = User::factory()->create(['name' => 'SoleOwner', 'password' => 'SoleOwnerPassword123']);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $this->artisan('project:remove-member', [
            'code' => $project->code,
            'user' => 'SoleOwner',
        ])->assertFailed();
    }

    public function test_project_remove_non_member(): void
    {
        $project = Project::factory()->create();
        User::factory()->create(['name' => 'Stranger', 'password' => 'StrangerPassword123']);

        $this->artisan('project:remove-member', [
            'code' => $project->code,
            'user' => 'Stranger',
        ])->assertFailed();
    }
}
