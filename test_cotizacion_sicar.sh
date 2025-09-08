#!/bin/bash

# SCRIPT DE PRUEBA: Crear cotización + agregar artículo siguiendo flujo exacto SICAR
# Basado en análisis exhaustivo del módulo secotizacion-4.0.jar

echo "🎯 PRUEBA TUNNEL: Creación de cotización + artículo siguiendo flujo SICAR exacto"
echo "=================================================================="
echo ""

# Configuración
API_URL="http://tunnelcuspi.test/api"
API_KEY="uN4gFh7rT3@kLp98#Qwz"
ENDPOINT="/cotizaciones/prueba-con-articulo"

echo "📡 Enviando petición POST a: ${API_URL}${ENDPOINT}"
echo "🔑 API Key: ${API_KEY}"
echo "📦 Artículo de prueba: 1634 (4-1025617 - Papelera Basurero Elite 121 Lts Rojo)"
echo ""

# Ejecutar petición
response=$(curl -s -X POST \
  "${API_URL}${ENDPOINT}" \
  -H "Content-Type: application/json" \
  -H "API-KEY: ${API_KEY}" \
  -w "\nHTTP_CODE:%{http_code}\n")

# Extraer código HTTP
http_code=$(echo "$response" | grep "HTTP_CODE:" | cut -d':' -f2)
response_body=$(echo "$response" | sed '/HTTP_CODE:/d')

echo "📊 RESULTADO DE LA PRUEBA:"
echo "=========================="
echo "HTTP Code: $http_code"
echo ""

if [ "$http_code" = "200" ]; then
    echo "✅ ÉXITO: Cotización + Artículo creados correctamente"
    echo ""
    echo "📋 Respuesta del servidor:"
    echo "$response_body" | jq . 2>/dev/null || echo "$response_body"
    echo ""
    
    # Extraer datos para validación en SICAR
    if command -v jq &> /dev/null; then
        cot_id=$(echo "$response_body" | jq -r '.datos.cotizacion.cot_id // "N/A"')
        total=$(echo "$response_body" | jq -r '.datos.cotizacion.total // "N/A"')
        art_agregado=$(echo "$response_body" | jq -r '.datos.articulo_agregado.clave // "N/A"')
        precio_con=$(echo "$response_body" | jq -r '.datos.articulo_agregado.precio_con // "N/A"')
        
        echo "🔍 DATOS PARA VALIDAR EN SICAR:"
        echo "==============================="
        echo "• Cotización ID: $cot_id"
        echo "• Total: \$${total}"
        echo "• Artículo agregado: $art_agregado"
        echo "• Precio con impuestos: \$${precio_con}"
        echo ""
        echo "🎯 INSTRUCCIONES DE VALIDACIÓN:"
        echo "1. Abre SICAR"
        echo "2. Ve a Operaciones → Cotizaciones"
        echo "3. Busca la cotización ID: $cot_id"
        echo "4. Verifica que se abra SIN ERRORES"
        echo "5. Confirma que muestre el artículo: $art_agregado"
        echo "6. Verifica que el total coincida: \$${total}"
        echo ""
    fi
    
    echo "🎉 SI ESTA COTIZACIÓN SE ABRE EN SICAR SIN PROBLEMAS, ¡EL ANÁLISIS FUE EXITOSO!"
    
else
    echo "❌ ERROR: Falló la creación de cotización + artículo"
    echo ""
    echo "📋 Respuesta del servidor:"
    echo "$response_body"
    echo ""
    echo "🔧 POSIBLES CAUSAS:"
    echo "• Artículo ID 1634 no existe en la BD"
    echo "• Error en configuración VentaConf"
    echo "• Problema con cliente por defecto"
    echo "• Error en cálculos de precios/impuestos"
fi

echo ""
echo "📚 DOCUMENTACIÓN DE REFERENCIA:"
echo "• Análisis completo: /home/dev/Proyectos/dev_sicar/ANALISIS_AGREGAR_ARTICULO_COTIZACION.md"
echo "• Implementación: /var/www/tunnelcuspi/app/Http/Controllers/Api/CotizacionController.php:521"
echo "• Endpoint: POST ${API_URL}${ENDPOINT}"
echo ""
echo "=================================================================="