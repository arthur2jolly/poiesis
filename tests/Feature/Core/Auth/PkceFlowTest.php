<?php

namespace Tests\Feature\Core\Auth;

use App\Core\Models\OAuthAuthorizationCode;
use App\Core\Models\OAuthClient;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PkceFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    private function createClientAndUser(): array
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $client = OAuthClient::create([
            'name' => 'PKCE Test Client',
            'client_id' => 'pkce-client-id',
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code'],
            'tenant_id' => $this->tenant->id,
        ]);

        return ['user' => $user, 'client' => $client];
    }

    private function generatePkce(): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    private function createAuthorizationCode(OAuthClient $client, User $user, string $challenge, ?Carbon $expiresAt = null): array
    {
        $rawCode = bin2hex(random_bytes(32));
        $hashedCode = hash('sha256', $rawCode);

        OAuthAuthorizationCode::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'code' => $hashedCode,
            'redirect_uri' => 'http://localhost/callback',
            'scopes' => ['projects:read'],
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'expires_at' => $expiresAt ?? Carbon::now()->addMinutes(10),
            'tenant_id' => $this->tenant->id,
        ]);

        return ['raw' => $rawCode, 'hash' => $hashedCode];
    }

    public function test_valid_s256_code_verifier_produces_matching_challenge(): void
    {
        $data = $this->createClientAndUser();
        $pkce = $this->generatePkce();
        $code = $this->createAuthorizationCode($data['client'], $data['user'], $pkce['challenge']);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $data['client']->client_id,
            'code' => $code['raw'],
            'redirect_uri' => 'http://localhost/callback',
            'code_verifier' => $pkce['verifier'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token']);
        $this->assertEquals('bearer', $response->json('token_type'));
    }

    public function test_invalid_code_verifier_is_rejected(): void
    {
        $data = $this->createClientAndUser();
        $pkce = $this->generatePkce();
        $code = $this->createAuthorizationCode($data['client'], $data['user'], $pkce['challenge']);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $data['client']->client_id,
            'code' => $code['raw'],
            'redirect_uri' => 'http://localhost/callback',
            'code_verifier' => 'wrong-verifier-value',
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'invalid_grant']);
    }

    public function test_missing_code_challenge_in_authorization_request_is_rejected(): void
    {
        $response = $this->getJson('/oauth/authorize?'.http_build_query([
            'client_id' => 'some-client',
            'redirect_uri' => 'http://localhost/callback',
            'response_type' => 'code',
            'code_challenge_method' => 'S256',
        ]));

        $response->assertStatus(422);
    }

    public function test_code_challenge_method_other_than_s256_is_rejected(): void
    {
        $data = $this->createClientAndUser();

        $response = $this->getJson('/oauth/authorize?'.http_build_query([
            'client_id' => $data['client']->client_id,
            'redirect_uri' => 'http://localhost/callback',
            'response_type' => 'code',
            'code_challenge' => 'some-challenge',
            'code_challenge_method' => 'plain',
        ]));

        $response->assertStatus(422);
    }

    public function test_replayed_authorization_code_is_rejected(): void
    {
        $data = $this->createClientAndUser();
        $pkce = $this->generatePkce();
        $code = $this->createAuthorizationCode($data['client'], $data['user'], $pkce['challenge']);

        // First use — should succeed
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $data['client']->client_id,
            'code' => $code['raw'],
            'redirect_uri' => 'http://localhost/callback',
            'code_verifier' => $pkce['verifier'],
        ])->assertStatus(200);

        // Second use — should fail
        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $data['client']->client_id,
            'code' => $code['raw'],
            'redirect_uri' => 'http://localhost/callback',
            'code_verifier' => $pkce['verifier'],
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'invalid_grant']);
    }

    public function test_expired_authorization_code_is_rejected(): void
    {
        $data = $this->createClientAndUser();
        $pkce = $this->generatePkce();
        $code = $this->createAuthorizationCode(
            $data['client'],
            $data['user'],
            $pkce['challenge'],
            Carbon::now()->subMinutes(1),
        );

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $data['client']->client_id,
            'code' => $code['raw'],
            'redirect_uri' => 'http://localhost/callback',
            'code_verifier' => $pkce['verifier'],
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'invalid_grant']);
    }

    public function test_mismatched_redirect_uri_in_token_exchange_is_rejected(): void
    {
        $data = $this->createClientAndUser();
        $pkce = $this->generatePkce();
        $code = $this->createAuthorizationCode($data['client'], $data['user'], $pkce['challenge']);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $data['client']->client_id,
            'code' => $code['raw'],
            'redirect_uri' => 'http://example.com/different',
            'code_verifier' => $pkce['verifier'],
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'invalid_grant']);
    }
}
