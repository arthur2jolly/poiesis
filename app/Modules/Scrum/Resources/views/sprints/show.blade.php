@extends('scrum::layout')

@section('title', $sprint->identifier)

@section('content')
    <div class="mb-6 flex flex-wrap justify-between gap-4">
        <div>
            <a href="{{ route('scrum.sprints', $project->code) }}" class="text-sm text-indigo-700 hover:text-indigo-900">Retour aux sprints</a>
            <h1 class="mt-2 text-2xl font-semibold">{{ $sprint->identifier }} - {{ $sprint->name }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ $sprint->goal ?? 'Aucun objectif defini.' }}</p>
        </div>
        <div class="text-right text-sm text-slate-600">
            <div>{{ $sprint->start_date->toDateString() }} au {{ $sprint->end_date->toDateString() }}</div>
            <div>{{ $sprint->items_count }} item(s), capacite {{ $sprint->capacity ?? 'non definie' }}</div>
            <span class="mt-2 inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-medium">{{ $sprint->status }}</span>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Position</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Item</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Points</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Ready</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($items as $item)
                    <tr>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $item['position'] }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $item['identifier'] }}</div>
                            <div class="text-sm text-slate-600">{{ $item['title'] }}</div>
                            @include('scrum::components.story-task-summary', ['tasks' => $item['tasks']])
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $item['kind'] }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $item['status'] }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $item['points'] ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $item['ready'] === null ? '-' : ($item['ready'] ? 'oui' : 'non') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">Aucun item dans ce sprint.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
