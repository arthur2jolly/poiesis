<?php

namespace Tests\Feature\Modules\Scrum;

use App\Core\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoryColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->make('migrator')->path(
            base_path('app/Modules/Scrum/Database/Migrations')
        );
    }

    public function test_stories_table_has_rank_column(): void
    {
        $this->assertTrue(Schema::hasColumn('stories', 'rank'));
    }

    public function test_stories_table_has_ready_column(): void
    {
        $this->assertTrue(Schema::hasColumn('stories', 'ready'));
    }

    public function test_existing_story_factory_still_works(): void
    {
        $story = Story::factory()->create();

        $row = DB::table('stories')->where('id', $story->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->rank);
        $this->assertFalse((bool) $row->ready);
    }

    public function test_rank_can_be_set(): void
    {
        $story = Story::factory()->create();

        DB::table('stories')
            ->where('id', $story->id)
            ->update(['rank' => 42]);

        $updated = DB::table('stories')->where('id', $story->id)->first();
        $this->assertNotNull($updated);
        $this->assertSame(42, (int) $updated->rank);
    }

    public function test_ready_can_be_toggled(): void
    {
        $story = Story::factory()->create();

        DB::table('stories')
            ->where('id', $story->id)
            ->update(['ready' => true]);

        $updated = DB::table('stories')->where('id', $story->id)->first();
        $this->assertNotNull($updated);
        $this->assertTrue((bool) $updated->ready);
    }

    public function test_story_points_still_present(): void
    {
        $this->assertTrue(Schema::hasColumn('stories', 'story_points'));
    }
}
