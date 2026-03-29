<!DOCTYPE html>
<html lang="fr" class="h-full bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') - Poiesis</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full">
    <nav class="bg-white border-b border-gray-200">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-14 items-center justify-between">
                <div class="flex items-center gap-6">
                    <a href="{{ route('dashboard.projects') }}" class="text-lg font-semibold text-gray-900">Poiesis</a>
                    @hasSection('breadcrumb')
                        <div class="flex items-center gap-2 text-sm text-gray-500">
                            @yield('breadcrumb')
                        </div>
                    @endif
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-sm text-gray-500 hover:text-gray-700">Se deconnecter</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    @hasSection('tabs')
        <div class="bg-white border-b border-gray-200">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <nav class="flex gap-6 -mb-px" aria-label="Tabs">
                    @yield('tabs')
                </nav>
            </div>
        </div>
    @endif

    <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>
</body>
</html>
