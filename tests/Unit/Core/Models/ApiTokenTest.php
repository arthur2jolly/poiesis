<?php

namespace Tests\Unit\Core\Models;

use App\Core\Models\ApiToken;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    public function test_generate_raw_returns_prefixed_token_and_hash(): void
    {
        $result = ApiToken::generateRaw();

        $this->assertStringStartsWith('aa-', $result['raw']);
        $this->assertEquals(43, strlen($result['raw'])); // aa- + 40 hex chars
        $this->assertEquals(hash('sha256', $result['raw']), $result['hash']);
    }

    public function test_is_expired_returns_false_when_null(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $token = ApiToken::generateRaw();
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'test',
            'token' => $token['hash'],
            'expires_at' => null,
        ]);

        $this->assertFalse($apiToken->isExpired());
    }

    public function test_is_expired_returns_true_for_past_date(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $token = ApiToken::generateRaw();
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'test',
            'token' => $token['hash'],
            'expires_at' => Carbon::yesterday(),
        ]);

        $this->assertTrue($apiToken->isExpired());
    }

    public function test_is_expired_returns_false_for_future_date(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $token = ApiToken::generateRaw();
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'test',
            'token' => $token['hash'],
            'expires_at' => Carbon::tomorrow(),
        ]);

        $this->assertFalse($apiToken->isExpired());
    }

    public function test_record_usage_updates_last_used_at(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $token = ApiToken::generateRaw();
        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'test',
            'token' => $token['hash'],
        ]);

        $this->assertNull($apiToken->last_used_at);

        $apiToken->recordUsage();
        $apiToken->refresh();

        $this->assertNotNull($apiToken->last_used_at);
    }
}
