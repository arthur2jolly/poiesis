@extends('dashboard::layout')

@section('title', $project->titre)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
@endsection

@section('tabs')
    <a href="{{ route('dashboard.project', $project->code) }}" class="border-b-2 border-blue-500 text-blue-600 px-1 py-3 text-sm font-medium">Vue d'ensemble</a>
    <a href="{{ route('dashboard.epics', $project->code) }}" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 py-3 text-sm font-medium">Epics</a>
    <a href="{{ route('dashboard.stories', $project->code) }}" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 py-3 text-sm font-medium">Stories</a>
    <a href="{{ route('dashboard.tasks', $project->code) }}" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 py-3 text-sm font-medium">Tasks</a>
@endsection

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Project info --}}
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h1 class="text-xl font-semibold text-gray-900 mb-2">{{ $project->titre }}</h1>
                @if($project->description)
                    <p class="text-gray-600 mb-4">{{ $project->description }}</p>
                @endif

                @if(!empty($project->modules))
                    <div class="flex flex-wrap gap-2 mb-4">
                        @foreach($project->modules as $module)
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ $module }}</span>
                        @endforeach
                    </div>
                @endif

                <h2 class="text-sm font-semibold text-gray-700 mb-2 mt-6">Membres</h2>
                <div class="space-y-1">
                    @foreach($members as $member)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-700">{{ $member->user->name }}</span>
                            <span class="text-xs text-gray-400">{{ $member->position }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Stats --}}
        <div class="space-y-4">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <div class="text-2xl font-bold text-gray-900">{{ $project->epics_count }}</div>
                <div class="text-sm text-gray-500">Epics</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <div class="text-2xl font-bold text-blue-600">{{ $openStoriesCount }}</div>
                <div class="text-sm text-gray-500">Stories ouvertes</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <div class="text-2xl font-bold text-gray-900">{{ $project->tasks_count }}</div>
                <div class="text-sm text-gray-500">Tasks totales</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <div class="text-2xl font-bold text-gray-400">{{ $project->standalone_tasks_count }}</div>
                <div class="text-sm text-gray-500">Tasks autonomes</div>
            </div>
        </div>
    </div>

    {{-- Recent epics --}}
    @if($epics->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Epics recentes</h2>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Titre</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Stories</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($epics as $epic)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('dashboard.epic', [$project->code, $epic->identifier]) }}" class="text-sm font-mono text-blue-600 hover:underline">{{ $epic->identifier }}</a>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900">{{ $epic->titre }}</td>
                                <td class="px-4 py-3 text-sm text-gray-500 text-right">{{ $epic->stories_count }}</td>
                                <td class="px-4 py-3 text-sm text-gray-400 text-right">{{ $epic->created_at->format('d/m/Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
