@props(['status', 'isStarted' => false, 'size' => 'sm'])

@php
    $box = $size === 'md' ? 'h-4 w-4' : 'h-3.5 w-3.5';

    // Specific phrases (not just "Done"/"To Do") so they cannot collide with
    // column headers when tests assert on substrings.
    // POIESIS-107: 'Task in progress' is now reserved to started + non-closed.
    $label = match (true) {
        $status === 'closed' => 'Task done',
        $isStarted => 'Task in progress',
        $status === 'open' => 'Task ready',
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
@elseif($isStarted)
    {{-- In progress (POIESIS-107): spinner indigo. animate-spin tourne par défaut,
         motion-reduce:animate-none stoppe l'animation pour les utilisateurs
         qui ont demandé prefers-reduced-motion. La forme reste lisible immobile. --}}
    <svg class="{{ $box }} shrink-0 animate-spin text-indigo-600 motion-reduce:animate-none"
         viewBox="0 0 16 16" fill="none"
         role="img" aria-label="{{ $label }}" title="{{ $label }}">
        <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5" stroke-opacity="0.25" />
        <path d="M8 1.75 A6.25 6.25 0 0 1 14.25 8" stroke="currentColor" stroke-width="1.7"
              stroke-linecap="round" />
    </svg>
@elseif($status === 'open')
    {{-- Ready (open + not started yet): cercle creux slate, plein trait. --}}
    <svg class="{{ $box }} shrink-0 text-slate-400" viewBox="0 0 16 16" fill="none"
         role="img" aria-label="{{ $label }}" title="{{ $label }}">
        <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5" />
    </svg>
@else
    {{-- To do (draft + not started): cercle creux pointillé slate, neutre. --}}
    <svg class="{{ $box }} shrink-0 text-slate-300" viewBox="0 0 16 16" fill="none"
         role="img" aria-label="{{ $label }}" title="{{ $label }}">
        <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5"
                stroke-dasharray="2.5 2" />
    </svg>
@endif
