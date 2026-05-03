<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Scrum;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Modules\Scrum\Models\ScrumColumn;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScrumHtmlPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_scrum_pages_are_available_without_dashboard_or_kanban_modules(): void
    {
        [$user, $project, $sprint] = $this->scrumProject(['modules' => ['scrum']]);
        $epic = Epic::factory()->create(['project_id' => $project->id, 'titre' => 'Scrum UX']);
        $story = Story::factory()->create([
            'epic_id' => $epic->id,
            'titre' => 'Afficher le backlog Scrum',
            'rank' => 0,
            'ready' => true,
        ]);
        $notReadyStory = Story::factory()->create([
            'epic_id' => $epic->id,
            'titre' => 'Story non prete hors board',
            'rank' => 1,
            'ready' => false,
        ]);
        $firstTask = Task::factory()->create([
            'project_id' => $project->id,
            'story_id' => $story->id,
            'titre' => 'Preparer les donnees de sprint',
            'description' => "Charger les stories du sprint.\nInclure les taches enfants dans le rendu.",
            'ordre' => 0,
            'statut' => 'open',
        ]);
        $secondTask = Task::factory()->create([
            'project_id' => $project->id,
            'story_id' => $story->id,
            'titre' => 'Verifier le rendu des taches',
            'ordre' => 1,
        ]);
        $column = ScrumColumn::create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'name' => 'To Do',
            'position' => 0,
        ]);
        $item = SprintItem::create([
            'sprint_id' => $sprint->id,
            'artifact_id' => $story->artifact->id,
            'position' => 0,
        ]);
        $notReadyItem = SprintItem::create([
            'sprint_id' => $sprint->id,
            'artifact_id' => $notReadyStory->artifact->id,
            'position' => 1,
        ]);
        $column->placements()->create([
            'sprint_item_id' => $item->id,
            'position' => 0,
        ]);
        $column->placements()->create([
            'sprint_item_id' => $notReadyItem->id,
            'position' => 1,
        ]);

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/sprints")
            ->assertOk()
            ->assertSee($sprint->identifier)
            ->assertSee('Sprint courant');

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/sprints/{$sprint->identifier}")
            ->assertOk()
            ->assertSee('Afficher le backlog Scrum')
            ->assertSee('2 tache(s) associee(s)')
            ->assertSee($firstTask->identifier)
            ->assertSee('Preparer les donnees de sprint')
            ->assertSee('Details')
            ->assertSee('Inclure les taches enfants dans le rendu')
            ->assertSee($secondTask->identifier)
            ->assertSee('Verifier le rendu des taches');

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/backlog")
            ->assertOk()
            ->assertSee('Afficher le backlog Scrum')
            ->assertSee('ready');

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/board")
            ->assertOk()
            ->assertSee('To Do')
            ->assertSee('Afficher le backlog Scrum')
            ->assertSee('Taches')
            ->assertSee('Tache en cours de developpement')
            ->assertSee($firstTask->identifier)
            ->assertSee('Preparer les donnees de sprint')
            ->assertSee($secondTask->identifier)
            ->assertSee('Verifier le rendu des taches')
            ->assertDontSee('Story non prete hors board');

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/board/{$sprint->identifier}")
            ->assertOk()
            ->assertSee($sprint->identifier)
            ->assertSee('To Do')
            ->assertSee($firstTask->identifier)
            ->assertSee('Preparer les donnees de sprint')
            ->assertDontSee('Story non prete hors board');
    }

    public function test_scrum_pages_require_scrum_module_to_be_active(): void
    {
        [$user, $project] = $this->scrumProject(['modules' => []]);

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/sprints")
            ->assertNotFound();
    }

    public function test_dashboard_project_navigation_links_to_scrum_when_module_is_active(): void
    {
        [$user, $project] = $this->scrumProject(['modules' => ['dashboard', 'scrum']]);

        $this->actingAs($user, 'web')
            ->get("/dashboard/{$project->code}")
            ->assertOk()
            ->assertSee('Scrum')
            ->assertSee("/scrum/{$project->code}/sprints", false);
    }

    public function test_scrum_pages_auto_refresh_every_thirty_seconds(): void
    {
        [$user, $project] = $this->scrumProject(['modules' => ['scrum']]);

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/sprints")
            ->assertOk()
            ->assertSee('<meta http-equiv="refresh" content="30">', false);
    }

    public function test_scrum_pages_link_back_to_dashboard_when_dashboard_is_active(): void
    {
        [$user, $project] = $this->scrumProject(['modules' => ['dashboard', 'scrum']]);

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/sprints")
            ->assertOk()
            ->assertSee('Projet')
            ->assertSee("/dashboard/{$project->code}", false);
    }

    public function test_scrum_pages_do_not_link_to_dashboard_when_dashboard_is_inactive(): void
    {
        [$user, $project] = $this->scrumProject(['modules' => ['scrum']]);

        $this->actingAs($user, 'web')
            ->get("/scrum/{$project->code}/sprints")
            ->assertOk()
            ->assertDontSee("/dashboard/{$project->code}", false);
    }

    public function test_scrum_module_does_not_import_dashboard_or_kanban_code(): void
    {
        $files = [
            app_path('Modules/Scrum/ScrumModule.php'),
            app_path('Modules/Scrum/Http/Controllers/ScrumController.php'),
            app_path('Modules/Scrum/Http/Middleware/AuthenticateScrumWeb.php'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString('App\\Modules\\Dashboard\\', $source);
            $this->assertStringNotContainsString('App\\Modules\\Kanban\\', $source);
        }
    }

    /**
     * @param  array<string, mixed>  $projectAttrs
     * @return array{0: User, 1: Project, 2?: Sprint}
     */
    private function scrumProject(array $projectAttrs = []): array
    {
        $auth = createAuth();
        /** @var User $user */
        $user = $auth['user'];
        $project = Project::factory()->create(array_merge([
            'tenant_id' => $auth['tenant']->id,
            'code' => 'SCRUMHTML',
            'titre' => 'Scrum HTML',
            'modules' => ['scrum'],
        ], $projectAttrs));

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'position' => 'owner',
        ]);

        if (($projectAttrs['modules'] ?? ['scrum']) === []) {
            return [$user, $project];
        }

        $sprint = Sprint::create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'name' => 'Sprint courant',
            'goal' => 'Publier les pages Scrum',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'capacity' => 20,
            'status' => 'active',
        ]);

        return [$user, $project, $sprint];
    }
}
