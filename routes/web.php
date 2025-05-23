<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/articulo-existencias', [App\Http\Controllers\ArticuloController::class, 'articuloEx'])->name('articulos-ex');

Route::get('/articulos', [App\Http\Controllers\ArticuloController::class, 'index'])->name('articulos');
Route::get('/categorias', [App\Http\Controllers\ArticuloController::class, 'getCategotias'])->name('categorias');