<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    protected $table = 'proveedor';
    protected $primaryKey = 'pro_id';
    public $timestamps = false;
    
    protected $fillable = [
        'nombre', 'representante', 'alias', 'domicilio', 'noExt', 'noInt',
        'localidad', 'ciudad', 'estado', 'pais', 'codigoPostal', 'colonia',
        'rfc', 'curp', 'telefono', 'celular', 'mail', 'comentario',
        'status', 'limite', 'diasCredito', 'foto'
    ];
    
    protected $casts = [
        'status' => 'integer',
        'limite' => 'decimal:2',
        'diasCredito' => 'integer'
    ];
    
    // Relación muchos a muchos con Articulo a través de ProveedorArticulo
    public function articulos()
    {
        return $this->belongsToMany(
            Articulo::class, 
            'proveedorarticulo', 
            'pro_id', 
            'art_id'
        )->withPivot('claveProveedor', 'precioCompra', 'fecha');
    }
    
    // Relación directa con ProveedorArticulo
    public function proveedorArticulos()
    {
        return $this->hasMany(ProveedorArticulo::class, 'pro_id', 'pro_id');
    }
}
