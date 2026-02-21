<?php

namespace Tests\Feature\Core\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Validates that all 13 Core migrations produce the expected schema.
 *
 * Uses SQLite-compatible introspection:
 *   - Schema::hasTable()       — table existence
 *   - Schema::hasColumns()     — column presence
 *   - PRAGMA table_info()      — column type, nullable, default
 *   - PRAGMA index_list()      — index/unique constraint list
 *   - PRAGMA index_info()      — columns composing an index
 *   - PRAGMA foreign_key_list()— foreign key definitions
 */
class MigrationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Return PRAGMA table_info rows keyed by column name. */
    private function columnInfo(string $table): array
    {
        $rows = DB::select("PRAGMA table_info(`{$table}`)");

        return array_column($rows, null, 'name');
    }

    /**
     * Return all indexes for a table.
     * Each entry: ['name' => string, 'unique' => bool, 'columns' => string[]]
     *
     * @return array<int, array{name: string, unique: bool, columns: string[]}>
     */
    private function indexList(string $table): array
    {
        $indexes = DB::select("PRAGMA index_list(`{$table}`)");
        $result = [];

        foreach ($indexes as $idx) {
            $cols = DB::select("PRAGMA index_info(`{$idx->name}`)");
            $result[] = [
                'name' => $idx->name,
                'unique' => (bool) $idx->unique,
                'columns' => array_column($cols, 'name'),
            ];
        }

        return $result;
    }

    /**
     * Return foreign key definitions for a table.
     * Each entry: ['from' => string, 'table' => string, 'to' => string]
     *
     * @return array<int, array{from: string, table: string, to: string}>
     */
    private function foreignKeys(string $table): array
    {
        $rows = DB::select("PRAGMA foreign_key_list(`{$table}`)");

        return array_map(
            fn ($r) => ['from' => $r->from, 'table' => $r->table, 'to' => $r->to],
            $rows
        );
    }

    /** Assert that at least one index covers exactly the given column set and matches the unique flag. */
    private function assertIndexExists(array $indexes, array $columns, bool $unique, string $message = ''): void
    {
        $sortedTarget = $columns;
        sort($sortedTarget);

        foreach ($indexes as $idx) {
            $sortedCols = $idx['columns'];
            sort($sortedCols);

            if ($sortedCols === $sortedTarget && $idx['unique'] === $unique) {
                $this->assertTrue(true);

                return;
            }
        }

        $label = implode(', ', $columns);
        $type = $unique ? 'UNIQUE' : 'INDEX';
        $this->fail($message ?: "No {$type} index found on columns [{$label}].");
    }

    /** Assert that at least one FK exists from $fromColumn to $referencedTable.$toColumn. */
    private function assertForeignKeyExists(array $fks, string $fromColumn, string $referencedTable, string $toColumn = 'id'): void
    {
        foreach ($fks as $fk) {
            if ($fk['from'] === $fromColumn && $fk['table'] === $referencedTable && $fk['to'] === $toColumn) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("No foreign key found: {$fromColumn} → {$referencedTable}.{$toColumn}");
    }

    // -------------------------------------------------------------------------
    // 1. projects
    // -------------------------------------------------------------------------

    public function test_projects_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('projects'));
    }

    public function test_projects_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('projects', ['id', 'code', 'titre', 'description', 'modules', 'created_at', 'updated_at'])
        );
    }

    public function test_projects_column_types_and_nullability(): void
    {
        $cols = $this->columnInfo('projects');

        $this->assertStringContainsStringIgnoringCase('char', $cols['id']->type);
        $this->assertEquals('1', $cols['id']->pk);            // PK flag set

        $this->assertStringContainsStringIgnoringCase('varchar', $cols['code']->type);
        $this->assertEquals('1', $cols['code']->notnull);

        $this->assertStringContainsStringIgnoringCase('varchar', $cols['titre']->type);
        $this->assertEquals('1', $cols['titre']->notnull);

        $this->assertEquals('0', $cols['description']->notnull); // nullable

        $this->assertStringContainsStringIgnoringCase('text', strtolower($cols['modules']->type)); // json stored as text
        $this->assertEquals('1', $cols['modules']->notnull);
        $this->assertNotNull($cols['modules']->dflt_value);     // default '[]'
    }

    public function test_projects_unique_index_on_code(): void
    {
        $indexes = $this->indexList('projects');
        $this->assertIndexExists($indexes, ['code'], true);
    }

    // -------------------------------------------------------------------------
    // 2. users
    // -------------------------------------------------------------------------

    public function test_users_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_users_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('users', ['id', 'name', 'created_at', 'updated_at'])
        );
    }

    public function test_users_column_types(): void
    {
        $cols = $this->columnInfo('users');

        $this->assertStringContainsStringIgnoringCase('char', $cols['id']->type);
        $this->assertStringContainsStringIgnoringCase('varchar', $cols['name']->type);
        $this->assertEquals('1', $cols['name']->notnull);
    }

    // -------------------------------------------------------------------------
    // 3. api_tokens
    // -------------------------------------------------------------------------

    public function test_api_tokens_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('api_tokens'));
    }

    public function test_api_tokens_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('api_tokens', [
                'id', 'user_id', 'name', 'token',
                'expires_at', 'last_used_at', 'created_at',
            ])
        );
    }

    public function test_api_tokens_nullable_timestamp_columns(): void
    {
        $cols = $this->columnInfo('api_tokens');

        $this->assertEquals('0', $cols['expires_at']->notnull);
        $this->assertEquals('0', $cols['last_used_at']->notnull);
        $this->assertEquals('0', $cols['created_at']->notnull);
    }

    public function test_api_tokens_unique_index_on_token(): void
    {
        $indexes = $this->indexList('api_tokens');
        $this->assertIndexExists($indexes, ['token'], true);
    }

    public function test_api_tokens_foreign_key_to_users(): void
    {
        $fks = $this->foreignKeys('api_tokens');
        $this->assertForeignKeyExists($fks, 'user_id', 'users');
    }

    public function test_api_tokens_composite_index_on_user_id_name(): void
    {
        $indexes = $this->indexList('api_tokens');
        $this->assertIndexExists($indexes, ['user_id', 'name'], false);
    }

    // -------------------------------------------------------------------------
    // 4. oauth_clients
    // -------------------------------------------------------------------------

    public function test_oauth_clients_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('oauth_clients'));
    }

    public function test_oauth_clients_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('oauth_clients', [
                'id', 'user_id', 'name', 'client_id', 'client_secret',
                'redirect_uris', 'grant_types', 'scopes', 'created_at', 'updated_at',
            ])
        );
    }

    public function test_oauth_clients_nullable_columns(): void
    {
        $cols = $this->columnInfo('oauth_clients');

        $this->assertEquals('0', $cols['user_id']->notnull);       // nullable FK
        $this->assertEquals('0', $cols['client_secret']->notnull);  // public clients
        $this->assertEquals('0', $cols['scopes']->notnull);
    }

    public function test_oauth_clients_unique_index_on_client_id(): void
    {
        $indexes = $this->indexList('oauth_clients');
        $this->assertIndexExists($indexes, ['client_id'], true);
    }

    public function test_oauth_clients_foreign_key_to_users(): void
    {
        $fks = $this->foreignKeys('oauth_clients');
        $this->assertForeignKeyExists($fks, 'user_id', 'users');
    }

    // -------------------------------------------------------------------------
    // 5. oauth_authorization_codes
    // -------------------------------------------------------------------------

    public function test_oauth_authorization_codes_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('oauth_authorization_codes'));
    }

    public function test_oauth_authorization_codes_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('oauth_authorization_codes', [
                'id', 'oauth_client_id', 'user_id', 'code', 'redirect_uri',
                'scopes', 'code_challenge', 'code_challenge_method',
                'expires_at', 'created_at',
            ])
        );
    }

    public function test_oauth_authorization_codes_nullable_columns(): void
    {
        $cols = $this->columnInfo('oauth_authorization_codes');

        $this->assertEquals('0', $cols['scopes']->notnull);
        $this->assertEquals('0', $cols['code_challenge']->notnull);
        $this->assertEquals('0', $cols['code_challenge_method']->notnull);
        $this->assertEquals('0', $cols['created_at']->notnull);
        $this->assertEquals('1', $cols['expires_at']->notnull);   // NOT NULL
    }

    public function test_oauth_authorization_codes_unique_index_on_code(): void
    {
        $indexes = $this->indexList('oauth_authorization_codes');
        $this->assertIndexExists($indexes, ['code'], true);
    }

    public function test_oauth_authorization_codes_foreign_key_to_oauth_clients(): void
    {
        $fks = $this->foreignKeys('oauth_authorization_codes');
        $this->assertForeignKeyExists($fks, 'oauth_client_id', 'oauth_clients');
    }

    public function test_oauth_authorization_codes_foreign_key_to_users(): void
    {
        $fks = $this->foreignKeys('oauth_authorization_codes');
        $this->assertForeignKeyExists($fks, 'user_id', 'users');
    }

    // -------------------------------------------------------------------------
    // 6. oauth_access_tokens
    // -------------------------------------------------------------------------

    public function test_oauth_access_tokens_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('oauth_access_tokens'));
    }

    public function test_oauth_access_tokens_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('oauth_access_tokens', [
                'id', 'oauth_client_id', 'user_id', 'token',
                'scopes', 'expires_at', 'created_at',
            ])
        );
    }

    public function test_oauth_access_tokens_nullable_columns(): void
    {
        $cols = $this->columnInfo('oauth_access_tokens');

        $this->assertEquals('0', $cols['scopes']->notnull);
        $this->assertEquals('0', $cols['created_at']->notnull);
        $this->assertEquals('1', $cols['expires_at']->notnull);
    }

    public function test_oauth_access_tokens_unique_index_on_token(): void
    {
        $indexes = $this->indexList('oauth_access_tokens');
        $this->assertIndexExists($indexes, ['token'], true);
    }

    public function test_oauth_access_tokens_foreign_key_to_oauth_clients(): void
    {
        $fks = $this->foreignKeys('oauth_access_tokens');
        $this->assertForeignKeyExists($fks, 'oauth_client_id', 'oauth_clients');
    }

    public function test_oauth_access_tokens_foreign_key_to_users(): void
    {
        $fks = $this->foreignKeys('oauth_access_tokens');
        $this->assertForeignKeyExists($fks, 'user_id', 'users');
    }

    // -------------------------------------------------------------------------
    // 7. oauth_refresh_tokens
    // -------------------------------------------------------------------------

    public function test_oauth_refresh_tokens_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('oauth_refresh_tokens'));
    }

    public function test_oauth_refresh_tokens_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('oauth_refresh_tokens', [
                'id', 'access_token_id', 'token', 'expires_at', 'revoked', 'created_at',
            ])
        );
    }

    public function test_oauth_refresh_tokens_revoked_default(): void
    {
        $cols = $this->columnInfo('oauth_refresh_tokens');

        $this->assertEquals('1', $cols['revoked']->notnull);
        // Default value is 0 (false). SQLite PRAGMA may quote it as '0' or return 0.
        $this->assertContains($cols['revoked']->dflt_value, ['0', "'0'"]);
    }

    public function test_oauth_refresh_tokens_unique_index_on_token(): void
    {
        $indexes = $this->indexList('oauth_refresh_tokens');
        $this->assertIndexExists($indexes, ['token'], true);
    }

    public function test_oauth_refresh_tokens_foreign_key_to_oauth_access_tokens(): void
    {
        $fks = $this->foreignKeys('oauth_refresh_tokens');
        $this->assertForeignKeyExists($fks, 'access_token_id', 'oauth_access_tokens');
    }

    // -------------------------------------------------------------------------
    // 8. project_members
    // -------------------------------------------------------------------------

    public function test_project_members_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('project_members'));
    }

    public function test_project_members_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('project_members', [
                'id', 'project_id', 'user_id', 'role', 'created_at',
            ])
        );
    }

    public function test_project_members_role_default(): void
    {
        $cols = $this->columnInfo('project_members');

        $this->assertEquals('1', $cols['role']->notnull);
        $this->assertEquals("'member'", $cols['role']->dflt_value);
    }

    public function test_project_members_unique_index_on_project_user(): void
    {
        $indexes = $this->indexList('project_members');
        $this->assertIndexExists($indexes, ['project_id', 'user_id'], true);
    }

    public function test_project_members_foreign_key_to_projects(): void
    {
        $fks = $this->foreignKeys('project_members');
        $this->assertForeignKeyExists($fks, 'project_id', 'projects');
    }

    public function test_project_members_foreign_key_to_users(): void
    {
        $fks = $this->foreignKeys('project_members');
        $this->assertForeignKeyExists($fks, 'user_id', 'users');
    }

    // -------------------------------------------------------------------------
    // 9. epics
    // -------------------------------------------------------------------------

    public function test_epics_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('epics'));
    }

    public function test_epics_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('epics', [
                'id', 'project_id', 'titre', 'description', 'created_at', 'updated_at',
            ])
        );
    }

    public function test_epics_nullable_columns(): void
    {
        $cols = $this->columnInfo('epics');

        $this->assertEquals('0', $cols['description']->notnull);
        $this->assertEquals('1', $cols['titre']->notnull);
    }

    public function test_epics_foreign_key_to_projects(): void
    {
        $fks = $this->foreignKeys('epics');
        $this->assertForeignKeyExists($fks, 'project_id', 'projects');
    }

    // -------------------------------------------------------------------------
    // 10. stories
    // -------------------------------------------------------------------------

    public function test_stories_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('stories'));
    }

    public function test_stories_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('stories', [
                'id', 'epic_id', 'titre', 'description', 'type', 'nature',
                'statut', 'priorite', 'ordre', 'story_points',
                'reference_doc', 'tags', 'created_at', 'updated_at',
            ])
        );
    }

    public function test_stories_defaults_and_nullability(): void
    {
        $cols = $this->columnInfo('stories');

        $this->assertEquals("'draft'", $cols['statut']->dflt_value);
        $this->assertEquals("'moyenne'", $cols['priorite']->dflt_value);
        $this->assertEquals('1', $cols['statut']->notnull);
        $this->assertEquals('1', $cols['priorite']->notnull);

        $this->assertEquals('0', $cols['description']->notnull);
        $this->assertEquals('0', $cols['nature']->notnull);
        $this->assertEquals('0', $cols['ordre']->notnull);
        $this->assertEquals('0', $cols['story_points']->notnull);
        $this->assertEquals('0', $cols['reference_doc']->notnull);
        $this->assertEquals('0', $cols['tags']->notnull);
    }

    public function test_stories_foreign_key_to_epics(): void
    {
        $fks = $this->foreignKeys('stories');
        $this->assertForeignKeyExists($fks, 'epic_id', 'epics');
    }

    public function test_stories_indexes(): void
    {
        $indexes = $this->indexList('stories');

        $this->assertIndexExists($indexes, ['epic_id'], false);
        $this->assertIndexExists($indexes, ['type'], false);
        $this->assertIndexExists($indexes, ['statut'], false);
        $this->assertIndexExists($indexes, ['priorite'], false);
    }

    // -------------------------------------------------------------------------
    // 11. tasks
    // -------------------------------------------------------------------------

    public function test_tasks_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('tasks'));
    }

    public function test_tasks_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('tasks', [
                'id', 'project_id', 'story_id', 'titre', 'description',
                'type', 'nature', 'statut', 'priorite', 'ordre',
                'estimation_temps', 'tags', 'created_at', 'updated_at',
            ])
        );
    }

    public function test_tasks_defaults_and_nullability(): void
    {
        $cols = $this->columnInfo('tasks');

        $this->assertEquals("'draft'", $cols['statut']->dflt_value);
        $this->assertEquals("'moyenne'", $cols['priorite']->dflt_value);

        $this->assertEquals('0', $cols['story_id']->notnull);      // nullable FK
        $this->assertEquals('0', $cols['description']->notnull);
        $this->assertEquals('0', $cols['nature']->notnull);
        $this->assertEquals('0', $cols['ordre']->notnull);
        $this->assertEquals('0', $cols['estimation_temps']->notnull);
        $this->assertEquals('0', $cols['tags']->notnull);
    }

    public function test_tasks_foreign_key_to_projects(): void
    {
        $fks = $this->foreignKeys('tasks');
        $this->assertForeignKeyExists($fks, 'project_id', 'projects');
    }

    public function test_tasks_foreign_key_to_stories(): void
    {
        $fks = $this->foreignKeys('tasks');
        $this->assertForeignKeyExists($fks, 'story_id', 'stories');
    }

    public function test_tasks_indexes(): void
    {
        $indexes = $this->indexList('tasks');

        $this->assertIndexExists($indexes, ['project_id'], false);
        $this->assertIndexExists($indexes, ['story_id'], false);
        $this->assertIndexExists($indexes, ['type'], false);
        $this->assertIndexExists($indexes, ['statut'], false);
        $this->assertIndexExists($indexes, ['priorite'], false);
    }

    // -------------------------------------------------------------------------
    // 12. artifacts
    // -------------------------------------------------------------------------

    public function test_artifacts_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('artifacts'));
    }

    public function test_artifacts_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('artifacts', [
                'id', 'project_id', 'identifier', 'sequence_number',
                'artifactable_id', 'artifactable_type', 'created_at', 'updated_at',
            ])
        );
    }

    public function test_artifacts_unique_index_on_identifier(): void
    {
        $indexes = $this->indexList('artifacts');
        $this->assertIndexExists($indexes, ['identifier'], true);
    }

    public function test_artifacts_unique_index_on_project_sequence(): void
    {
        $indexes = $this->indexList('artifacts');
        $this->assertIndexExists($indexes, ['project_id', 'sequence_number'], true);
    }

    public function test_artifacts_foreign_key_to_projects(): void
    {
        $fks = $this->foreignKeys('artifacts');
        $this->assertForeignKeyExists($fks, 'project_id', 'projects');
    }

    public function test_artifacts_polymorphic_index(): void
    {
        $indexes = $this->indexList('artifacts');
        $this->assertIndexExists($indexes, ['artifactable_id', 'artifactable_type'], false);
    }

    // -------------------------------------------------------------------------
    // 13. item_dependencies
    // -------------------------------------------------------------------------

    public function test_item_dependencies_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('item_dependencies'));
    }

    public function test_item_dependencies_has_all_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('item_dependencies', [
                'id', 'item_id', 'item_type', 'depends_on_id', 'depends_on_type', 'created_at',
            ])
        );
    }

    public function test_item_dependencies_unique_composite_index(): void
    {
        $indexes = $this->indexList('item_dependencies');
        $this->assertIndexExists(
            $indexes,
            ['item_id', 'item_type', 'depends_on_id', 'depends_on_type'],
            true,
            'Composite unique index on item_dependencies not found.'
        );
    }

    public function test_item_dependencies_index_on_item(): void
    {
        $indexes = $this->indexList('item_dependencies');
        $this->assertIndexExists($indexes, ['item_id', 'item_type'], false);
    }

    public function test_item_dependencies_index_on_depends_on(): void
    {
        $indexes = $this->indexList('item_dependencies');
        $this->assertIndexExists($indexes, ['depends_on_id', 'depends_on_type'], false);
    }

    public function test_item_dependencies_no_direct_foreign_keys(): void
    {
        // Polymorphic table intentionally has no FK constraints — app handles integrity
        $fks = $this->foreignKeys('item_dependencies');
        $this->assertEmpty($fks, 'item_dependencies should have no foreign key constraints (polymorphic design).');
    }
}
