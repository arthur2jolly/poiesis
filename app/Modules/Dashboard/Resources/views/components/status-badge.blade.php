@props(['status'])
@php
    $colors = [
        'draft' => 'bg-gray-100 text-gray-700',
        'open' => 'bg-blue-100 text-blue-700',
        'closed' => 'bg-green-100 text-green-700',
    ];
@endphp
<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $colors[$status] ?? 'bg-gray-100 text-gray-600' }}">{{ $status }}</span>
