<?php
/**
 * test_sunat.php — Prueba aislada de la integración con la API Laravel.
 * Sirve para validar el flujo SIN tocar la BD.
 *
 * USO:  http://localhost/dental/pages/admin/test_sunat.php
 *
 * ⚠ PRODUCCIÓN: proteger o eliminar este archivo antes de subir.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/config_sunat.php';
require_once __DIR__ . '/../../includes/sunat/SunatClient.php';
require_once __DIR__ . '/../../includes/sunat/SunatBuilder.php';

header('Content-Type: text/plain; charset=utf-8');

$pago = [
    'tipo_comprobante' => 'boleta',
    'serie'            => SUNAT_SERIE_BOLETA,
    'numero'           => 1,
    'fecha'            => date('Y-m-d\TH:i:sP'),
];
$paciente = [
    'dni'              => '12345678',
    'ruc'              => '',
    'nombres'          => 'JUAN',
    'apellido_paterno' => 'PEREZ',
    'apellido_materno' => 'TEST',
    'direccion'        => 'AV. CLIENTE 999',
];
$items = [
    ['id' => 1, 'concepto' => 'PROFILAXIS DENTAL',  'cantidad' => 1, 'precio' => 80.00],
    ['id' => 2, 'concepto' => 'CURACIÓN SIMPLE',    'cantidad' => 2, 'precio' => 50.00],
];

echo "═══ DentalSys · TEST SUNAT (RUC " . SUNAT_RUC . " · " . SUNAT_ENDPOINT . ") ═══\n\n";

$payload = SunatBuilder::buildComprobante($pago, $paciente, $items);
echo "▶ PAYLOAD enviado a /generar/comprobante:\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$client = new SunatClient();

echo "▶ Llamando a /generar/comprobante ...\n";
$gen = $client->generarComprobante($payload);
echo "  HTTP {$gen['http']} · estado=" . var_export($gen['estado'] ?? null, true) . "\n";

if (empty($gen['estado'])) {
    echo "  Error: " . ($gen['mensaje'] ?? 'sin mensaje') . "\n";
    if (isset($gen['errors'])) echo "  errores: " . json_encode($gen['errors'], JSON_PRETTY_PRINT) . "\n";
    exit;
}

$nombre = $gen['data']['nombre_archivo'];
$xml    = $gen['data']['contenido_xml'];
$qr     = $gen['data']['qr_info'];
echo "  XML firmado · archivo=$nombre · " . strlen($xml) . " bytes\n";
echo "  qr_info: $qr\n\n";

echo "▶ Llamando a /enviar/documento/electronico ...\n";
$env = $client->enviarDocumento([
    'ruc'                 => SUNAT_RUC,
    'usuario'             => SUNAT_USUARIO_SOL,
    'clave'               => SUNAT_CLAVE_SOL,
    'endpoint'            => SUNAT_ENDPOINT,
    'nombre_documento'    => $nombre,
    'contenido_documento' => $xml,
]);
echo "  HTTP {$env['http']} · estado=" . var_export($env['estado'] ?? null, true) . "\n";
echo "  mensaje: " . ($env['mensaje'] ?? '(sin mensaje)') . "\n";

if (!empty($env['estado'])) {
    echo "\nOK — comprobante aceptado por SUNAT (beta).\n";
    echo "   CDR (base64, primeros 80 chars): " . substr($env['cdr'] ?? '', 0, 80) . "...\n";
} else {
    echo "\nFALLÓ el envío.\n";
}
