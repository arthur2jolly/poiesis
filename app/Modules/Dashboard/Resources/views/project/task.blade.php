@extends('dashboard::layout')

@section('title', $task->identifier . ' - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <a href="{{ route('dashboard.tasks', $project->code) }}" class="hover:text-gray-700">Tasks</a>
    <span>/</span>
    <span class="text-gray-700">{{ $task->identifier }}</span>
@endsection

@section('tabs')
    @include('dashboard::components.project-tabs', ['project' => $project, 'active' => 'tasks'])
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center gap-3 mb-3">
            <span class="text-sm font-mono font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">{{ $task->identifier }}</span>
            @include('dashboard::components.status-badge', ['status' => $task->statut])
            @include('dashboard::components.priority-badge', ['priority' => $task->priorite])
        </div>
        <h1 class="text-xl font-semibold text-gray-900 mb-2">{{ $task->titre }}</h1>
        @if($task->description)
            @include('dashboard::components.markdown', ['source' => $task->description, 'class' => 'text-gray-600 mb-4'])
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="text-gray-400">Type</span>
                <div class="text-gray-900">{{ $task->type }}</div>
            </div>
            <div>
                <span class="text-gray-400">Nature</span>
                <div class="text-gray-900">{{ $task->nature ?? '-' }}</div>
            </div>
            <div>
                <span class="text-gray-400">Estimation</span>
                <div class="text-gray-900">{{ $task->estimation_temps ? $task->estimation_temps . ' min' : '-' }}</div>
            </div>
            <div>
                <span class="text-gray-400">Story</span>
                <div>
                    @if($task->story)
                        <a href="{{ route('dashboard.story', [$project->code, $task->story->identifier]) }}" class="text-blue-600 hover:underline font-mono">{{ $task->story->identifier }}</a>
                    @else
                        <span class="text-gray-500">Autonome</span>
                    @endif
                </div>
            </div>
        </div>

        @if($task->tags)
            <div class="mt-4 flex flex-wrap gap-1">
                @foreach($task->tags as $tag)
                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ $tag }}</span>
                @endforeach
            </div>
        @endif

        @php
            $blockedBy = $task->blockedBy();
            $blocks = $task->blocks();
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
@endsection
