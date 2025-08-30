<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProveedorArticulo extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    protected $table = 'proveedorarticulo';
    public $timestamps = false;
    
    // Llave compuesta
    protected $primaryKey = ['pro_id', 'art_id'];
    public $incrementing = false;
    
    protected $fillable = [
        'pro_id', 'art_id', 'claveProveedor', 'precioCompra', 'fecha'
    ];
    
    protected $casts = [
        'pro_id' => 'integer',
        'art_id' => 'integer',
        'precioCompra' => 'decimal:6',
        'fecha' => 'datetime'
    ];
    
    // Relación con Proveedor
    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'pro_id', 'pro_id');
    }
    
    // Relación con Articulo
    public function articulo()
    {
        return $this->belongsTo(Articulo::class, 'art_id', 'art_id');
    }
}
