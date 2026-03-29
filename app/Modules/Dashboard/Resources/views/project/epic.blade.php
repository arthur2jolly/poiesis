@extends('dashboard::layout')

@section('title', $epic->identifier . ' - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <a href="{{ route('dashboard.epics', $project->code) }}" class="hover:text-gray-700">Epics</a>
    <span>/</span>
    <span class="text-gray-700">{{ $epic->identifier }}</span>
@endsection

@section('tabs')
    @include('dashboard::components.project-tabs', ['project' => $project, 'active' => 'epics'])
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <div class="flex items-center gap-3 mb-2">
            <span class="text-sm font-mono font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">{{ $epic->identifier }}</span>
        </div>
        <h1 class="text-xl font-semibold text-gray-900 mb-2">{{ $epic->titre }}</h1>
        @if($epic->description)
            <p class="text-gray-600">{{ $epic->description }}</p>
        @endif
    </div>

    <h2 class="text-lg font-semibold text-gray-900 mb-4">Stories ({{ $epic->stories->count() }})</h2>

    @if($epic->stories->isEmpty())
        <p class="text-gray-500">Aucune story.</p>
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
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tasks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($epic->stories as $story)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('dashboard.story', [$project->code, $story->identifier]) }}" class="text-sm font-mono text-blue-600 hover:underline">{{ $story->identifier }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $story->titre }}</td>
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
