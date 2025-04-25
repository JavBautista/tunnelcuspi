<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Mi Aplicación')</title>
    <!-- Vincula Bootstrap desde tu compilado de Laravel Mix -->
    <link href="{{ mix('css/app.css') }}" rel="stylesheet">
</head>
<body>
    <div id="app">
        <!-- Aquí se insertarán las vistas -->
        @yield('content')
    </div>

    <!-- Scripts generados por Laravel Mix -->
    <script src="{{ mix('js/app.js') }}"></script>
</body>
</html>
