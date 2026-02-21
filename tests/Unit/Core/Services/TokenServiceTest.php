<?php

namespace Tests\Unit\Core\Services;

use App\Core\Models\User;
use App\Core\Services\TokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private TokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TokenService;
    }

    public function test_generate_creates_token_and_returns_raw(): void
    {
        $user = User::factory()->create();

        $result = $this->service->generate($user, 'my-token');

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('model', $result);
        $this->assertStringStartsWith('aa-', $result['token']);
        $this->assertEquals('my-token', $result['model']->name);
        $this->assertNull($result['model']->expires_at);
        $this->assertDatabaseHas('api_tokens', ['name' => 'my-token', 'user_id' => $user->id]);
    }

    public function test_generate_with_expiry(): void
    {
        $user = User::factory()->create();
        $expiresAt = Carbon::now()->addDay();

        $result = $this->service->generate($user, 'expiring-token', $expiresAt);

        $this->assertNotNull($result['model']->expires_at);
    }

    public function test_revoke_deletes_token(): void
    {
        $user = User::factory()->create();
        $result = $this->service->generate($user, 'delete-me');

        $this->service->revoke($result['model']->id);

        $this->assertDatabaseMissing('api_tokens', ['id' => $result['model']->id]);
    }

    public function test_list_for_user_returns_user_tokens(): void
    {
        $user = User::factory()->create();
        $this->service->generate($user, 'token-1');
        $this->service->generate($user, 'token-2');

        $otherUser = User::factory()->create();
        $this->service->generate($otherUser, 'other-token');

        $tokens = $this->service->listForUser($user);

        $this->assertCount(2, $tokens);
    }
}
