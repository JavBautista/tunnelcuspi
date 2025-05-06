<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Articulo;
use App\Models\Categoria;
use Illuminate\Support\Facades\DB;


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

    public function articuloEx(){   
        
        $clave = 'AE25000'; // por ejemplo

        $existenciaMatriz = DB::table('articulo')
            ->where('clave', $clave)
            ->value('existencia');

        $existenciaBodega = DB::connection('bodega')
            ->table('articulo')
            ->where('clave', $clave)
            ->value('existencia');

        $existenciaTotal = floatval($existenciaMatriz) + floatval($existenciaBodega);

        //dd($articulos);
        return view('articulo_ex',[
            'existenciaMatriz'=>$existenciaMatriz,
            'existenciaBodega'=>$existenciaBodega,
            'existenciaTotal'=>$existenciaTotal,
        ]);
    }


}
