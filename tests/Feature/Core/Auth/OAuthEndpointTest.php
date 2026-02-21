<?php

namespace Tests\Feature\Core\Auth;

use App\Core\Models\OAuthAccessToken;
use App\Core\Models\OAuthClient;
use App\Core\Models\OAuthRefreshToken;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthEndpointTest extends TestCase
{
    use RefreshDatabase;

    // -- Metadata endpoint --

    public function test_well_known_metadata_returns_correct_structure(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'registration_endpoint',
            'revocation_endpoint',
            'scopes_supported',
            'response_types_supported',
            'grant_types_supported',
            'code_challenge_methods_supported',
        ]);
        $response->assertJsonFragment(['response_types_supported' => ['code']]);
        $response->assertJsonFragment(['code_challenge_methods_supported' => ['S256']]);
    }

    // -- Client registration --

    public function test_register_client_creates_public_client(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test App',
            'redirect_uris' => ['http://localhost/callback'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['client_id', 'client_name', 'redirect_uris', 'grant_types']);
        $this->assertEquals('Test App', $response->json('client_name'));
        $this->assertDatabaseHas('oauth_clients', ['name' => 'Test App']);
    }

    public function test_register_client_requires_redirect_uris(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test App',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_client_requires_valid_urls(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test App',
            'redirect_uris' => ['not-a-url'],
        ]);

        $response->assertStatus(422);
    }

    // -- Token revocation --

    public function test_revoke_access_token_deletes_it(): void
    {
        $user = User::factory()->create();
        $client = OAuthClient::create([
            'name' => 'Revoke Test',
            'client_id' => 'revoke-client',
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code'],
        ]);
        $rawToken = 'aa-'.bin2hex(random_bytes(20));
        $accessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'token' => hash('sha256', $rawToken),
            'scopes' => ['projects:read'],
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $response = $this->postJson('/oauth/revoke', ['token' => $rawToken]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $accessToken->id]);
    }

    public function test_revoke_unknown_token_returns_200(): void
    {
        $response = $this->postJson('/oauth/revoke', ['token' => 'unknown-token']);

        $response->assertStatus(200);
    }

    public function test_revoke_refresh_token_marks_as_revoked(): void
    {
        $user = User::factory()->create();
        $client = OAuthClient::create([
            'name' => 'Revoke RT Test',
            'client_id' => 'revoke-rt-client',
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code'],
        ]);
        $accessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'token' => hash('sha256', 'some-access-token'),
            'scopes' => ['projects:read'],
            'expires_at' => Carbon::now()->addHour(),
        ]);
        $rawRefresh = 'rt-'.bin2hex(random_bytes(20));
        OAuthRefreshToken::create([
            'access_token_id' => $accessToken->id,
            'token' => hash('sha256', $rawRefresh),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $response = $this->postJson('/oauth/revoke', ['token' => $rawRefresh]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'token' => hash('sha256', $rawRefresh),
            'revoked' => true,
        ]);
    }

    // -- Token refresh --

    public function test_refresh_token_issues_new_tokens(): void
    {
        $user = User::factory()->create();
        $client = OAuthClient::create([
            'name' => 'Refresh Test',
            'client_id' => 'refresh-client',
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code'],
        ]);
        $accessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'token' => hash('sha256', 'old-access-token'),
            'scopes' => ['projects:read'],
            'expires_at' => Carbon::now()->addHour(),
        ]);
        $rawRefresh = 'rt-'.bin2hex(random_bytes(20));
        OAuthRefreshToken::create([
            'access_token_id' => $accessToken->id,
            'token' => hash('sha256', $rawRefresh),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'refresh-client',
            'refresh_token' => $rawRefresh,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token']);
    }

    public function test_expired_refresh_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $client = OAuthClient::create([
            'name' => 'Expired RT Test',
            'client_id' => 'expired-rt-client',
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code'],
        ]);
        $accessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'token' => hash('sha256', 'some-at'),
            'scopes' => null,
            'expires_at' => Carbon::now()->addHour(),
        ]);
        $rawRefresh = 'rt-'.bin2hex(random_bytes(20));
        OAuthRefreshToken::create([
            'access_token_id' => $accessToken->id,
            'token' => hash('sha256', $rawRefresh),
            'expires_at' => Carbon::now()->subHour(),
        ]);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'expired-rt-client',
            'refresh_token' => $rawRefresh,
        ]);

        $response->assertStatus(400);
    }
}
