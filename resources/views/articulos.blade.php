@extends('layouts.app')

@section('Cuspi', 'Página de Artículos')

@section('content')
    <div class="container">
        <a href="{{ route('categorias') }}"class="btn btn-primary">Categorias</a>
        <hr>
        <h1>Artículos</h1>

        <!-- Tabla de Artículos -->
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Clave</th>
                    <th>Clave Alterna</th>
                    <th>Descripción</th>
                    <th>Servicio</th>
                    <th>Localización</th>
                    <th>Inventario Mínimo</th>
                    <th>Inventario Máximo</th>
                    <th>Factor</th>
                    <th>Precio Compra</th>
                    <th>Precio Compra Prom.</th>
                    <th>Margen 1</th>
                    <th>Margen 2</th>
                    <th>Margen 3</th>
                    <th>Margen 4</th>
                    <th>Precio 1</th>
                    <th>Precio 2</th>
                    <th>Precio 3</th>
                    <th>Precio 4</th>
                    <th>Mayoreo 1</th>
                    <th>Mayoreo 2</th>
                    <th>Mayoreo 3</th>
                    <th>Mayoreo 4</th>
                </tr>
            </thead>
            <tbody>
                @foreach($articulos as $articulo)
                    <tr>
                        <td>{{ $articulo->clave }}</td>
                        <td>{{ $articulo->claveAlterna }}</td>
                        <td>{{ $articulo->descripcion }}</td>
                        <td>{{ $articulo->servicio }}</td>
                        <td>{{ $articulo->localizacion }}</td>
                        <td>{{ $articulo->invMin }}</td>
                        <td>{{ $articulo->invMax }}</td>
                        <td>{{ $articulo->factor }}</td>
                        <td>${{ number_format($articulo->precioCompra, 2) }}</td>
                        <td>${{ number_format($articulo->preCompraProm, 2) }}</td>
                        <td>{{ $articulo->margen1 }}%</td>
                        <td>{{ $articulo->margen2 }}%</td>
                        <td>{{ $articulo->margen3 }}%</td>
                        <td>{{ $articulo->margen4 }}%</td>
                        <td>${{ number_format($articulo->precio1, 2) }}</td>
                        <td>${{ number_format($articulo->precio2, 2) }}</td>
                        <td>${{ number_format($articulo->precio3, 2) }}</td>
                        <td>${{ number_format($articulo->precio4, 2) }}</td>
                        <td>${{ number_format($articulo->mayoreo1, 2) }}</td>
                        <td>${{ number_format($articulo->mayoreo2, 2) }}</td>
                        <td>${{ number_format($articulo->mayoreo3, 2) }}</td>
                        <td>${{ number_format($articulo->mayoreo4, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

        