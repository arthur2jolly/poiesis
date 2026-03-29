@extends('dashboard::layout')

@section('title', 'Stories - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <span class="text-gray-700">Stories</span>
@endsection

@section('tabs')
    @include('dashboard::components.project-tabs', ['project' => $project, 'active' => 'stories'])
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Stories</h1>
    </div>

    {{-- Filters --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6">
        <select name="statut" onchange="this.form.submit()" class="text-sm border-gray-300 rounded px-3 py-1.5 border">
            <option value="">Tous les statuts</option>
            @foreach(['draft', 'open', 'closed'] as $s)
                <option value="{{ $s }}" {{ ($filters['statut'] ?? '') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
        <select name="priorite" onchange="this.form.submit()" class="text-sm border-gray-300 rounded px-3 py-1.5 border">
            <option value="">Toutes priorites</option>
            @foreach(['critique', 'haute', 'moyenne', 'basse'] as $p)
                <option value="{{ $p }}" {{ ($filters['priorite'] ?? '') === $p ? 'selected' : '' }}>{{ $p }}</option>
            @endforeach
        </select>
        <select name="type" onchange="this.form.submit()" class="text-sm border-gray-300 rounded px-3 py-1.5 border">
            <option value="">Tous les types</option>
            @foreach(['backend', 'frontend', 'devops', 'qa'] as $t)
                <option value="{{ $t }}" {{ ($filters['type'] ?? '') === $t ? 'selected' : '' }}>{{ $t }}</option>
            @endforeach
        </select>
        @if(array_filter($filters))
            <a href="{{ route('dashboard.stories', $project->code) }}" class="text-sm text-gray-500 hover:text-gray-700 px-2 py-1.5">Effacer</a>
        @endif
    </form>

    @if($stories->isEmpty())
        <p class="text-gray-500">Aucune story.</p>
    @else
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Titre</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Epic</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Priorite</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tasks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($stories as $story)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('dashboard.story', [$project->code, $story->identifier]) }}" class="text-sm font-mono text-blue-600 hover:underline">{{ $story->identifier }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $story->titre }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('dashboard.epic', [$project->code, $story->epic->identifier]) }}" class="text-sm font-mono text-blue-600 hover:underline">{{ $story->epic->identifier }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $story->type }}</td>
                            <td class="px-4 py-3">@include('dashboard::components.status-badge', ['status' => $story->statut])</td>
                            <td class="px-4 py-3">@include('dashboard::components.priority-badge', ['priority' => $story->priorite])</td>
                            <td class="px-4 py-3 text-sm text-gray-500 text-right">{{ $story->tasks_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
