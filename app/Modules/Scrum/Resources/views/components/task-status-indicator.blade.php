@props(['status', 'size' => 'sm'])

@php
    $dimensions = $size === 'md' ? 'h-4 w-4 text-[10px]' : 'h-3 w-3 text-[9px]';
@endphp

@if($status === 'open')
    <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent" aria-label="Tache en cours de developpement"></span>
@elseif($status === 'closed')
    <span class="inline-flex {{ $dimensions }} items-center justify-center rounded-full bg-emerald-600 font-bold leading-none text-white" aria-label="Tache terminee">&#10003;</span>
@else
    <span class="inline-block h-2 w-2 rounded-full bg-slate-300" aria-hidden="true"></span>
@endif
