@extends('dashboard::layout')

@section('title', 'Epics - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <span class="text-gray-700">Epics</span>
@endsection

@section('tabs')
    <a href="{{ route('dashboard.project', $project->code) }}" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 py-3 text-sm font-medium">Vue d'ensemble</a>
    <a href="{{ route('dashboard.epics', $project->code) }}" class="border-b-2 border-blue-500 text-blue-600 px-1 py-3 text-sm font-medium">Epics</a>
    <a href="{{ route('dashboard.stories', $project->code) }}" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 py-3 text-sm font-medium">Stories</a>
    <a href="{{ route('dashboard.tasks', $project->code) }}" class="border-b-2 border-transparent text-gray-500 hover:text-gray-700 px-1 py-3 text-sm font-medium">Tasks</a>
@endsection

@section('content')
    <h1 class="text-xl font-semibold text-gray-900 mb-6">Epics</h1>

    @if($epics->isEmpty())
        <p class="text-gray-500">Aucune epic.</p>
    @else
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
    @endif
@endsection
