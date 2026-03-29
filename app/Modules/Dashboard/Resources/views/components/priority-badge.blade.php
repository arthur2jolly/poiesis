@props(['priority'])
@php
    $colors = [
        'critique' => 'bg-red-100 text-red-700',
        'haute' => 'bg-orange-100 text-orange-700',
        'moyenne' => 'bg-yellow-100 text-yellow-800',
        'basse' => 'bg-gray-100 text-gray-500',
    ];
@endphp
<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $colors[$priority] ?? 'bg-gray-100 text-gray-600' }}">{{ $priority }}</span>
