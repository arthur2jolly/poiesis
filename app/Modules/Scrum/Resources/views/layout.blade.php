<!DOCTYPE html>
<html lang="fr" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="30">
    <title>@yield('title', 'Scrum') - Poiesis</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full text-slate-900">
    <nav class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex h-14 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-5">
                <a href="{{ route('scrum.sprints', $project->code) }}" class="text-lg font-semibold">Poiesis Scrum</a>
                <span class="text-sm text-slate-500">{{ $project->code }}</span>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm text-slate-600">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-slate-500 hover:text-slate-900">Se deconnecter</button>
                </form>
            </div>
        </div>
    </nav>

    <div class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <nav class="flex gap-6" aria-label="Scrum">
                @if(in_array('dashboard', $project->modules ?? [], true))
                    <a href="{{ route('dashboard.project', $project->code) }}" class="border-b-2 border-transparent px-1 py-3 text-sm font-medium text-slate-500 hover:text-slate-900">Projet</a>
                @endif
                <a href="{{ route('scrum.sprints', $project->code) }}" class="border-b-2 px-1 py-3 text-sm font-medium {{ request()->routeIs('scrum.sprints') || request()->routeIs('scrum.sprint') ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-900' }}">Sprints</a>
                <a href="{{ route('scrum.backlog', $project->code) }}" class="border-b-2 px-1 py-3 text-sm font-medium {{ request()->routeIs('scrum.backlog') ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-900' }}">Backlog</a>
                <a href="{{ route('scrum.board', $project->code) }}" class="border-b-2 px-1 py-3 text-sm font-medium {{ request()->routeIs('scrum.board') || request()->routeIs('scrum.board.sprint') ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-900' }}">Board</a>
            </nav>
        </div>
    </div>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @yield('content')
    </main>
</body>
</html>
