@props(['tasks'])

@if($tasks !== [])
    <details class="mt-3 rounded-md border border-slate-200 bg-slate-50">
        <summary class="cursor-pointer px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600 hover:text-slate-900">
            {{ count($tasks) }} tache(s) associee(s)
        </summary>
        <ul class="divide-y divide-slate-200 border-t border-slate-200">
            @foreach($tasks as $task)
                @include('scrum::components.task-compact-row', ['task' => $task])
            @endforeach
        </ul>
    </details>
@endif
