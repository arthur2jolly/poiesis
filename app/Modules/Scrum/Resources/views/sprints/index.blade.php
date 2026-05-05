@extends('scrum::layout')

@section('title', 'Sprints')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Sprints</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $sprints->count() }} sprint(s)</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <label for="status" class="text-sm text-slate-600">Statut</label>
            <select id="status" name="status" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                <option value="">Tous</option>
                @foreach($statuses as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
                @endforeach
            </select>
            <button class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white">Filtrer</button>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Sprint</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Dates</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Capacite</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Items</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Statut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($sprints as $sprint)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('scrum.sprint', [$project->code, $sprint->identifier]) }}" class="font-medium text-indigo-700 hover:text-indigo-900">{{ $sprint->identifier }}</a>
                            <div class="text-sm text-slate-600">{{ $sprint->name }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $sprint->start_date->toDateString() }} au {{ $sprint->end_date->toDateString() }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $sprint->capacity ?? 'Non definie' }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600">{{ $sprint->items_count }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">{{ $sprint->status }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">Aucun sprint.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
