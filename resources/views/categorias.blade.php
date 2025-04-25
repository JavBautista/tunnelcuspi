@extends('layouts.app')

@section('Cuspi', 'Página de Artículos')

@section('content')
    <div class="container">
        <h1>Categorias</h1>

        <!-- Tabla de Artículos -->
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                </tr>
            </thead>
            <tbody>
                @foreach($categorias as $cat)
                    <tr>
                        <td>{{ $cat->cat_id }}</td>
                        <td>{{ $cat->nombre }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

        