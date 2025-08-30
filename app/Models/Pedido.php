<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    protected $table = 'pedido';
    protected $primaryKey = 'ped_id';
    public $timestamps = false;

    protected $fillable = [
        'fecha',
        'total',
        'monAbr',
        'monTotal', 
        'monTipoCambio',
        'img',
        'caracteristicas',
        'desglosado',
        'mostrarPrecios',
        'mostrarClaveAlterna',
        'comentario',
        'status',
        'usu_id',
        'pro_id'
    ];

    protected $casts = [
        'fecha' => 'date',
        'total' => 'decimal:2',
        'monTotal' => 'decimal:6',
        'monTipoCambio' => 'decimal:6',
        'img' => 'boolean',
        'caracteristicas' => 'boolean',
        'desglosado' => 'boolean',
        'mostrarPrecios' => 'boolean',
        'mostrarClaveAlterna' => 'boolean'
    ];

    public function detalles()
    {
        return $this->hasMany(DetallePedido::class, 'ped_id', 'ped_id');
    }
}