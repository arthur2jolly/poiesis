@props(['story'])

@php
    $tasks = $story->tasks ?? collect();
@endphp

<div class="mt-3 border-t border-slate-200 pt-3">
    <div class="text-xs font-semibold uppercase text-slate-500">Taches</div>
    @if($tasks->isEmpty())
        <p class="mt-2 text-xs text-slate-500">Aucune tache definie.</p>
    @else
        <ul class="mt-2 space-y-1 text-xs text-slate-700">
            @foreach($tasks as $task)
                <li>
                    <span class="inline-flex items-center gap-1.5">
                        @include('scrum::components.task-status-indicator', ['status' => $task->statut, 'isStarted' => $task->isStarted()])
                        <span class="font-medium text-slate-900">{{ $task->identifier }}</span>
                        <span>{{ $task->titre }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
