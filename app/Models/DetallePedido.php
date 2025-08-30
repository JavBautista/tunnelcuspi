<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetallePedido extends Model
{
    protected $table = 'detalleped';
    protected $primaryKey = ['ped_id', 'art_id'];
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'ped_id',
        'art_id',
        'clave',
        'descripcion', 
        'cantidad',
        'unidad',
        'precioCompra',
        'importeCompra',
        'monPrecioCompra',
        'monImporteCompra',
        'orden'
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'precioCompra' => 'decimal:6',
        'importeCompra' => 'decimal:2',
        'monPrecioCompra' => 'decimal:6',
        'monImporteCompra' => 'decimal:6'
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'ped_id', 'ped_id');
    }
}