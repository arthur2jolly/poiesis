@extends('dashboard::layout')

@section('title', 'Kanban - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <span class="text-gray-700">Kanban</span>
@endsection

@section('tabs')
    @include('dashboard::components.project-tabs', ['project' => $project, 'active' => 'kanban'])
@endsection

@section('content')
    <h1 class="text-xl font-semibold text-gray-900 mb-6">Kanban Boards</h1>

    @if($boards->isEmpty())
        <p class="text-gray-500">Aucun board Kanban.</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($boards as $board)
                <a href="{{ route('dashboard.kanban.board', [$project->code, $board->id]) }}"
                   class="block bg-white rounded-lg shadow-sm border border-gray-200 p-5 hover:border-blue-300 hover:shadow transition">
                    <h2 class="font-semibold text-gray-900 mb-2">{{ $board->name }}</h2>
                    <div class="flex flex-wrap gap-2 mb-3">
                        @foreach($board->columns as $col)
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ $col->name }} ({{ $col->boardTasks->count() }})</span>
                        @endforeach
                    </div>
                    <div class="text-xs text-gray-400">{{ $board->columns->count() }} colonnes</div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
