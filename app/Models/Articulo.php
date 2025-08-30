<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Articulo extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    protected $table = 'articulo';
    protected $primaryKey = 'art_id';
    public $timestamps = false;
    
    // Relación muchos a muchos con Proveedor a través de ProveedorArticulo
    public function proveedores()
    {
        return $this->belongsToMany(
            Proveedor::class, 
            'proveedorarticulo', 
            'art_id', 
            'pro_id'
        )->withPivot('claveProveedor', 'precioCompra', 'fecha');
    }
    
    // Relación directa con ProveedorArticulo
    public function proveedorArticulos()
    {
        return $this->hasMany(ProveedorArticulo::class, 'art_id', 'art_id');
    }
}
