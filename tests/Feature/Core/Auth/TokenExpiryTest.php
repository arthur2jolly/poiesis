<?php

namespace Tests\Feature\Core\Auth;

use App\Core\Models\ApiToken;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenExpiryTest extends TestCase
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

    public function test_last_used_at_is_updated_on_successful_request(): void
    {
        $data = $this->createUserWithToken();

        $this->assertNull($data['token']->last_used_at);

        $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $data['token']->refresh();
        $this->assertNotNull($data['token']->last_used_at);
    }

    public function test_token_with_past_expiry_is_rejected(): void
    {
        $data = $this->createUserWithToken(Carbon::now()->subHour());

        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(401);
    }

    public function test_token_with_future_expiry_is_accepted(): void
    {
        $data = $this->createUserWithToken(Carbon::now()->addHour());

        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(200);
    }

    public function test_permanent_token_is_always_accepted(): void
    {
        $data = $this->createUserWithToken(null);

        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(200);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $data = $this->createUserWithToken();

        // Revoke by deleting
        $data['token']->delete();

        $response = $this->getJson('/api/v1/ping', [
            'Authorization' => 'Bearer '.$data['raw'],
        ]);

        $response->assertStatus(401);
    }
}
