@extends('dashboard::layout')

@section('title', 'Projets')

@section('content')
    <h1 class="text-xl font-semibold text-gray-900 mb-6">Projets</h1>

    @if($projects->isEmpty())
        <p class="text-gray-500">Aucun projet accessible.</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($projects as $project)
                <a href="{{ route('dashboard.project', $project->code) }}"
                   class="block bg-white rounded-lg shadow-sm border border-gray-200 p-5 hover:border-blue-300 hover:shadow transition">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-mono font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">{{ $project->code }}</span>
                    </div>
                    <h2 class="font-semibold text-gray-900 mb-1">{{ $project->titre }}</h2>
                    @if($project->description)
                        @include('dashboard::components.markdown', [
                            'source' => $project->description,
                            'plain' => true,
                            'class' => 'text-sm text-gray-500 line-clamp-2',
                        ])
                    @endif
                    <div class="mt-3 flex gap-4 text-xs text-gray-400">
                        <span>{{ $project->epics_count }} epics</span>
                        <span>{{ $project->tasks_count }} tasks</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
