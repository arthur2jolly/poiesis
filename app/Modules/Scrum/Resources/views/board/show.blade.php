@extends('scrum::layout')

@section('title', 'Board')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Board Scrum</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $sprint ? $sprint->identifier.' - '.$sprint->name : 'Aucun sprint actif selectionne.' }}
            </p>
        </div>
        @if($sprint && $sprint->status !== 'active')
            {{-- Affordance pour revenir au sprint actif uniquement quand on consulte un sprint historique --}}
            <div>
                <a href="{{ route('scrum.board', $project->code) }}" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Sprint actif</a>
            </div>
        @endif
    </div>

    @if($columns->isEmpty())
        <div class="rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">Le board Scrum n'est pas encore configure.</div>
    @else
        <div class="grid gap-4 lg:grid-cols-{{ min(max($columns->count(), 1), 4) }}">
            @foreach($columns as $column)
                @php
                    $placements = $column->placements
                        ->filter(fn ($placement) => $sprint !== null && $placement->sprintItem->sprint_id === $sprint->id)
                        ->filter(fn ($placement) => $placement->sprintItem->artifact?->artifactable instanceof \App\Core\Models\Story)
                        ->filter(fn ($placement) => $placement->sprintItem->artifact->artifactable->ready === true);
                @endphp
                <section class="rounded-lg border border-slate-200 bg-white">
                    <header class="border-b border-slate-200 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="font-semibold">{{ $column->name }}</h2>
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium">{{ $placements->count() }} item(s)</span>
                        </div>
                        @if($column->limit_warning || $column->limit_hard)
                            <p class="mt-1 text-xs text-slate-500">Limites: warning {{ $column->limit_warning ?? '-' }}, hard {{ $column->limit_hard ?? '-' }}</p>
                        @endif
                    </header>
                    <div class="space-y-3 p-3">
                        @forelse($placements as $placement)
                            @php
                                $artifact = $placement->sprintItem->artifact;
                                $item = $artifact?->artifactable;
                            @endphp
                            <article class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <div class="text-sm font-semibold text-indigo-700">{{ $artifact?->identifier }}</div>
                                <div class="mt-1 text-sm font-medium">{{ $item?->titre }}</div>
                                <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
                                    <span>{{ $placement->sprintItem->sprint->identifier }}</span>
                                    @if($item instanceof \App\Core\Models\Story)
                                        <span>{{ $item->story_points ?? '-' }} pt(s)</span>
                                        <span>{{ $item->ready ? 'ready' : 'not ready' }}</span>
                                    @endif
                                    @if($item instanceof \App\Core\Models\Story || $item instanceof \App\Core\Models\Task)
                                        <span>{{ $item->statut }}</span>
                                    @endif
                                </div>
                                @if($item instanceof \App\Core\Models\Story)
                                    @include('scrum::components.story-task-bullets', ['story' => $item])
                                @endif
                            </article>
                        @empty
                            <p class="py-4 text-center text-sm text-slate-500">Aucun item.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    @endif
@endsection
