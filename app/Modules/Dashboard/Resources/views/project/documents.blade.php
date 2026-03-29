@extends('dashboard::layout')

@section('title', 'Documents - ' . $project->code)

@section('breadcrumb')
    <span>/</span>
    <a href="{{ route('dashboard.project', $project->code) }}" class="hover:text-gray-700">{{ $project->code }}</a>
    <span>/</span>
    <span class="text-gray-700">Documents</span>
@endsection

@section('tabs')
    @include('dashboard::components.project-tabs', ['project' => $project, 'active' => 'documents'])
@endsection

@section('content')
    <h1 class="text-xl font-semibold text-gray-900 mb-6">Documents</h1>

    @if($documents->isEmpty())
        <p class="text-gray-500">Aucun document.</p>
    @else
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Titre</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Taille</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Maj</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($documents as $doc)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('dashboard.document', [$project->code, $doc->identifier]) }}" class="text-sm font-mono text-blue-600 hover:underline">{{ $doc->identifier }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $doc->title }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $doc->type }}</td>
                            <td class="px-4 py-3">@include('dashboard::components.status-badge', ['status' => $doc->status])</td>
                            <td class="px-4 py-3 text-sm text-gray-400 text-right">{{ number_format(mb_strlen($doc->content ?? '')) }} car.</td>
                            <td class="px-4 py-3 text-sm text-gray-400 text-right">{{ $doc->updated_at->format('d/m/Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
