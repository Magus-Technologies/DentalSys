<?php
/**
 * Compatibilidad: el módulo "Caja y Pagos" fue unificado en Facturación.
 * Cualquier acceso se redirige a /pages/facturacion.php manteniendo parámetros.
 */
require_once __DIR__.'/../includes/config.php';

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: '.BASE_URL.'/pages/facturacion.php'.($qs ? '?'.$qs : ''));
exit;
