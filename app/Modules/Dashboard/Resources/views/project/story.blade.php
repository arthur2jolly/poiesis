@extends('dashboard::layout')

@section('title', $story->identifier . ' - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <a href="{{ route('dashboard.stories', $project->code) }}" class="hover:text-gray-700">Stories</a>
    <span>/</span>
    <span class="text-gray-700">{{ $story->identifier }}</span>
@endsection

@section('tabs')
    @include('dashboard::components.project-tabs', ['project' => $project, 'active' => 'stories'])
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <div class="flex items-center gap-3 mb-3">
            <span class="text-sm font-mono font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">{{ $story->identifier }}</span>
            @include('dashboard::components.status-badge', ['status' => $story->statut])
            @include('dashboard::components.priority-badge', ['priority' => $story->priorite])
        </div>
        <h1 class="text-xl font-semibold text-gray-900 mb-2">{{ $story->titre }}</h1>
        @if($story->description)
            <p class="text-gray-600 mb-4">{{ $story->description }}</p>
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="text-gray-400">Type</span>
                <div class="text-gray-900">{{ $story->type }}</div>
            </div>
            <div>
                <span class="text-gray-400">Nature</span>
                <div class="text-gray-900">{{ $story->nature ?? '-' }}</div>
            </div>
            <div>
                <span class="text-gray-400">Story points</span>
                <div class="text-gray-900">{{ $story->story_points ?? '-' }}</div>
            </div>
            <div>
                <span class="text-gray-400">Epic</span>
                <div>
                    <a href="{{ route('dashboard.epic', [$project->code, $story->epic->identifier]) }}" class="text-blue-600 hover:underline font-mono">{{ $story->epic->identifier }}</a>
                </div>
            </div>
        </div>

        @if($story->tags)
            <div class="mt-4 flex flex-wrap gap-1">
                @foreach($story->tags as $tag)
                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ $tag }}</span>
                @endforeach
            </div>
        @endif

        @php
            $blockedBy = $story->blockedBy();
            $blocks = $story->blocks();
        @endphp
        @if($blockedBy->isNotEmpty() || $blocks->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                @if($blockedBy->isNotEmpty())
                    <div>
                        <span class="text-gray-400">Bloquee par</span>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($blockedBy as $dep)
                                <span class="font-mono text-xs text-red-600 bg-red-50 px-2 py-0.5 rounded">{{ $dep->artifact?->identifier ?? $dep->id }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($blocks->isNotEmpty())
                    <div>
                        <span class="text-gray-400">Bloque</span>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($blocks as $dep)
                                <span class="font-mono text-xs text-orange-600 bg-orange-50 px-2 py-0.5 rounded">{{ $dep->artifact?->identifier ?? $dep->id }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <h2 class="text-lg font-semibold text-gray-900 mb-4">Tasks ({{ $story->tasks->count() }})</h2>

    @if($story->tasks->isEmpty())
        <p class="text-gray-500">Aucune task.</p>
    @else
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Titre</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Priorite</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Estimation</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($story->tasks as $task)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('dashboard.task', [$project->code, $task->identifier]) }}" class="text-sm font-mono text-blue-600 hover:underline">{{ $task->identifier }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $task->titre }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $task->type }}</td>
                            <td class="px-4 py-3">@include('dashboard::components.status-badge', ['status' => $task->statut])</td>
                            <td class="px-4 py-3">@include('dashboard::components.priority-badge', ['priority' => $task->priorite])</td>
                            <td class="px-4 py-3 text-sm text-gray-500 text-right">{{ $task->estimation_temps ? $task->estimation_temps . 'min' : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
