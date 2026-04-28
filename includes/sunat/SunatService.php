<?php
/**
 * SunatService — Orquesta el flujo de facturación electrónica en DOS pasos.
 *
 *   1) generarXml($pagoId)   → llama /generar/comprobante, guarda XML+hash+qr,
 *                              deja sunat_estado = 'pendiente'.
 *   2) enviarSunat($pagoId)  → toma el XML guardado, llama /enviar/documento/electronico,
 *                              guarda CDR, deja sunat_estado = 'aceptado' | 'rechazado'.
 *
 * El nombre del archivo SUNAT no se persiste: se reconstruye con
 * {RUC}-{TIPO}-{SERIE}-{NUMERO_8}.
 */
require_once __DIR__ . '/SunatClient.php';
require_once __DIR__ . '/SunatBuilder.php';

class SunatService
{
    private PDO          $db;
    private SunatClient  $client;

    public function __construct(PDO $db, ?SunatClient $client = null)
    {
        $this->db     = $db;
        $this->client = $client ?? new SunatClient();
    }

    // ─── PASO 1: GENERAR XML ──────────────────────────────────────
    public function generarXml(int $pagoId): array
    {
        $pago = $this->fetchPago($pagoId);
        if (!$pago) {
            return ['ok' => false, 'mensaje' => "Pago #$pagoId no encontrado."];
        }
        if (!in_array($pago['tipo_comprobante'], ['factura', 'boleta'], true)) {
            return ['ok' => false, 'mensaje' => "Tipo '{$pago['tipo_comprobante']}' no se emite a SUNAT."];
        }
        if (empty($pago['serie']) || empty($pago['numero'])) {
            return ['ok' => false, 'mensaje' => 'El pago no tiene serie/numero asignados.'];
        }

        $paciente = $this->fetchPaciente((int) $pago['paciente_id']);
        $items    = $this->fetchItems($pagoId);

        try {
            $payload = SunatBuilder::buildComprobante($pago, $paciente, $items);
        } catch (Throwable $e) {
            $this->marcarRechazada($pagoId, $e->getMessage());
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }

        $gen = $this->client->generarComprobante($payload);
        if (empty($gen['estado'])) {
            $msg = $gen['mensaje'] ?? 'Error al generar XML.';
            $this->marcarRechazada($pagoId, $msg);
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $gen];
        }

        $hash   = $gen['data']['hash']          ?? '';
        $qrInfo = $gen['data']['qr_info']       ?? '';
        $xml    = $gen['data']['contenido_xml'] ?? '';

        $this->marcarPendiente($pagoId, $hash, $qrInfo, $xml);

        return [
            'ok'      => true,
            'mensaje' => 'XML generado correctamente. Listo para enviar a SUNAT.',
            'hash'    => $hash,
            'qr'      => $qrInfo,
        ];
    }

    // ─── PASO 2: ENVIAR A SUNAT ───────────────────────────────────
    public function enviarSunat(int $pagoId): array
    {
        $pago = $this->fetchPago($pagoId);
        if (!$pago) {
            return ['ok' => false, 'mensaje' => "Pago #$pagoId no encontrado."];
        }
        if (empty($pago['sunat_xml'])) {
            return ['ok' => false, 'mensaje' => 'Este pago no tiene XML generado todavía.'];
        }
        if ($pago['sunat_estado'] === 'aceptado') {
            return ['ok' => false, 'mensaje' => 'Este pago ya fue aceptado por SUNAT.'];
        }

        $nombreArchivo = $this->nombreArchivo($pago);

        $env = $this->client->enviarDocumento([
            'ruc'                 => SUNAT_RUC,
            'usuario'             => SUNAT_USUARIO_SOL,
            'clave'               => SUNAT_CLAVE_SOL,
            'endpoint'            => SUNAT_ENDPOINT,
            'nombre_documento'    => $nombreArchivo,
            'contenido_documento' => $pago['sunat_xml'],
        ]);

        if (empty($env['estado'])) {
            $msg = $env['mensaje'] ?? 'Error al enviar a SUNAT.';
            $this->marcarRechazada(
                $pagoId, $msg,
                $pago['sunat_hash'] ?? '',
                $pago['sunat_qr']   ?? '',
                $pago['sunat_xml']  ?? ''
            );
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $env];
        }

        $this->marcarAceptada(
            $pagoId,
            $pago['sunat_hash'] ?? '',
            $pago['sunat_qr']   ?? '',
            $pago['sunat_xml']  ?? '',
            $env['cdr']     ?? '',
            $env['mensaje'] ?? 'ACEPTADO'
        );

        return [
            'ok'      => true,
            'mensaje' => 'Comprobante aceptado por SUNAT.',
            'cdr'     => $env['cdr'] ?? '',
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    public static function nombreArchivo(array $pago): string
    {
        $tipo = $pago['tipo_comprobante'] === 'factura' ? '01' : '03';
        $num  = str_pad((string)$pago['numero'], 8, '0', STR_PAD_LEFT);
        return SUNAT_RUC . '-' . $tipo . '-' . $pago['serie'] . '-' . $num;
    }

    /**
     * Calcula el siguiente correlativo libre para una serie dada.
     * Usado al registrar el pago.
     */
    public static function siguienteNumero(PDO $db, string $serie): int
    {
        $st = $db->prepare("SELECT COALESCE(MAX(numero),0)+1 FROM pagos WHERE serie=?");
        $st->execute([$serie]);
        return (int) $st->fetchColumn();
    }

    // ─── Persistencia ────────────────────────────────────────────────

    private function marcarPendiente(int $id, string $hash, string $qr, string $xml): void
    {
        $st = $this->db->prepare("
            UPDATE pagos SET
                sunat_estado='pendiente',
                sunat_hash=?,
                sunat_qr=?,
                sunat_xml=?,
                sunat_cdr=NULL,
                sunat_mensaje='XML generado, pendiente de envío.',
                sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$hash, $qr, $xml, $id]);
    }

    private function marcarAceptada(int $id, string $hash, string $qr, string $xml, string $cdr, string $msg): void
    {
        $st = $this->db->prepare("
            UPDATE pagos SET
                sunat_estado='aceptado',
                sunat_hash=?,
                sunat_qr=?,
                sunat_xml=?,
                sunat_cdr=?,
                sunat_mensaje=?,
                sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$hash, $qr, $xml, $cdr, $msg, $id]);
    }

    private function marcarRechazada(int $id, string $msg, string $hash = '', string $qr = '', string $xml = ''): void
    {
        $st = $this->db->prepare("
            UPDATE pagos SET
                sunat_estado='rechazado',
                sunat_hash=?,
                sunat_qr=?,
                sunat_xml=?,
                sunat_mensaje=?,
                sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$hash, $qr, $xml, mb_substr($msg, 0, 1000), $id]);
    }

    // ─── Lecturas ────────────────────────────────────────────────────

    private function fetchPago(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM pagos WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private function fetchPaciente(int $id): array
    {
        $st = $this->db->prepare("SELECT * FROM pacientes WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: [];
    }

    private function fetchItems(int $pagoId): array
    {
        $st = $this->db->prepare("SELECT * FROM pago_detalles WHERE pago_id=? ORDER BY id");
        $st->execute([$pagoId]);
        return $st->fetchAll();
    }
}
