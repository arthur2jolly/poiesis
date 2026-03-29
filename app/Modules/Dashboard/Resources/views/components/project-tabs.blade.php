@props(['project', 'active'])
@php
    $hasDocuments = in_array('document', $project->modules ?? [], true);
    $tabs = [
        ['route' => route('dashboard.project', $project->code), 'label' => "Vue d'ensemble", 'key' => 'overview'],
        ['route' => route('dashboard.epics', $project->code), 'label' => 'Epics', 'key' => 'epics'],
        ['route' => route('dashboard.stories', $project->code), 'label' => 'Stories', 'key' => 'stories'],
        ['route' => route('dashboard.tasks', $project->code), 'label' => 'Tasks', 'key' => 'tasks'],
    ];
    if ($hasDocuments) {
        $tabs[] = ['route' => route('dashboard.documents', $project->code), 'label' => 'Documents', 'key' => 'documents'];
    }
    $hasKanban = in_array('kanban', $project->modules ?? [], true);
    if ($hasKanban) {
        $tabs[] = ['route' => route('dashboard.kanban', $project->code), 'label' => 'Kanban', 'key' => 'kanban'];
    }
@endphp
@foreach($tabs as $tab)
    <a href="{{ $tab['route'] }}" class="border-b-2 {{ $active === $tab['key'] ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }} px-1 py-3 text-sm font-medium">{{ $tab['label'] }}</a>
@endforeach
