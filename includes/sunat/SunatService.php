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

    // ─── NOTAS DE CRÉDITO/DÉBITO ─────────────────────────────────────

    public function generarXmlNota(int $notaId): array
    {
        $nota = $this->fetchNota($notaId);
        if (!$nota) return ['ok' => false, 'mensaje' => "Nota #$notaId no encontrada."];

        try {
            if (!empty($nota['nota_ref_id'])) {
                // NC-of-NC: cancels a wrong NC that was already accepted by SUNAT
                $notaRef  = $this->fetchNota((int) $nota['nota_ref_id']);
                $pagOrig  = $this->fetchPago((int) $notaRef['pago_id']);
                $paciente = $this->fetchPaciente((int) $pagOrig['paciente_id']);
                $items    = $this->fetchItems((int) $notaRef['pago_id']);
                $payload  = SunatBuilder::buildNotaDeNota($nota, $notaRef, $paciente, $items);
            } else {
                $pagOrig  = $this->fetchPago((int) $nota['pago_id']);
                $paciente = $this->fetchPaciente((int) $pagOrig['paciente_id']);
                $items    = $this->fetchItems((int) $nota['pago_id']);
                $payload  = SunatBuilder::buildNota($nota, $pagOrig, $paciente, $items);
            }
        } catch (Throwable $e) {
            $this->marcarNotaEstado($notaId, 'rechazado', $e->getMessage());
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }

        $gen = $this->client->generarNota($payload);
        if (empty($gen['estado'])) {
            $msg = $gen['mensaje'] ?? 'Error al generar XML de nota.';
            $this->marcarNotaEstado($notaId, 'rechazado', $msg);
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $gen];
        }

        $hash   = $gen['data']['hash']          ?? '';
        $qrInfo = $gen['data']['qr_info']       ?? '';
        $xml    = $gen['data']['contenido_xml'] ?? '';

        $st = $this->db->prepare("
            UPDATE notas_credito SET
                sunat_estado='pendiente', sunat_hash=?, sunat_qr=?, sunat_xml=?,
                sunat_cdr=NULL, sunat_mensaje='XML generado, pendiente de envío.', sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$hash, $qrInfo, $xml, $notaId]);

        return ['ok' => true, 'mensaje' => 'XML de nota generado. Listo para enviar.', 'hash' => $hash, 'qr' => $qrInfo];
    }

    public function enviarSunatNota(int $notaId): array
    {
        $nota = $this->fetchNota($notaId);
        if (!$nota) return ['ok' => false, 'mensaje' => "Nota #$notaId no encontrada."];
        if (empty($nota['sunat_xml'])) return ['ok' => false, 'mensaje' => 'Esta nota no tiene XML generado.'];
        if ($nota['sunat_estado'] === 'aceptado') return ['ok' => false, 'mensaje' => 'Esta nota ya fue aceptada por SUNAT.'];

        $tipoNota = $nota['tipo_nota'] === 'credito' ? '07' : '08';
        $num = str_pad((string)$nota['numero'], 8, '0', STR_PAD_LEFT);
        $nombreArchivo = SUNAT_RUC . '-' . $tipoNota . '-' . $nota['serie'] . '-' . $num;

        $env = $this->client->enviarDocumento([
            'ruc'                 => SUNAT_RUC,
            'usuario'             => SUNAT_USUARIO_SOL,
            'clave'               => SUNAT_CLAVE_SOL,
            'endpoint'            => SUNAT_ENDPOINT,
            'nombre_documento'    => $nombreArchivo,
            'contenido_documento' => $nota['sunat_xml'],
        ]);

        if (empty($env['estado'])) {
            $msg = $env['mensaje'] ?? 'Error al enviar nota a SUNAT.';
            $this->marcarNotaEstado($notaId, 'rechazado', $msg, $nota['sunat_xml'], $nota['sunat_hash'] ?? '', $nota['sunat_qr'] ?? '');
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $env];
        }

        $st = $this->db->prepare("
            UPDATE notas_credito SET
                sunat_estado='aceptado', sunat_hash=?, sunat_qr=?, sunat_xml=?,
                sunat_cdr=?, sunat_mensaje=?, sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$nota['sunat_hash'] ?? '', $nota['sunat_qr'] ?? '', $nota['sunat_xml'], $env['cdr'] ?? '', $env['mensaje'] ?? 'ACEPTADO', $notaId]);

        // Update downstream state after a credit note is accepted
        if ($nota['tipo_nota'] === 'credito') {
            if (!empty($nota['nota_ref_id'])) {
                // NC-of-NC accepted: mark wrong NC as anulada, restore pago to aceptado
                $notaRef = $this->fetchNota((int) $nota['nota_ref_id']);
                $this->db->prepare("UPDATE notas_credito SET estado='anulada' WHERE id=?")
                         ->execute([$nota['nota_ref_id']]);
                $this->db->prepare("UPDATE pagos SET estado='pagado' WHERE id=? AND estado='anulado'")
                         ->execute([$notaRef['pago_id']]);
            } else {
                // Normal NC: annul the original payment
                $this->db->prepare("UPDATE pagos SET estado='anulado' WHERE id=?")
                         ->execute([$nota['pago_id']]);
            }
        }

        return ['ok' => true, 'mensaje' => 'Nota aceptada por SUNAT.', 'cdr' => $env['cdr'] ?? ''];
    }

    /**
     * Cancels an accepted boleta-linked NC via SummaryDocuments (Resumen Diario).
     * Valid within 7 calendar days of the NC's emission date.
     * On SUNAT acceptance: marks the NC as anulada and restores the pago.
     */
    public function darDeBajaNotaResumen(int $notaId): array
    {
        $nota = $this->fetchNota($notaId);
        if (!$nota) return ['ok' => false, 'mensaje' => "Nota #$notaId no encontrada."];
        if ($nota['sunat_estado'] !== 'aceptado') return ['ok' => false, 'mensaje' => 'Solo se puede dar de baja una nota aceptada por SUNAT.'];
        if ($nota['estado'] === 'anulada') return ['ok' => false, 'mensaje' => 'Esta nota ya está anulada.'];

        // Verify 7-day window
        $emitida = new DateTime($nota['created_at']);
        $hoy     = new DateTime('today');
        $dias    = (int) $hoy->diff($emitida)->days;
        if ($dias > 7) {
            return ['ok' => false, 'mensaje' => "Fuera del plazo: la nota fue emitida hace $dias días (límite: 7 días calendario)."];
        }

        $pago      = $this->fetchPago((int) $nota['pago_id']);
        $paciente  = $this->fetchPaciente((int) $pago['paciente_id']);
        $fechaEmit = (new DateTime($nota['created_at']))->format('Y-m-d');
        $hoyStr    = date('Y-m-d');
        $dni       = trim($paciente['dni'] ?? '');
        $tipoCli   = ($dni !== '' && strlen($dni) === 8) ? 1 : 0;
        $numCli    = ($tipoCli === 1) ? $dni : '00000000';

        $aplica_igv = (bool)($nota['aplica_igv'] ?? false);
        $total      = (float)$nota['total'];
        $gravadas   = $aplica_igv ? round($total / 1.18, 2) : 0;
        $igv        = $aplica_igv ? round($total - $gravadas, 2) : 0;
        $exoneradas = $aplica_igv ? 0 : $total;

        $tipoPago   = $pago['tipo_comprobante'] === 'factura' ? '01' : '03';
        $refNro     = $pago['serie'] . '-' . str_pad((string)$pago['numero'], 8, '0', STR_PAD_LEFT);

        // offset avoids collision with sequentially-generated correlativos already sent
        $correlativo = (string)($notaId + 1000);

        $payload = [
            'endpoint'         => SUNAT_ENDPOINT,
            'correlativo'      => $correlativo,
            'fecha_generacion' => $fechaEmit, // date of the documents being reported
            'fecha_resumen'    => $hoyStr,    // today = summary creation date
            'empresa'          => [
                'ruc'          => SUNAT_RUC,
                'usuario'      => SUNAT_USUARIO_SOL,
                'clave'        => SUNAT_CLAVE_SOL,
                'razon_social' => SUNAT_RAZON_SOCIAL,
                'direccion'    => SUNAT_DIRECCION,
                'ubigeo'       => SUNAT_UBIGEO,
                'distrito'     => SUNAT_DISTRITO,
                'provincia'    => SUNAT_PROVINCIA,
                'departamento' => SUNAT_DEPARTAMENTO,
            ],
            'detalles' => [[
                'tipo_doc'            => '07',
                'serie_numero'        => $nota['serie'] . '-' . str_pad((string)$nota['numero'], 8, '0', STR_PAD_LEFT),
                'estado'              => 3,
                'tipo_doc_cliente'    => $tipoCli,
                'num_doc_cliente'     => $numCli,
                'total'               => $total,
                'mto_oper_gravadas'   => $gravadas,
                'mto_igv'             => $igv,
                'mto_oper_exoneradas' => $exoneradas,
                'doc_referencia_tipo' => $tipoPago,
                'doc_referencia_nro'  => $refNro,
            ]],
        ];

        $res = $this->client->enviarResumen($payload);

        if (empty($res['estado'])) {
            return ['ok' => false, 'mensaje' => $res['mensaje'] ?? 'Error al enviar resumen a SUNAT.', 'detalle' => $res];
        }

        // SUNAT accepted — mark NC as anulada and restore pago
        $this->db->prepare("UPDATE notas_credito SET estado='anulada', sunat_mensaje=?, sunat_fecha=NOW() WHERE id=?")
                 ->execute([mb_substr('BAJA RESUMEN: ' . ($res['mensaje'] ?? 'Aceptada'), 0, 1000), $notaId]);

        if ($nota['tipo_nota'] === 'credito') {
            $this->db->prepare("UPDATE pagos SET estado='pagado' WHERE id=? AND estado='anulado'")
                     ->execute([$nota['pago_id']]);
        }

        return ['ok' => true, 'mensaje' => 'Nota anulada via Resumen Diario. El comprobante original quedó reactivado.', 'ticket' => $res['ticket'] ?? ''];
    }

    public function darDeBajaNota(int $notaId, string $motivo = 'ERROR EN EMISION DE COMPROBANTE'): array
    {
        $nota = $this->fetchNota($notaId);
        if (!$nota) return ['ok' => false, 'mensaje' => "Nota #$notaId no encontrada."];
        if ($nota['sunat_estado'] !== 'aceptado') return ['ok' => false, 'mensaje' => 'Solo se puede dar de baja una nota aceptada por SUNAT.'];
        if ($nota['estado'] === 'anulada') return ['ok' => false, 'mensaje' => 'Esta nota ya fue dada de baja.'];

        $tipoDoc = $nota['tipo_nota'] === 'credito' ? '07' : '08';
        $hoy     = date('Y-m-d');

        // Daily correlativo: count of bajas already sent today
        $corrSt = $this->db->prepare("SELECT COUNT(*)+1 FROM notas_credito WHERE DATE(sunat_fecha)=? AND estado='anulada'");
        $corrSt->execute([$hoy]);
        $correlativo = (string)(int)$corrSt->fetchColumn();

        $payload = [
            'endpoint'            => SUNAT_ENDPOINT,
            'empresa'             => [
                'ruc'          => SUNAT_RUC,
                'usuario'      => SUNAT_USUARIO_SOL,
                'clave'        => SUNAT_CLAVE_SOL,
                'razon_social' => SUNAT_RAZON_SOCIAL,
                'direccion'    => SUNAT_DIRECCION,
                'ubigeo'       => SUNAT_UBIGEO,
                'distrito'     => SUNAT_DISTRITO,
                'provincia'    => SUNAT_PROVINCIA,
                'departamento' => SUNAT_DEPARTAMENTO,
            ],
            'correlativo'         => $correlativo,
            'fecha_generacion'    => $hoy,
            'fecha_comunicacion'  => $hoy,
            'detalles'            => [[
                'tipo_doc'    => $tipoDoc,
                'serie'       => $nota['serie'],
                'correlativo' => (string)$nota['numero'],
                'motivo'      => mb_strtoupper(mb_substr($motivo, 0, 100)),
            ]],
        ];

        $res = $this->client->enviarBaja($payload);

        if (empty($res['estado'])) {
            // 0098 + ticket = SUNAT received but still processing (async)
            if (!empty($res['pendiente']) && !empty($res['ticket'])) {
                $ticket = $res['ticket'];
                $this->db->prepare("UPDATE notas_credito SET sunat_mensaje=?, sunat_fecha=NOW() WHERE id=?")
                         ->execute([mb_substr('BAJA_PENDIENTE:' . $ticket, 0, 1000), $notaId]);
                return [
                    'ok'      => false,
                    'pending' => true,
                    'ticket'  => $ticket,
                    'mensaje' => 'La baja fue enviada a SUNAT pero el procesamiento todavía no terminó. '
                               . 'Verificá el estado en el portal de SUNAT (Consulta de Validez). Ticket: ' . $ticket,
                ];
            }
            $msg = $res['mensaje'] ?? 'Error al enviar baja a SUNAT.';
            return ['ok' => false, 'mensaje' => $msg, 'detalle' => $res];
        }

        // Baja confirmada por SUNAT
        $this->db->prepare("
            UPDATE notas_credito SET estado='anulada', sunat_cdr=?, sunat_mensaje=?, sunat_fecha=NOW() WHERE id=?
        ")->execute([$res['cdr'] ?? null, mb_substr('BAJA: ' . ($res['mensaje'] ?? 'Aceptada'), 0, 1000), $notaId]);

        if ($nota['tipo_nota'] === 'credito') {
            $this->db->prepare("UPDATE pagos SET estado='pagado' WHERE id=? AND estado='anulado'")
                     ->execute([$nota['pago_id']]);
        }

        return ['ok' => true, 'mensaje' => 'Baja enviada y aceptada por SUNAT. El comprobante original quedó reactivado.'];
    }

    public static function siguienteNumeroNota(PDO $db, string $serie): int
    {
        $st = $db->prepare("SELECT COALESCE(MAX(numero),0)+1 FROM notas_credito WHERE serie=?");
        $st->execute([$serie]);
        return (int) $st->fetchColumn();
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

    private function fetchNota(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM notas_credito WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private function marcarNotaEstado(int $id, string $estado, string $mensaje = '', string $xml = '', string $hash = '', string $qr = ''): void
    {
        $st = $this->db->prepare("
            UPDATE notas_credito SET sunat_estado=?, sunat_mensaje=?, sunat_xml=?, sunat_hash=?, sunat_qr=?, sunat_fecha=NOW()
            WHERE id=?
        ");
        $st->execute([$estado, mb_substr($mensaje, 0, 1000), $xml, $hash, $qr, $id]);
    }
}
