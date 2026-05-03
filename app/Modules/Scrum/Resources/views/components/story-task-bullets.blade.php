@props(['story'])

@php
    $tasks = $story->tasks ?? collect();
@endphp

<div class="mt-3 border-t border-slate-200 pt-3">
    <div class="text-xs font-semibold uppercase text-slate-500">Taches</div>
    @if($tasks->isEmpty())
        <p class="mt-2 text-xs text-slate-500">Aucune tache definie.</p>
    @else
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-700">
            @foreach($tasks as $task)
                <li>
                    <span class="inline-flex items-center gap-1.5">
                        @if($task->statut === 'open')
                            <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent" aria-label="Tache en cours de developpement"></span>
                        @endif
                        <span class="font-medium text-slate-900">{{ $task->identifier }}</span>
                        <span>{{ $task->titre }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
