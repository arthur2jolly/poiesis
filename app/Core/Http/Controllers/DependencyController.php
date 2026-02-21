<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\Artifact;
use App\Core\Services\DependencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DependencyController extends Controller
{
    public function __construct(
        private readonly DependencyService $dependencyService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blocked_identifier' => ['required', 'string'],
            'blocking_identifier' => ['required', 'string'],
        ]);

        $blockedItem = Artifact::resolveIdentifier($validated['blocked_identifier']);
        $blockingItem = Artifact::resolveIdentifier($validated['blocking_identifier']);

        if (! $blockedItem || ! $blockingItem) {
            return response()->json(['message' => 'One or both identifiers not found.'], 404);
        }

        $this->dependencyService->addDependency($blockedItem, $blockingItem);

        $deps = $this->dependencyService->getDependencies($blockedItem);

        return response()->json([
            'blocked_by' => collect($deps['blocked_by'])->map(fn ($m) => $m->identifier)->values(),
            'blocks' => collect($deps['blocks'])->map(fn ($m) => $m->identifier)->values(),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blocked_identifier' => ['required', 'string'],
            'blocking_identifier' => ['required', 'string'],
        ]);

        $blockedItem = Artifact::resolveIdentifier($validated['blocked_identifier']);
        $blockingItem = Artifact::resolveIdentifier($validated['blocking_identifier']);

        if (! $blockedItem || ! $blockingItem) {
            return response()->json(['message' => 'One or both identifiers not found.'], 404);
        }

        $this->dependencyService->removeDependency($blockedItem, $blockingItem);

        return response()->json(null, 204);
    }

    public function show(string $identifier): JsonResponse
    {
        $item = Artifact::resolveIdentifier($identifier);

        if (! $item) {
            abort(404, 'Artifact not found.');
        }

        $deps = $this->dependencyService->getDependencies($item);

        return response()->json([
            'blocked_by' => collect($deps['blocked_by'])->map(fn ($m) => $m->identifier)->values(),
            'blocks' => collect($deps['blocks'])->map(fn ($m) => $m->identifier)->values(),
        ]);
    }
}
