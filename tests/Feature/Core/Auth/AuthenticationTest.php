<?php

namespace Tests\Feature\Core\Auth;

use App\Core\Models\ApiToken;
use App\Core\Models\OAuthAccessToken;
use App\Core\Models\OAuthClient;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithToken(?Carbon $expiresAt = null): array
    {
        $user = User::factory()->create();
        $raw = ApiToken::generateRaw();
        $token = $user->apiTokens()->create([
            'name' => 'test-token',
            'token' => $raw['hash'],
            'expires_at' => $expiresAt,
        ]);

        return ['user' => $user, 'raw' => $raw['raw'], 'token' => $token];
    }

    private function createUserWithOAuthToken(?Carbon $expiresAt = null): array
    {
        $user = User::factory()->create();
        $client = OAuthClient::create([
            'name' => 'Test Client',
            'client_id' => 'test-client-id',
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code'],
        ]);
        $raw = 'aa-'.bin2hex(random_bytes(20));
        $accessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'token' => hash('sha256', $raw),
            'scopes' => ['projects:read'],
            'expires_at' => $expiresAt ?? Carbon::now()->addHour(),
        ]);

        return ['user' => $user, 'raw' => $raw, 'accessToken' => $accessToken];
    }

    // -- AuthenticateBearer tests --

    public function test_valid_static_token_authenticates_successfully(): void
    {
        $data = $this->createUserWithToken();

        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        // The route doesn't exist yet, but middleware should pass (not 401)
        $this->assertNotEquals(401, $response->status());
    }

    public function test_expired_static_token_returns_401(): void
    {
        $data = $this->createUserWithToken(Carbon::now()->subHour());

        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(401);
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer invalid-token-value',
        ]);

        $response->assertStatus(401);
    }

    public function test_valid_oauth_access_token_authenticates(): void
    {
        $data = $this->createUserWithOAuthToken();

        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $this->assertNotEquals(401, $response->status());
    }

    public function test_expired_oauth_access_token_returns_401(): void
    {
        $data = $this->createUserWithOAuthToken(Carbon::now()->subHour());

        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(401);
    }

    // -- EnsureProjectAccess tests --

    public function test_project_access_with_membership_passes(): void
    {
        $data = $this->createUserWithToken();
        $project = Project::factory()->create();
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $data['user']->id, 'role' => 'member']);

        $response = $this->getJson("/api/v1/projects/{$project->code}/access-check", [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $this->assertNotEquals(403, $response->status());
    }

    public function test_project_access_without_membership_returns_403(): void
    {
        $data = $this->createUserWithToken();
        $project = Project::factory()->create();

        $response = $this->getJson("/api/v1/projects/{$project->code}/access-check", [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(403);
    }

    // -- EnsureModuleActive tests --

    public function test_module_active_passes_when_module_is_active(): void
    {
        $data = $this->createUserWithToken();
        $project = Project::factory()->create(['modules' => ['sprint']]);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $data['user']->id, 'role' => 'member']);

        $response = $this->getJson("/api/v1/projects/{$project->code}/module-check/sprint", [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['module' => 'sprint']);
    }

    public function test_module_active_fails_when_module_is_inactive(): void
    {
        $data = $this->createUserWithToken();
        $project = Project::factory()->create(['modules' => []]);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $data['user']->id, 'role' => 'member']);

        $response = $this->getJson("/api/v1/projects/{$project->code}/module-check/sprint", [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => "Module 'sprint' is not active for this project."]);
    }
}
