<?php

namespace Tests\Feature\Modules\Document;

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use App\Modules\Document\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DocumentToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    private Project $project;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = createTenant();

        $this->user = User::factory()->manager()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $this->user->apiTokens()->create(['name' => 'test', 'token' => $raw['hash'], 'tenant_id' => $this->tenant->id]);
        $this->token = $raw['raw'];

        $this->project = Project::factory()->create([
            'code' => 'DOC',
            'tenant_id' => $this->tenant->id,
            'modules' => ['document'],
        ]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'position' => 'owner',
        ]);

        app(TenantManager::class)->setTenant($this->tenant);
    }

    private function mcpCall(string $toolName, array $arguments = []): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function extractToolResult(TestResponse $response): mixed
    {
        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertArrayNotHasKey('error', $data);
        $text = $data['result']['content'][0]['text'];

        return json_decode($text, true);
    }

    // ===== CREATE =====

    public function test_create_document(): void
    {
        $result = $this->extractToolResult($this->mcpCall('document_create', [
            'project_code' => 'DOC',
            'title' => 'API Specification',
            'summary' => 'REST API design document',
            'type' => 'spec',
            'tags' => ['api', 'v2'],
        ]));

        $this->assertStringStartsWith('DOC-', $result['identifier']);
        $this->assertEquals('API Specification', $result['title']);
        $this->assertEquals('REST API design document', $result['summary']);
        $this->assertEquals('spec', $result['type']);
        $this->assertEquals('draft', $result['status']);
        $this->assertEquals(['api', 'v2'], $result['tags']);
        $this->assertEquals(0, $result['content_length']);
    }

    public function test_create_document_minimal(): void
    {
        $result = $this->extractToolResult($this->mcpCall('document_create', [
            'project_code' => 'DOC',
            'title' => 'Quick Note',
        ]));

        $this->assertEquals('Quick Note', $result['title']);
        $this->assertEquals('reference', $result['type']);
        $this->assertEquals('', $result['summary']);
    }

    public function test_create_document_rejects_long_summary(): void
    {
        $response = $this->mcpCall('document_create', [
            'project_code' => 'DOC',
            'title' => 'Test',
            'summary' => str_repeat('x', 2001),
        ]);

        $this->assertNotNull($response->json('error'));
    }

    public function test_create_document_rejects_invalid_type(): void
    {
        $response = $this->mcpCall('document_create', [
            'project_code' => 'DOC',
            'title' => 'Test',
            'type' => 'invalid',
        ]);

        $this->assertNotNull($response->json('error'));
    }

    // ===== LIST =====

    public function test_list_documents(): void
    {
        Document::create(['project_id' => $this->project->id, 'title' => 'Doc A', 'type' => 'spec']);
        Document::create(['project_id' => $this->project->id, 'title' => 'Doc B', 'type' => 'note']);

        $result = $this->extractToolResult($this->mcpCall('document_list', [
            'project_code' => 'DOC',
        ]));

        $this->assertCount(2, $result['data']);
        $this->assertEquals(2, $result['meta']['total']);
    }

    public function test_list_documents_filter_by_type(): void
    {
        Document::create(['project_id' => $this->project->id, 'title' => 'Spec', 'type' => 'spec']);
        Document::create(['project_id' => $this->project->id, 'title' => 'Note', 'type' => 'note']);

        $result = $this->extractToolResult($this->mcpCall('document_list', [
            'project_code' => 'DOC',
            'type' => 'spec',
        ]));

        $this->assertCount(1, $result['data']);
        $this->assertEquals('Spec', $result['data'][0]['title']);
    }

    public function test_list_documents_filter_by_status(): void
    {
        Document::create(['project_id' => $this->project->id, 'title' => 'Draft', 'type' => 'note', 'status' => 'draft']);
        Document::create(['project_id' => $this->project->id, 'title' => 'Published', 'type' => 'note', 'status' => 'published']);

        $result = $this->extractToolResult($this->mcpCall('document_list', [
            'project_code' => 'DOC',
            'status' => 'published',
        ]));

        $this->assertCount(1, $result['data']);
        $this->assertEquals('Published', $result['data'][0]['title']);
    }

    public function test_list_documents_filter_by_tag(): void
    {
        Document::create(['project_id' => $this->project->id, 'title' => 'Tagged', 'type' => 'note', 'tags' => ['api', 'v2']]);
        Document::create(['project_id' => $this->project->id, 'title' => 'Untagged', 'type' => 'note']);

        $result = $this->extractToolResult($this->mcpCall('document_list', [
            'project_code' => 'DOC',
            'tag' => 'api',
        ]));

        $this->assertCount(1, $result['data']);
        $this->assertEquals('Tagged', $result['data'][0]['title']);
    }

    // ===== READ (PAGINATED) =====

    public function test_read_document_single_page(): void
    {
        $doc = Document::create([
            'project_id' => $this->project->id,
            'title' => 'Short',
            'type' => 'note',
            'content' => 'Hello world',
        ]);

        $result = $this->extractToolResult($this->mcpCall('document_read', [
            'identifier' => $doc->identifier,
        ]));

        $this->assertEquals('Hello world', $result['content']);
        $this->assertEquals(1, $result['pagination']['current_page']);
        $this->assertEquals(1, $result['pagination']['total_pages']);
        $this->assertEquals(11, $result['pagination']['total_length']);
    }

    public function test_read_document_paginated(): void
    {
        $content = str_repeat('A', 5000); // 3 pages (2000 + 2000 + 1000)
        $doc = Document::create([
            'project_id' => $this->project->id,
            'title' => 'Long',
            'type' => 'note',
            'content' => $content,
        ]);

        // Page 1
        $page1 = $this->extractToolResult($this->mcpCall('document_read', [
            'identifier' => $doc->identifier,
            'page' => 1,
        ]));
        $this->assertEquals(2000, mb_strlen($page1['content']));
        $this->assertEquals(3, $page1['pagination']['total_pages']);

        // Page 3
        $page3 = $this->extractToolResult($this->mcpCall('document_read', [
            'identifier' => $doc->identifier,
            'page' => 3,
        ]));
        $this->assertEquals(1000, mb_strlen($page3['content']));
    }

    public function test_read_empty_document(): void
    {
        $doc = Document::create([
            'project_id' => $this->project->id,
            'title' => 'Empty',
            'type' => 'note',
        ]);

        $result = $this->extractToolResult($this->mcpCall('document_read', [
            'identifier' => $doc->identifier,
        ]));

        $this->assertEquals('', $result['content']);
        $this->assertEquals(1, $result['pagination']['total_pages']);
    }

    // ===== UPDATE =====

    public function test_update_document_metadata(): void
    {
        $doc = Document::create(['project_id' => $this->project->id, 'title' => 'Old', 'type' => 'note']);

        $result = $this->extractToolResult($this->mcpCall('document_update', [
            'identifier' => $doc->identifier,
            'title' => 'New Title',
            'type' => 'spec',
            'status' => 'published',
            'tags' => ['updated'],
        ]));

        $this->assertEquals('New Title', $result['title']);
        $this->assertEquals('spec', $result['type']);
        $this->assertEquals('published', $result['status']);
        $this->assertEquals(['updated'], $result['tags']);
    }

    public function test_update_document_rejects_invalid_status(): void
    {
        $doc = Document::create(['project_id' => $this->project->id, 'title' => 'Test', 'type' => 'note']);

        $response = $this->mcpCall('document_update', [
            'identifier' => $doc->identifier,
            'status' => 'invalid',
        ]);

        $this->assertNotNull($response->json('error'));
    }

    // ===== APPEND =====

    public function test_append_content(): void
    {
        $doc = Document::create([
            'project_id' => $this->project->id,
            'title' => 'Build',
            'type' => 'note',
            'content' => 'Part 1. ',
        ]);

        $result = $this->extractToolResult($this->mcpCall('document_append', [
            'identifier' => $doc->identifier,
            'text' => 'Part 2.',
        ]));

        $this->assertEquals(15, $result['content_length']);

        // Verify full content
        $read = $this->extractToolResult($this->mcpCall('document_read', [
            'identifier' => $doc->identifier,
        ]));
        $this->assertEquals('Part 1. Part 2.', $read['content']);
    }

    // ===== REPLACE =====

    public function test_replace_content(): void
    {
        $doc = Document::create([
            'project_id' => $this->project->id,
            'title' => 'Replace',
            'type' => 'note',
            'content' => 'Old content',
        ]);

        $result = $this->extractToolResult($this->mcpCall('document_replace', [
            'identifier' => $doc->identifier,
            'content' => 'Brand new content',
        ]));

        $this->assertEquals(17, $result['content_length']);

        $doc->refresh();
        $this->assertEquals('Brand new content', $doc->content);
    }

    public function test_replace_with_empty_clears_content(): void
    {
        $doc = Document::create([
            'project_id' => $this->project->id,
            'title' => 'Clear',
            'type' => 'note',
            'content' => 'Some content',
        ]);

        $result = $this->extractToolResult($this->mcpCall('document_replace', [
            'identifier' => $doc->identifier,
            'content' => '',
        ]));

        $this->assertEquals(0, $result['content_length']);
    }

    // ===== DELETE =====

    public function test_delete_document(): void
    {
        $doc = Document::create(['project_id' => $this->project->id, 'title' => 'Delete Me', 'type' => 'note']);
        $identifier = $doc->identifier;

        $result = $this->extractToolResult($this->mcpCall('document_delete', [
            'identifier' => $identifier,
        ]));

        $this->assertEquals('Document deleted.', $result['message']);
        $this->assertDatabaseMissing('documents', ['id' => $doc->id]);
        $this->assertDatabaseMissing('artifacts', ['artifactable_id' => $doc->id]);
    }

    // ===== MODULE ACTIVATION =====

    public function test_document_tools_require_module_activation(): void
    {
        $project = Project::factory()->create([
            'code' => 'NOMOD',
            'tenant_id' => $this->tenant->id,
            'modules' => [],
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $this->user->id,
            'position' => 'owner',
        ]);

        $response = $this->mcpCall('document_create', [
            'project_code' => 'NOMOD',
            'title' => 'Should Fail',
        ]);

        $this->assertNotNull($response->json('error'));
    }

    // ===== ACCESS CONTROL =====

    public function test_viewer_cannot_create_document(): void
    {
        $viewer = User::factory()->viewer()->create(['tenant_id' => $this->tenant->id]);
        $raw = ApiToken::generateRaw();
        $viewer->apiTokens()->create(['name' => 'test', 'token' => $raw['hash'], 'tenant_id' => $this->tenant->id]);
        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $viewer->id,
            'position' => 'member',
        ]);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'document_create',
                'arguments' => ['project_code' => 'DOC', 'title' => 'Test'],
            ],
        ], ['Authorization' => 'Bearer '.$raw['raw']]);

        $this->assertNotNull($response->json('error'));
    }
}
