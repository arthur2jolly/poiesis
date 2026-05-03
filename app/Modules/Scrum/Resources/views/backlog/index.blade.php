@extends('scrum::layout')

@section('title', 'Backlog')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">Backlog</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $stories->count() }} story(s)</p>
    </div>

    <form method="GET" class="mb-6 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-6">
        <select name="statut" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            <option value="">Tous statuts</option>
            @foreach($statuses as $option)
                <option value="{{ $option }}" @selected($filters['statut'] === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <select name="priorite" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            <option value="">Toutes priorites</option>
            @foreach(config('core.priorities') as $option)
                <option value="{{ $option }}" @selected($filters['priorite'] === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <select name="epic" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            <option value="">Tous epics</option>
            @foreach($epics as $epic)
                <option value="{{ $epic->identifier }}" @selected($filters['epic'] === $epic->identifier)>{{ $epic->identifier }}</option>
            @endforeach
        </select>
        <select name="ready" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            <option value="">Ready: tous</option>
            <option value="yes" @selected($filters['ready'] === 'yes')>Ready</option>
            <option value="no" @selected($filters['ready'] === 'no')>Non ready</option>
        </select>
        <select name="in_sprint" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            <option value="">Sprint: tous</option>
            <option value="yes" @selected($filters['in_sprint'] === 'yes')>Dans un sprint</option>
            <option value="no" @selected($filters['in_sprint'] === 'no')>Hors sprint</option>
        </select>
        <button class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white">Filtrer</button>
    </form>

    <div class="space-y-3">
        @forelse($stories as $story)
            <article class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-indigo-700">{{ $story->identifier }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium">{{ $story->statut }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium">{{ $story->priorite }}</span>
                            @if(in_array($story->id, $storiesInSprints, true))
                                <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800">sprint</span>
                            @endif
                            <span class="rounded-full {{ $story->ready ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }} px-2 py-1 text-xs font-medium">{{ $story->ready ? 'ready' : 'not ready' }}</span>
                        </div>
                        <h2 class="mt-2 font-medium">{{ $story->titre }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $story->epic->identifier }} - {{ $story->epic->titre }}</p>
                    </div>
                    <div class="text-right text-sm text-slate-600">
                        <div>Rank {{ $story->rank ?? '-' }}</div>
                        <div>{{ $story->story_points ?? '-' }} pt(s)</div>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">Aucune story dans le backlog.</div>
        @endforelse
    </div>
@endsection
