<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Articulo;
use App\Models\Categoria;
class ArticuloController extends Controller
{
    public function index(){   
        $articulos = Articulo::limit(5)->get();
        //dd($articulos);
        return view('articulos',['articulos'=>$articulos]);
    }

    public function getCategotias(){   
        $categorias = Categoria::limit(5)->get();
        //dd($articulos);
        return view('categorias',['categorias'=>$categorias]);
    }


}
