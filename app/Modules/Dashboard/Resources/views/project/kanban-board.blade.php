@extends('dashboard::layout')

@section('title', $board->name . ' - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <a href="{{ route('dashboard.kanban', $project->code) }}" class="hover:text-gray-700">Kanban</a>
    <span>/</span>
    <span class="text-gray-700">{{ $board->name }}</span>
@endsection

@section('tabs')
    @include('dashboard::components.project-tabs', ['project' => $project, 'active' => 'kanban'])
@endsection

@section('content')
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $board->name }}</h1>

    <div class="flex gap-4 overflow-x-auto pb-4">
        @foreach($board->columns as $column)
            @php
                $count = $column->boardTasks->count();
                $atWarning = $column->limit_warning !== null && $count >= $column->limit_warning;
                $atHard = $column->limit_hard !== null && $count >= $column->limit_hard;
            @endphp
            <div class="flex-shrink-0 w-72 bg-gray-100 rounded-lg">
                {{-- Column header --}}
                <div class="px-3 py-2 border-b border-gray-200 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-700">{{ $column->name }}</span>
                        <span class="text-xs text-gray-400">({{ $count }})</span>
                    </div>
                    <div class="flex items-center gap-1">
                        @if($column->limit_warning !== null)
                            <span class="text-xs px-1.5 py-0.5 rounded {{ $atWarning ? 'bg-orange-100 text-orange-700' : 'bg-gray-200 text-gray-500' }}">W:{{ $column->limit_warning }}</span>
                        @endif
                        @if($column->limit_hard !== null)
                            <span class="text-xs px-1.5 py-0.5 rounded {{ $atHard ? 'bg-red-100 text-red-700' : 'bg-gray-200 text-gray-500' }}">H:{{ $column->limit_hard }}</span>
                        @endif
                    </div>
                </div>
                {{-- Cards --}}
                <div class="p-2 space-y-2 min-h-[4rem]">
                    @foreach($column->boardTasks as $bt)
                        <div class="bg-white rounded border border-gray-200 p-3 shadow-sm">
                            <div class="flex items-center justify-between mb-1">
                                <a href="{{ route('dashboard.task', [$project->code, $bt->task->identifier]) }}" class="text-xs font-mono text-blue-600 hover:underline">{{ $bt->task->identifier }}</a>
                                @include('dashboard::components.priority-badge', ['priority' => $bt->task->priorite])
                            </div>
                            <div class="text-sm text-gray-900">{{ $bt->task->titre }}</div>
                            @if($bt->task->type)
                                <div class="mt-1 text-xs text-gray-400">{{ $bt->task->type }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endsection
