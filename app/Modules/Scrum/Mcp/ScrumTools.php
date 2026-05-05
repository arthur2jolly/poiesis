<?php

declare(strict_types=1);

namespace App\Modules\Scrum\Mcp;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\User;

class ScrumTools implements McpToolInterface
{
    use ScrumBacklogToolMethods;
    use ScrumBoardToolMethods;
    use ScrumColumnToolMethods;
    use ScrumItemPlacementToolMethods;
    use ScrumPlanningAndValidationToolMethods;
    use ScrumSharedToolMethods;
    use ScrumSprintItemToolMethods;
    use ScrumSprintToolMethods;
    use ScrumToolDescriptionMethods;

    /** @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function tools(): array
    {
        return [
            $this->getCreateSprintToolDescription(),
            $this->getListSprintsToolDescription(),
            $this->getGetSprintToolDescription(),
            $this->getUpdateSprintToolDescription(),
            $this->getDeleteSprintToolDescription(),
            $this->getStartSprintToolDescription(),
            $this->getCloseSprintToolDescription(),
            $this->getCancelSprintToolDescription(),
            $this->getAddToSprintToolDescription(),
            $this->getRemoveFromSprintToolDescription(),
            $this->getListSprintItemsToolDescription(),
            $this->getListBacklogToolDescription(),
            $this->getReorderBacklogToolDescription(),
            $this->getEstimateStoryToolDescription(),
            $this->getMarkReadyToolDescription(),
            $this->getMarkUnreadyToolDescription(),
            $this->getStartPlanningToolDescription(),
            $this->getAddToPlanningToolDescription(),
            $this->getRemoveFromPlanningToolDescription(),
            $this->getValidateSprintPlanToolDescription(),
            $this->getBoardBuildToolDescription(),
            $this->getBoardGetToolDescription(),
            $this->getColumnCreateToolDescription(),
            $this->getColumnUpdateToolDescription(),
            $this->getColumnDeleteToolDescription(),
            $this->getColumnReorderToolDescription(),
            $this->getItemPlaceToolDescription(),
            $this->getItemMoveToolDescription(),
            $this->getItemUnplaceToolDescription(),
            $this->getColumnItemsToolDescription(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'create_sprint' => $this->sprintCreate($params, $user),
            'list_sprints' => $this->sprintList($params, $user),
            'get_sprint' => $this->sprintGet($params, $user),
            'update_sprint' => $this->sprintUpdate($params, $user),
            'delete_sprint' => $this->sprintDelete($params, $user),
            'start_sprint' => $this->sprintStart($params, $user),
            'close_sprint' => $this->sprintClose($params, $user),
            'cancel_sprint' => $this->sprintCancel($params, $user),
            'add_to_sprint' => $this->sprintItemAdd($params, $user),
            'remove_from_sprint' => $this->sprintItemRemove($params, $user),
            'list_sprint_items' => $this->sprintItemList($params, $user),
            'list_backlog' => $this->backlogList($params, $user),
            'reorder_backlog' => $this->backlogReorder($params, $user),
            'estimate_story' => $this->storyEstimate($params, $user),
            'mark_ready' => $this->storyMarkReady($params, $user),
            'mark_unready' => $this->storyMarkUnready($params, $user),
            'start_planning' => $this->planningStart($params, $user),
            'add_to_planning' => $this->planningAdd($params, $user),
            'remove_from_planning' => $this->planningRemove($params, $user),
            'validate_sprint_plan' => $this->sprintValidatePlan($params, $user),
            'scrum_board_build' => $this->boardBuild($params, $user),
            'scrum_board_get' => $this->boardGet($params, $user),
            'scrum_column_create' => $this->columnCreate($params, $user),
            'scrum_column_update' => $this->columnUpdate($params, $user),
            'scrum_column_delete' => $this->columnDelete($params, $user),
            'scrum_column_reorder' => $this->columnReorder($params, $user),
            'scrum_item_place' => $this->itemPlace($params, $user),
            'scrum_item_move' => $this->itemMove($params, $user),
            'scrum_item_unplace' => $this->itemUnplace($params, $user),
            'scrum_column_items' => $this->columnItems($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }
}
