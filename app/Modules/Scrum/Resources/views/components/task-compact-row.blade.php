@props(['task'])

<li class="px-3 py-2 text-sm">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <span class="font-mono text-indigo-700">{{ $task['identifier'] }}</span>
            <span class="text-slate-800">{{ $task['title'] }}</span>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2 text-xs text-slate-500">
            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{ $task['status'] }}</span>
            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{ $task['priority'] }}</span>
            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{ $task['estimate'] ?? '-' }} min</span>
        </div>
    </div>
    @if($task['description'] || $task['tags'] !== [])
        <details class="mt-2">
            <summary class="cursor-pointer text-xs font-medium text-slate-500 hover:text-slate-800">Details</summary>
            @if($task['description'])
                <p class="mt-2 max-w-4xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ $task['description'] }}</p>
            @endif
            @if($task['tags'] !== [])
                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach($task['tags'] as $tag)
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs text-slate-500 ring-1 ring-slate-200">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif
        </details>
    @endif
</li>
