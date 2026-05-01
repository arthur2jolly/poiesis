@extends('dashboard::layout')

@section('title', $document->identifier . ' - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <a href="{{ route('dashboard.documents', $project->code) }}" class="hover:text-gray-700">Documents</a>
    <span>/</span>
    <span class="text-gray-700">{{ $document->identifier }}</span>
@endsection

@section('tabs')
    @include('dashboard::components.project-tabs', ['project' => $project, 'active' => 'documents'])
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <div class="flex items-center gap-3 mb-3">
            <span class="text-sm font-mono font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">{{ $document->identifier }}</span>
            @include('dashboard::components.status-badge', ['status' => $document->status])
            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ $document->type }}</span>
        </div>
        <h1 class="text-xl font-semibold text-gray-900 mb-2">{{ $document->title }}</h1>
        @if($document->summary)
            <p class="text-gray-600 mb-4">{{ $document->summary }}</p>
        @endif

        @if($document->tags)
            <div class="flex flex-wrap gap-1">
                @foreach($document->tags as $tag)
                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ $tag }}</span>
                @endforeach
            </div>
        @endif
    </div>

    @if($document->content)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-sm font-semibold text-gray-700 mb-4">Contenu</h2>
            @include('dashboard::components.markdown', ['source' => $document->content, 'class' => 'text-gray-700'])
        </div>
    @endif
@endsection
