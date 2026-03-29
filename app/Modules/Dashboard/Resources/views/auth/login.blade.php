<!DOCTYPE html>
<html lang="fr" class="h-full bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion - Poiesis</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center">
    <div class="w-full max-w-sm">
        <h1 class="text-2xl font-bold text-center text-gray-900 mb-8">Poiesis</h1>

        <form method="POST" action="{{ route('login') }}" class="bg-white shadow rounded-lg px-8 py-6 space-y-5">
            @csrf

            @if ($errors->any())
                <div class="text-sm text-red-600 bg-red-50 rounded p-3">
                    {{ $errors->first() }}
                </div>
            @endif

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" required autofocus
                    class="block w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Mot de passe</label>
                <input type="password" name="password" id="password" required
                    class="block w-full rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
            </div>

            <button type="submit"
                class="w-full rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Se connecter
            </button>
        </form>
    </div>
</body>
</html>
