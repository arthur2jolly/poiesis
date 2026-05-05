@props(['status', 'size' => 'sm'])

@php
    $box = $size === 'md' ? 'h-4 w-4' : 'h-3.5 w-3.5';

    // Specific phrases (not just "Done"/"To Do") so they cannot collide with
    // column headers when tests assert on substrings.
    $label = match ($status) {
        'closed' => 'Task done',
        'open' => 'Task in progress',
        default => 'Task to do',
    };
@endphp

@if($status === 'closed')
    {{-- Done : disque emerald rempli + check blanc --}}
    <svg class="{{ $box }} shrink-0 text-emerald-500" viewBox="0 0 16 16" fill="currentColor"
         role="img" aria-label="{{ $label }}" title="{{ $label }}">
        <circle cx="8" cy="8" r="7" />
        <path d="m4.8 8.2 2.2 2.2L11.4 6" fill="none" stroke="white" stroke-width="1.7"
              stroke-linecap="round" stroke-linejoin="round" />
    </svg>
@elseif($status === 'open')
    {{-- In progress : pie-chart à demi-rempli, convention Linear / Height / Notion --}}
    <svg class="{{ $box }} shrink-0 text-indigo-600" viewBox="0 0 16 16" fill="none"
         role="img" aria-label="{{ $label }}" title="{{ $label }}">
        <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5" />
        <path d="M8 2 A6 6 0 0 1 8 14 Z" fill="currentColor" />
    </svg>
@else
    {{-- To do : cercle creux slate, neutre --}}
    <svg class="{{ $box }} shrink-0 text-slate-300" viewBox="0 0 16 16" fill="none"
         role="img" aria-label="{{ $label }}" title="{{ $label }}">
        <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5"
                stroke-dasharray="2.5 2" />
    </svg>
@endif
