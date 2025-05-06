@extends('layouts.app')

@section('Cuspi', 'Página de Artículos')

@section('content')
    <div class="container">
        <h3>MATRIZ: {{$existenciaMatriz}}</h3>
        <h3>BODEGA: {{$existenciaBodega}}</h3>
        <h3>TOTAL: {{$existenciaTotal}}</h3>
    </div>
@endsection

        