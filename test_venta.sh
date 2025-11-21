#!/bin/bash

# Script de prueba: Insertar venta en SICAR desde TUNNEL
# Datos reales de la BD SICAR

echo "=========================================="
echo "PRUEBA: Inserción de Venta en SICAR"
echo "=========================================="
echo ""
echo "Datos de prueba:"
echo "  - Cliente: cli_id=1 (PÚBLICO EN GENERAL)"
echo "  - Artículo: art_id=2 (PH51310 - Portarrollo Altera Mini Humo)"
echo "  - Precio: \$244.50"
echo "  - IVA 16%: \$39.12"
echo "  - Total: \$283.62"
echo "  - Forma de pago: Efectivo"
echo ""
echo "Ejecutando..."
echo ""

curl -X POST http://tunnelcuspi.test/api/sicar/ventas/store \
  -H "API-KEY: uN4gFh7!rT3@kLp98#Qwz" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "venta": {
      "fecha": "2025-11-20 15:30:00",
      "subtotal0": 244.50,
      "subtotal": 244.50,
      "descuento": 0.00,
      "total": 283.62,
      "cambio": 0.00,
      "comentario": "Venta de prueba desde TUNNEL - TEST",
      "status": 1,
      "cli_id": 1,
      "usu_id": 1,
      "suc_id": 1,
      "caj_id": 1,
      "mon_id": 1,
      "vnd_id": null,
      "tipoCambio": 1.000000
    },
    "detalles": [
      {
        "art_id": 2,
        "clave": "PH51310",
        "descripcion": "Portarrollo Altera Mini Humo",
        "cantidad": 1.0000,
        "unidad": "PZA",
        "precioSin": 244.50,
        "precioCon": 283.62,
        "importeSin": 244.50,
        "importeCon": 283.62,
        "descPorcentaje": 0.00,
        "descTotal": 0.00,
        "precioCompra": 163.00,
        "orden": 1
      }
    ],
    "detallesImpuestos": [
      {
        "art_id": 2,
        "imp_id": 1,
        "base": 244.50,
        "tasa": 16.00,
        "importe": 39.12
      }
    ],
    "impuestos": [
      {
        "imp_id": 1,
        "base": 244.50,
        "importe": 39.12
      }
    ],
    "formasPago": [
      {
        "tpa_id": 1,
        "importe": 283.62
      }
    ]
  }' \
  | python3 -m json.tool

echo ""
echo "=========================================="
echo "FIN DE LA PRUEBA"
echo "=========================================="
