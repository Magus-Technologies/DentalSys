<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();

$paciente_id = (int)($_GET['paciente_id'] ?? 0);
$hc_id       = (int)($_GET['hc_id'] ?? 0);
$id          = (int)($_GET['id'] ?? 0); // odontograma id

if (!$paciente_id && !$hc_id) {
    flash('error','Paciente requerido');
    go('pages/pacientes.php');
}

// Obtener paciente
if ($paciente_id) {
    $ps = db()->prepare("SELECT * FROM pacientes WHERE id=?");
    $ps->execute([$paciente_id]); $pac = $ps->fetch();
} else {
    $ps = db()->prepare("SELECT p.* FROM pacientes p JOIN historias_clinicas hc ON hc.paciente_id=p.id WHERE hc.id=?");
    $ps->execute([$hc_id]); $pac = $ps->fetch();
    if ($pac) $paciente_id = $pac['id'];
}
if (!$pac) { flash('error','Paciente no encontrado'); go('pages/pacientes.php'); }

// Obtener HC si no viene
if (!$hc_id) {
    $hs = db()->prepare("SELECT id FROM historias_clinicas WHERE paciente_id=? ORDER BY fecha_apertura DESC LIMIT 1");
    $hs->execute([$paciente_id]); $hc_id = (int)$hs->fetchColumn();
}

// ── POST: guardar odontograma ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $datos = json_decode($_POST['datos_json'] ?? '{}', true);
    $obs   = trim($_POST['observaciones'] ?? '');
    $tipo  = $_POST['tipo_dentadura'] ?? 'permanente';

    // Buscar o crear odontograma
    $oids = db()->prepare("SELECT id FROM odontogramas WHERE hc_id=? AND paciente_id=? ORDER BY fecha DESC LIMIT 1");
    $oids->execute([$hc_id, $paciente_id]); $oid = $oids->fetchColumn();

    if (!$oid) {
        db()->prepare("INSERT INTO odontogramas(hc_id,paciente_id,doctor_id,fecha,observaciones) VALUES(?,?,?,CURDATE(),?)")
           ->execute([$hc_id, $paciente_id, $_SESSION['uid'], $obs]);
        $oid = db()->lastInsertId();
    } else {
        db()->prepare("UPDATE odontogramas SET observaciones=?,fecha=CURDATE() WHERE id=?")
           ->execute([$obs, $oid]);
        db()->prepare("DELETE FROM odontograma_dientes WHERE odontograma_id=?")->execute([$oid]);
    }

    // Guardar cada diente
    foreach ($datos as $num => $estados) {
        foreach ($estados as $est) {
            if (!isset($est['estado'])) continue;
            db()->prepare("INSERT INTO odontograma_dientes(odontograma_id,numero_diente,cara,estado,color,notas) VALUES(?,?,?,?,?,?)")
               ->execute([$oid, $num, $est['cara'] ?? 'total', $est['estado'], $est['color'] ?? 'azul', $est['notas'] ?? '']);
        }
    }

    auditar('GUARDAR_ODONTOGRAMA','odontogramas',$oid);
    flash('ok','Odontograma guardado correctamente.');
    header('Location:'.BASE_URL.'/pages/odontograma.php?paciente_id='.$paciente_id.'&hc_id='.$hc_id);
    exit;
}

// ── Cargar datos existentes ──────────────────────────────────
$odont = null; $dientes_db = [];
$od = db()->prepare("SELECT * FROM odontogramas WHERE paciente_id=? ORDER BY fecha DESC LIMIT 1");
$od->execute([$paciente_id]); $odont = $od->fetch();
if ($odont) {
    $ds = db()->prepare("SELECT * FROM odontograma_dientes WHERE odontograma_id=?");
    $ds->execute([$odont['id']]); 
    foreach ($ds->fetchAll() as $d) {
        $dientes_db[$d['numero_diente']][] = [
            'cara'   => $d['cara'],
            'estado' => $d['estado'],
            'color'  => $d['color'],
            'notas'  => $d['notas'] ?? ''
        ];
    }
}

// Historial de odontogramas
$historial = db()->prepare("SELECT o.*,CONCAT(u.nombre,' ',u.apellidos) AS dr FROM odontogramas o LEFT JOIN usuarios u ON o.doctor_id=u.id WHERE o.paciente_id=? ORDER BY o.fecha DESC LIMIT 10");
$historial->execute([$paciente_id]); $historial = $historial->fetchAll();

$titulo = 'Odontograma — '.$pac['nombres'].' '.$pac['apellido_paterno'];
$pagina_activa = 'hc';
$topbar_act = '<a href="'.BASE_URL.'/pages/historia_clinica.php?paciente_id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-file-medical me-1"></i>HC</a>
<a href="'.BASE_URL.'/pages/pacientes.php?accion=ver&id='.$paciente_id.'" class="btn btn-dk btn-sm"><i class="bi bi-person me-1"></i>Paciente</a>';

$xhead = '<style>
@media(max-width:768px){
  #toolPanel{
    overflow-x:auto;-webkit-overflow-scrolling:touch;
    display:flex;flex-direction:row;flex-wrap:nowrap;gap:8px;padding:10px;
    align-items:flex-start
  }
  #toolPanel>div{flex-shrink:0}
  .tool-btn{width:auto;white-space:nowrap;margin-bottom:0!important}
  .odont-wrap{padding:10px 6px}
  .odont-svg{min-width:750px}
  .tool-panel+.tool-panel{margin-top:0}
}
@media(max-width:576px){
  .odont-wrap .d-flex.gap-2{overflow-x:auto;flex-wrap:nowrap!important;padding-bottom:4px}
}

@media(max-width:768px){
  .odont-svg{min-width:750px}
  .odont-wrap{padding:10px 8px;overflow-x:auto}
  #toolPanel{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:5px}
  .tool-btn{padding:6px 8px;font-size:11px;margin-bottom:0!important}
  select#selCara,select+select{width:100%!important}
}
@media(max-width:480px){
  .odont-svg{min-width:680px}
  .row.g-3>.col-12.col-xl-2,
  .row.g-3>.col-12.col-lg-3{order:2}
  .row.g-3>.col-12.col-xl-10,
  .row.g-3>.col-12.col-lg-9{order:1}
}</style>';
// Override xhead for odontogram page
$xhead = '<style>
/* ── ODONTOGRAMA PROFESIONAL ── */
.odont-wrap{background:var(--bg3);border:1px solid var(--bd);border-radius:12px;padding:20px;overflow-x:auto;user-select:none}
.odont-svg{display:block;margin:0 auto;min-width:900px;max-width:1200px;width:100%}
.odont-svg .tooth-group{cursor:pointer;transition:opacity .15s}
.odont-svg .tooth-group:hover .tooth-bg{opacity:.85}
.odont-svg .tooth-num{font-family:"DM Mono",monospace;font-size:11px;font-weight:600;fill:#6BC5D3;text-anchor:middle}
.odont-svg .tooth-name{font-size:8px;fill:#507080;text-anchor:middle}
.odont-svg .face-label{font-size:7px;fill:#4A6070;text-anchor:middle;font-weight:700;letter-spacing:.5px}
/* Estado badges debajo del odontograma */
.legend-item{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;border:2px solid transparent;transition:all .15s;color:var(--t)}
.legend-item.active{border-color:var(--c)!important;background:rgba(0,212,238,.1)}
.legend-dot{width:14px;height:14px;border-radius:3px;flex-shrink:0}
/* Paleta de herramientas */
.tool-panel{background:var(--bg2);border:1px solid var(--bd2);border-radius:10px;padding:16px}
.tool-btn{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:7px;border:1px solid var(--bd2);background:var(--bg3);color:var(--t);font-size:12px;font-weight:700;cursor:pointer;width:100%;text-align:left;transition:all .14s;margin-bottom:5px}
.tool-btn:hover{border-color:var(--c);color:var(--c)}
.tool-btn.active{background:rgba(0,212,238,.12);border-color:var(--c);color:var(--c)}
.tool-dot{width:16px;height:16px;border-radius:4px;flex-shrink:0}
/* Selected tooth info */
.tooth-info{background:var(--bg2);border:1px solid var(--bd);border-radius:8px;padding:12px;min-height:80px}
</style>';

require_once __DIR__.'/../includes/header.php';
?>

<div class="row g-3 mb-3">
 <!-- Paciente info -->
 <div class="col-12">
  <div class="card p-3 d-flex flex-row align-items-center gap-3 flex-wrap">
   <div class="ava" style="width:44px;height:44px;font-size:18px;flex-shrink:0"><?= strtoupper(substr($pac['nombres'],0,1)) ?></div>
   <div>
    <strong style="font-size:15px;color:var(--t)"><?= e($pac['nombres'].' '.$pac['apellido_paterno'].' '.($pac['apellido_materno']??'')) ?></strong>
    <div style="font-size:12px;color:var(--t2)"><?= e($pac['codigo']) ?> · <?= $pac['fecha_nacimiento'] ? edad($pac['fecha_nacimiento']) : '—' ?> · DNI: <?= e($pac['dni']??'—') ?></div>
   </div>
   <?php if ($pac['alergias']): ?>
   <span class="badge br ms-2">⚠️ ALÉRGICO: <?= e($pac['alergias']) ?></span>
   <?php endif; ?>
   <?php if ($odont): ?>
   <span class="badge bg ms-auto">Último registro: <?= fDate($odont['fecha']) ?></span>
   <?php endif; ?>
  </div>
 </div>
</div>

<form method="POST" id="fOdont">
<input type="hidden" name="accion" value="guardar">
<input type="hidden" name="hc_id" value="<?= $hc_id ?>">
<input type="hidden" name="datos_json" id="datosJson" value="{}">

<div class="row g-3">
 <!-- Panel de herramientas -->
 <div class="col-12 col-xl-2 col-lg-3 order-2 order-lg-1">
  <div class="tool-panel mb-3" id="toolPanel">
   <div style="font-size:10px;font-weight:700;color:var(--c);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px">Tipo dentadura</div>
   <div class="d-flex gap-2 mb-3">
    <button type="button" class="btn btn-sm flex-fill" id="btnPerm" onclick="setTipo('permanente')" style="font-size:11px">🦷 Permanente</button>
    <button type="button" class="btn btn-sm flex-fill" id="btnTemp" onclick="setTipo('temporal')" style="font-size:11px">🧒 Temporal</button>
   </div>

   <div style="font-size:10px;font-weight:700;color:var(--c);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px">Estado / Diagnóstico</div>

   <button type="button" class="tool-btn" onclick="setHerramienta('sano')" id="tool-sano">
    <div class="tool-dot" style="background:rgba(255,255,255,.15);border:2px solid #607080"></div> Sano / Borrar
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('caries')" id="tool-caries">
    <div class="tool-dot" style="background:#E05252"></div> Caries
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('obturado')" id="tool-obturado">
    <div class="tool-dot" style="background:#00D4EE"></div> Obturado / Restaurado
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('ausente')" id="tool-ausente">
    <div class="tool-dot" style="background:#F5A623"></div> Ausente / Extraído
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('endodoncia')" id="tool-endodoncia">
    <div class="tool-dot" style="background:#8B5CF6"></div> Endodoncia
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('corona')" id="tool-corona">
    <div class="tool-dot" style="background:#F59E0B"></div> Corona
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('implante')" id="tool-implante">
    <div class="tool-dot" style="background:#10B981"></div> Implante
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('fractura')" id="tool-fractura">
    <div class="tool-dot" style="background:#EF4444"></div> Fractura
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('presupuesto')" id="tool-presupuesto">
    <div class="tool-dot" style="background:#3B82F6"></div> Presupuesto
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('sellante')" id="tool-sellante">
    <div class="tool-dot" style="background:#EC4899"></div> Sellante
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('protesis')" id="tool-protesis">
    <div class="tool-dot" style="background:#6366F1"></div> Prótesis / Puente
   </button>
   <button type="button" class="tool-btn" onclick="setHerramienta('brackets')" id="tool-brackets">
    <div class="tool-dot" style="background:#06B6D4"></div> Brackets / Ortodoncia
   </button>

   <div style="font-size:10px;font-weight:700;color:var(--c);letter-spacing:1.5px;text-transform:uppercase;margin:12px 0 8px">Cara a marcar</div>
   <select id="selCara" class="form-select form-select-sm mb-3">
    <option value="total">⬛ Total / Completo</option>
    <option value="vestibular">↑ Vestibular</option>
    <option value="lingual">↓ Lingual/Palatino</option>
    <option value="mesial">← Mesial</option>
    <option value="distal">→ Distal</option>
    <option value="oclusal">⬤ Oclusal/Incisal</option>
   </select>

   <div style="font-size:10px;font-weight:700;color:var(--c);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px">Color</div>
   <div class="d-flex gap-2 mb-3">
    <button type="button" class="legend-item" onclick="setColor('rojo')" id="col-rojo" style="border-color:rgba(224,82,82,.4)"><div class="tool-dot" style="background:#E05252"></div><span style="color:var(--t)">Rojo</span></button>
    <button type="button" class="legend-item" onclick="setColor('azul')" id="col-azul" style="border-color:rgba(0,212,238,.4)"><div class="tool-dot" style="background:#00D4EE"></div><span style="color:var(--t)">Azul</span></button>
    <button type="button" class="legend-item" onclick="setColor('negro')" id="col-negro" style="border-color:rgba(160,176,192,.3)"><div class="tool-dot" style="background:#607080"></div><span style="color:var(--t)">Negro</span></button>
   </div>

   <button type="button" class="btn btn-del btn-sm w-100 mb-2" onclick="limpiarTodo()">🧹 Limpiar todo</button>
  </div>

  <!-- Diente seleccionado -->
  <div class="tool-panel">
   <div style="font-size:10px;font-weight:700;color:var(--c);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px">Diente seleccionado</div>
   <div class="tooth-info" id="toothInfo">
    <div style="color:var(--t2);font-size:12px;text-align:center;padding-top:20px">Haz clic en un diente</div>
   </div>
   <div class="mt-2">
    <label class="form-label">Nota del diente</label>
    <textarea id="notaDiente" class="form-control" rows="2" placeholder="Observación..." oninput="saveNota()" disabled></textarea>
   </div>
  </div>
 </div>

 <!-- ODONTOGRAMA PRINCIPAL -->
 <div class="col-12 col-xl-10 col-lg-9 order-1 order-lg-2">
  <div class="odont-wrap" id="odontWrap">
   <!-- Leyenda superior -->
   <div class="d-flex gap-2 flex-wrap mb-3 px-2 justify-content-center">
    <?php
    $leyenda = [
     ['caries','#E05252','Caries'],['obturado','#00D4EE','Obturado'],['ausente','#F5A623','Ausente'],
     ['endodoncia','#8B5CF6','Endodoncia'],['corona','#F59E0B','Corona'],['implante','#10B981','Implante'],
     ['fractura','#EF4444','Fractura'],['presupuesto','#3B82F6','Presupuesto'],
     ['sellante','#EC4899','Sellante'],['protesis','#6366F1','Prótesis'],['brackets','#06B6D4','Brackets'],
    ];
    foreach ($leyenda as [$k,$c,$l]): ?>
    <div style="display:inline-flex;align-items:center;gap:4px;font-size:10px;color:var(--t2)">
     <div style="width:10px;height:10px;border-radius:2px;background:<?= $c ?>"></div><?= $l ?>
    </div>
    <?php endforeach; ?>
   </div>

   <!-- SVG ODONTOGRAMA FDI COMPLETO -->
   <svg id="svgOdont" class="odont-svg" viewBox="0 0 1100 620" xmlns="http://www.w3.org/2000/svg">
    <defs>
     <!-- Filtro sombra para dientes -->
     <filter id="dShadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="rgba(0,0,0,.4)"/>
     </filter>
     <!-- Gradiente base diente -->
     <linearGradient id="toothGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#D8E4EC;stop-opacity:1"/>
      <stop offset="100%" style="stop-color:#B0C4D0;stop-opacity:1"/>
     </linearGradient>
     <!-- Gradiente diente sombra -->
     <linearGradient id="toothShade" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#EAEFF5;stop-opacity:1"/>
      <stop offset="100%" style="stop-color:#A0B8C8;stop-opacity:1"/>
     </linearGradient>
    </defs>

    <!-- ══ LÍNEAS DE REFERENCIA ══ -->
    <!-- Línea media vertical -->
    <line x1="550" y1="10" x2="550" y2="610" stroke="rgba(0,212,238,.25)" stroke-width="1.5" stroke-dasharray="6,4"/>
    <!-- Separador superior/inferior -->
    <line x1="20" y1="305" x2="1080" y2="305" stroke="rgba(0,212,238,.15)" stroke-width="1"/>

    <!-- Etiquetas cuadrantes -->
    <text x="275" y="22" text-anchor="middle" font-size="9" fill="#507080" font-weight="700" letter-spacing="1">SUPERIOR DERECHO</text>
    <text x="825" y="22" text-anchor="middle" font-size="9" fill="#507080" font-weight="700" letter-spacing="1">SUPERIOR IZQUIERDO</text>
    <text x="275" y="600" text-anchor="middle" font-size="9" fill="#507080" font-weight="700" letter-spacing="1">INFERIOR DERECHO</text>
    <text x="825" y="600" text-anchor="middle" font-size="9" fill="#507080" font-weight="700" letter-spacing="1">INFERIOR IZQUIERDO</text>

    <!-- Etiquetas V/L/P -->
    <text x="12" y="80" font-size="8" fill="#4A6070" font-weight="700">V</text>
    <text x="12" y="140" font-size="8" fill="#4A6070" font-weight="700">P</text>
    <text x="12" y="200" font-size="8" fill="#4A6070" font-weight="700">V</text>

    <text x="12" y="370" font-size="8" fill="#4A6070" font-weight="700">V</text>
    <text x="12" y="430" font-size="8" fill="#4A6070" font-weight="700">L</text>
    <text x="12" y="490" font-size="8" fill="#4A6070" font-weight="700">V</text>

    <?php
    // ═══════════════════════════════════════════════════════════
    // DEFINICIÓN DE DIENTES — Sistema FDI
    // Formato: [numero, cx, cy_centro, tipo]
    // tipo: I=incisivo, C=canino, PM=premolar, M=molar
    // Cuadrante 1: 11-18 (superior derecho)
    // Cuadrante 2: 21-28 (superior izquierdo)
    // Cuadrante 3: 31-38 (inferior izquierdo)
    // Cuadrante 4: 41-48 (inferior derecho)
    // ═══════════════════════════════════════════════════════════

    // Posiciones X para superiores (de derecha a izquierda para cuad 1, izquierda a derecha para cuad 2)
    // Cuadrante 1: 18(izq) → 11(centro)  Cuadrante 2: 21(centro) → 28(der)
    $dx = 63; // spacing entre dientes
    $x_centro = 550; // línea media

    // Sup Der (18→11): posiciones a la IZQUIERDA del centro
    $sup_der = [18,17,16,15,14,13,12,11];
    // Sup Izq (21→28): posiciones a la DERECHA del centro
    $sup_izq = [21,22,23,24,25,26,27,28];
    // Inf Izq (31→38): posiciones a la DERECHA del centro (inferior izquierdo = mismо lado que 21-28)
    $inf_izq = [31,32,33,34,35,36,37,38];
    // Inf Der (41→48): posiciones a la IZQUIERDA del centro
    $inf_der = [48,47,46,45,44,43,42,41];

    // Tipo de cada diente (para forma SVG)
    $tipo_diente = [
        11=>'I', 12=>'I', 13=>'C', 14=>'PM', 15=>'PM', 16=>'M', 17=>'M', 18=>'M',
        21=>'I', 22=>'I', 23=>'C', 24=>'PM', 25=>'PM', 26=>'M', 27=>'M', 28=>'M',
        31=>'I', 32=>'I', 33=>'C', 34=>'PM', 35=>'PM', 36=>'M', 37=>'M', 38=>'M',
        41=>'I', 42=>'I', 43=>'C', 44=>'PM', 45=>'PM', 46=>'M', 47=>'M', 48=>'M',
    ];

    // Colores de estados
    $col_map = [
        'caries'     => '#E05252',
        'obturado'   => '#00D4EE',
        'ausente'    => '#F5A623',
        'endodoncia' => '#8B5CF6',
        'corona'     => '#F59E0B',
        'implante'   => '#10B981',
        'fractura'   => '#EF4444',
        'presupuesto'=> '#3B82F6',
        'sellante'   => '#EC4899',
        'protesis'   => '#6366F1',
        'brackets'   => '#06B6D4',
        'sano'       => 'none',
    ];
    // Color override por color-param
    $col_color = ['rojo'=>'#E05252','azul'=>'#00D4EE','negro'=>'#607080','verde'=>'#10B981'];

    // Posiciones calculadas
    // Superiores: fila de coronas en y≈120, círculo cara en y≈200
    // Inferiores: círculo cara en y≈355, coronas en y≈435
    $y_sup_corona = 120;  // centro de la corona superior
    $y_sup_cara   = 205;  // centro del círculo de caras superior
    $y_inf_cara   = 355;  // círculo inferior
    $y_inf_corona = 440;  // corona inferior

    function getToothX(int $num, int $x_centro, int $dx): float {
        $sup_der = [18,17,16,15,14,13,12,11];
        $sup_izq = [21,22,23,24,25,26,27,28];
        $inf_izq = [31,32,33,34,35,36,37,38];
        $inf_der = [48,47,46,45,44,43,42,41];
        
        if (in_array($num, $sup_der)) {
            $idx = array_search($num, array_reverse($sup_der));
            return $x_centro - 30 - ($idx * $dx);
        }
        if (in_array($num, $sup_izq)) {
            $idx = array_search($num, $sup_izq);
            return $x_centro + 30 + ($idx * $dx);
        }
        if (in_array($num, $inf_izq)) {
            $idx = array_search($num, $inf_izq);
            return $x_centro + 30 + ($idx * $dx);
        }
        if (in_array($num, $inf_der)) {
            $idx = array_search($num, array_reverse($inf_der));
            return $x_centro - 30 - ($idx * $dx);
        }
        return $x_centro;
    }

    function renderToothSVG(int $num, float $cx, float $cy_corona, float $cy_cara, string $tipo, bool $inferior, array $dientes_db, array $col_map, array $col_color): void {
        $estados = $dientes_db[(string)$num] ?? [];
        
        // Color principal del diente
        $main_color = 'none';
        $main_estado = '';
        foreach ($estados as $e) {
            if ($e['cara'] === 'total' || $e['cara'] === 'oclusal') {
                $main_color = $col_color[$e['color']] ?? ($col_map[$e['estado']] ?? 'none');
                $main_estado = $e['estado'];
                break;
            }
        }
        if (!$main_color && $estados) {
            $main_color = $col_color[$estados[0]['color']] ?? ($col_map[$estados[0]['estado']] ?? 'none');
            $main_estado = $estados[0]['estado'];
        }

        // Tamaño por tipo de diente
        $w_map  = ['I'=>16,'C'=>17,'PM'=>19,'M'=>24];
        $h_map  = ['I'=>55,'C'=>60,'PM'=>52,'M'=>50];
        $r_map  = ['I'=>3, 'C'=>3, 'PM'=>4,  'M'=>5];
        $w = $w_map[$tipo] ?? 18;
        $h = $h_map[$tipo] ?? 50;
        $r = $r_map[$tipo] ?? 3;

        // Para inferiores, la corona apunta hacia abajo (invertida)
        $cy_root  = $inferior ? $cy_corona - $h/2 - 10 : $cy_corona + $h/2 + 8;
        $root_h   = ['I'=>22,'C'=>28,'PM'=>24,'M'=>20][$tipo] ?? 22;
        $root_w   = ['I'=>6, 'C'=>7, 'PM'=>8, 'M'=>10][$tipo] ?? 7;
        $roots    = ['I'=>1, 'C'=>1, 'PM'=>1, 'M'=>3][$tipo] ?? 1;

        echo "<g class='tooth-group' data-num='$num' data-tipo='$tipo' onclick='clickDiente(this)' data-estados='".htmlspecialchars(json_encode($estados))."'>";

        // ── CORONA DEL DIENTE ──
        $fill_corona = $main_estado === 'ausente' ? 'rgba(245,166,35,.15)' : ($main_color !== 'none' ? $main_color.'33' : 'url(#toothShade)');
        $stroke_corona = $main_color !== 'none' && $main_estado !== 'sano' ? $main_color : '#8899A6';
        $sw = $main_estado !== 'sano' && $main_color !== 'none' ? '2' : '1';

        if ($main_estado === 'ausente') {
            // Cruz para diente ausente
            echo "<rect x='".($cx-$w/2)."' y='".($cy_corona-$h/2)."' width='$w' height='$h' rx='$r' fill='rgba(245,166,35,.08)' stroke='#F5A623' stroke-width='1.5' stroke-dasharray='4,2'/>";
            echo "<line x1='".($cx-8)."' y1='".($cy_corona-8)."' x2='".($cx+8)."' y2='".($cy_corona+8)."' stroke='#F5A623' stroke-width='2'/>";
            echo "<line x1='".($cx+8)."' y1='".($cy_corona-8)."' x2='".($cx-8)."' y2='".($cy_corona+8)."' stroke='#F5A623' stroke-width='2'/>";
        } else {
            // Corona normal
            echo "<rect class='tooth-bg' x='".($cx-$w/2)."' y='".($cy_corona-$h/2)."' width='$w' height='$h' rx='$r' fill='$fill_corona' stroke='$stroke_corona' stroke-width='$sw' filter='url(#dShadow)'/>";

            // Raíces
            $rys  = $inferior ? $cy_corona - $h/2 - $root_h : $cy_corona + $h/2;
            $rdx  = $roots > 1 ? ($roots === 3 ? [$cx-$root_w, $cx, $cx+$root_w] : [$cx-$root_w/2, $cx+$root_w/2]) : [$cx];
            foreach ($rdx as $rx) {
                $ry_end = $inferior ? $rys : $rys + $root_h;
                $ry_start = $inferior ? $rys + $root_h : $rys;
                echo "<path d='M ".($rx-$root_w/2)." $ry_start Q $rx ".($inferior ? $rys - 8 : $ry_end + 8)." ".($rx+$root_w/2)." $ry_end' fill='none' stroke='#8899A6' stroke-width='1.2' stroke-linecap='round'/>";
            }

            // Símbolo endodoncia
            if ($main_estado === 'endodoncia') {
                echo "<line x1='$cx' y1='".($cy_corona-$h/2+4)."' x2='$cx' y2='".($cy_corona+$h/2-4)."' stroke='#8B5CF6' stroke-width='2.5' stroke-linecap='round'/>";
            }
            // Símbolo corona (círculo encima)
            if ($main_estado === 'corona') {
                $cap_y = $inferior ? $cy_corona + $h/2 + 3 : $cy_corona - $h/2 - 3;
                echo "<rect x='".($cx-$w/2+1)."' y='".($cap_y - ($inferior?0:6))."' width='".($w-2)."' height='6' rx='1' fill='#F59E0B' stroke='#F59E0B' stroke-width='0'/>";
            }
            // Símbolo implante
            if ($main_estado === 'implante') {
                echo "<line x1='$cx' y1='".($cy_corona-4)."' x2='$cx' y2='".($cy_corona+4)."' stroke='#10B981' stroke-width='3'/>";
                echo "<line x1='".($cx-4)."' y1='$cy_corona' x2='".($cx+4)."' y2='$cy_corona' stroke='#10B981' stroke-width='3'/>";
            }
            // Símbolo fractura
            if ($main_estado === 'fractura') {
                echo "<polyline points='".($cx-3).",".($cy_corona-8)." $cx,".($cy_corona)." ".($cx+3).",".($cy_corona+8)."' fill='none' stroke='#EF4444' stroke-width='2.5' stroke-linecap='round'/>";
            }
        }

        // ── CÍRCULO DE CARAS (FDI) ──
        $r_circ = $tipo === 'M' ? 16 : ($tipo === 'PM' ? 14 : 13);
        $circ_fill = 'rgba(30,43,60,.8)';
        $circ_stroke = $main_estado !== 'sano' && $main_color !== 'none' ? $main_color : '#607080';
        $circ_sw = $main_estado !== 'sano' && $main_color !== 'none' ? '2' : '1.5';

        echo "<circle cx='$cx' cy='$cy_cara' r='$r_circ' fill='$circ_fill' stroke='$circ_stroke' stroke-width='$circ_sw'/>";

        // Caras marcadas (5 segmentos: V=top, L=bottom, M=left, D=right, O=center)
        $cara_faces = [
            'vestibular' => ['M', $cx-$r_circ, $cy_cara-$r_circ, $cx+$r_circ, $cy_cara-$r_circ, $cx, $cy_cara-$r_circ*0.35],
            'lingual'    => ['M', $cx-$r_circ, $cy_cara+$r_circ, $cx+$r_circ, $cy_cara+$r_circ, $cx, $cy_cara+$r_circ*0.35],
            'mesial'     => ['M', $cx-$r_circ, $cy_cara-$r_circ, $cx-$r_circ, $cy_cara+$r_circ, $cx-$r_circ*0.35, $cy_cara],
            'distal'     => ['M', $cx+$r_circ, $cy_cara-$r_circ, $cx+$r_circ, $cy_cara+$r_circ, $cx+$r_circ*0.35, $cy_cara],
            'oclusal'    => null, // center circle
        ];

        foreach ($estados as $est) {
            if ($est['estado'] === 'sano') continue;
            $c = $col_color[$est['color']] ?? ($col_map[$est['estado']] ?? '#E05252');
            $cara = $est['cara'];

            if ($cara === 'total') {
                // Relleno completo del círculo
                echo "<circle cx='$cx' cy='$cy_cara' r='".($r_circ-2)."' fill='$c' opacity='.75'/>";
            } elseif ($cara === 'oclusal') {
                $r2 = round($r_circ * 0.45);
                echo "<circle cx='$cx' cy='$cy_cara' r='$r2' fill='$c' opacity='.9'/>";
            } elseif ($cara === 'vestibular') {
                $p = $r_circ - 2;
                echo "<path d='M ".($cx-$p).",".($cy_cara-$p)." L ".($cx+$p).",".($cy_cara-$p)." L $cx,".($cy_cara-2)." Z' fill='$c' opacity='.85'/>";
            } elseif ($cara === 'lingual') {
                $p = $r_circ - 2;
                echo "<path d='M ".($cx-$p).",".($cy_cara+$p)." L ".($cx+$p).",".($cy_cara+$p)." L $cx,".($cy_cara+2)." Z' fill='$c' opacity='.85'/>";
            } elseif ($cara === 'mesial') {
                $p = $r_circ - 2;
                echo "<path d='M ".($cx-$p).",".($cy_cara-$p)." L ".($cx-$p).",".($cy_cara+$p)." L ".($cx-2).",$cy_cara Z' fill='$c' opacity='.85'/>";
            } elseif ($cara === 'distal') {
                $p = $r_circ - 2;
                echo "<path d='M ".($cx+$p).",".($cy_cara-$p)." L ".($cx+$p).",".($cy_cara+$p)." L ".($cx+2).",$cy_cara Z' fill='$c' opacity='.85'/>";
            }
        }

        // Líneas de división del círculo
        $lc = 'rgba(96,112,128,.5)'; $lw = '0.8';
        echo "<line x1='$cx' y1='".($cy_cara-$r_circ)."' x2='$cx' y2='".($cy_cara+$r_circ)."' stroke='$lc' stroke-width='$lw'/>";
        echo "<line x1='".($cx-$r_circ)."' y1='$cy_cara' x2='".($cx+$r_circ)."' y2='$cy_cara' stroke='$lc' stroke-width='$lw'/>";
        // Círculo oclusal interno
        echo "<circle cx='$cx' cy='$cy_cara' r='".round($r_circ*0.45)."' fill='none' stroke='$lc' stroke-width='$lw'/>";

        // Número de diente
        $num_y = $inferior ? $cy_cara + $r_circ + 12 : $cy_cara - $r_circ - 6;
        echo "<text class='tooth-num' x='$cx' y='$num_y'>$num</text>";

        echo "</g>"; // tooth-group
    }

    // ── RENDERIZAR TODOS LOS DIENTES ──
    $todos = array_merge($sup_der, $sup_izq, $inf_izq, $inf_der);
    foreach ($todos as $num) {
        $cx = getToothX($num, $x_centro, $dx);
        $tipo = $tipo_diente[$num] ?? 'I';
        $es_inf = $num >= 30;
        $cy_c = $es_inf ? $y_inf_corona : $y_sup_corona;
        $cy_ca = $es_inf ? $y_inf_cara : $y_sup_cara;
        renderToothSVG($num, $cx, $cy_c, $cy_ca, $tipo, $es_inf, $dientes_db, $col_map, $col_color);
    }
    ?>

    <!-- ── DENTICIÓN DECIDUA (temporal) ── oculto por defecto -->
    <g id="gDecidua" style="display:none">
     <!-- Separadores deciduos -->
     <line x1="550" y1="308" x2="550" y2="590" stroke="rgba(245,166,35,.3)" stroke-width="1.5" stroke-dasharray="4,3"/>
     <text x="275" y="318" text-anchor="middle" font-size="8" fill="#F5A623" font-weight="700" letter-spacing="1">TEMPORAL DERECHO</text>
     <text x="825" y="318" text-anchor="middle" font-size="8" fill="#F5A623" font-weight="700" letter-spacing="1">TEMPORAL IZQUIERDO</text>
     <?php
     // Deciduos: sup der 55-51, sup izq 61-65, inf izq 71-75, inf der 85-81
     $dec_sup_der = [55,54,53,52,51];
     $dec_sup_izq = [61,62,63,64,65];
     $dec_inf_izq = [71,72,73,74,75];
     $dec_inf_der = [85,84,83,82,81];
     $dec_tipo = [55=>'M',54=>'PM',53=>'C',52=>'I',51=>'I', 61=>'I',62=>'I',63=>'C',64=>'PM',65=>'M', 71=>'I',72=>'I',73=>'C',74=>'PM',75=>'M', 81=>'I',82=>'I',83=>'C',84=>'PM',85=>'M'];
     $dx_dec = 52;
     $x0_dec = 550;
     $dec_y_sup_c = 380; $dec_y_sup_ca = 450;
     $dec_y_inf_c = 560; $dec_y_inf_ca = 490;

     function getDecX(int $n, int $x0, int $dx): float {
         $sd=[55,54,53,52,51]; $si=[61,62,63,64,65]; $ii=[71,72,73,74,75]; $id=[85,84,83,82,81];
         if(in_array($n,$sd)){$i=array_search($n,array_reverse($sd));return $x0-20-$i*$dx;}
         if(in_array($n,$si)){$i=array_search($n,$si);return $x0+20+$i*$dx;}
         if(in_array($n,$ii)){$i=array_search($n,$ii);return $x0+20+$i*$dx;}
         if(in_array($n,$id)){$i=array_search($n,array_reverse($id));return $x0-20-$i*$dx;}
         return $x0;
     }
     $dec_todos = array_merge($dec_sup_der,$dec_sup_izq,$dec_inf_izq,$dec_inf_der);
     foreach ($dec_todos as $dn) {
         $cx2 = getDecX($dn,$x0_dec,$dx_dec);
         $tip = $dec_tipo[$dn]??'I';
         $es_inf2 = $dn>=70;
         $cy_c2 = $es_inf2 ? $dec_y_inf_c : $dec_y_sup_c;
         $cy_ca2 = $es_inf2 ? $dec_y_inf_ca : $dec_y_sup_ca;
         renderToothSVG($dn,$cx2,$cy_c2,$cy_ca2,$tip,$es_inf2,$dientes_db,$col_map,$col_color);
     }
     ?>
    </g>
   </svg>
  </div>

  <!-- Observaciones y guardado -->
  <div class="row g-3 mt-2">
   <div class="col-12 col-md-8">
    <div class="card p-3">
     <label class="form-label">📝 Observaciones generales del odontograma</label>
     <textarea name="observaciones" class="form-control" rows="2" placeholder="Observaciones clínicas generales..."><?= e($odont['observaciones'] ?? '') ?></textarea>
    </div>
   </div>
   <div class="col-12 col-md-4">
    <div class="card p-3 h-100 d-flex flex-column justify-content-between">
     <div style="font-size:12px;color:var(--t2)" id="contadorMarcas">Marcas: 0 dientes</div>
     <div class="d-flex gap-2 mt-2">
      <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-floppy me-2"></i>Guardar odontograma</button>
      <button type="button" class="btn btn-del" onclick="limpiarTodo()" title="Limpiar"><i class="bi bi-eraser"></i></button>
     </div>
     <?php if ($historial): ?>
     <div class="mt-2">
      <small style="color:var(--t2)">Historial:</small>
      <?php foreach (array_slice($historial,0,3) as $h): ?>
      <a href="?paciente_id=<?=$paciente_id?>&hc_id=<?=$h['hc_id']?>" class="badge bgr d-inline-block mt-1 me-1" style="text-decoration:none"><?=fDate($h['fecha'])?></a>
      <?php endforeach; ?>
     </div>
     <?php endif; ?>
    </div>
   </div>
  </div>
 </div>
</div>
</form>

<?php
$dientes_js = json_encode($dientes_db);
$xscript = "<script>\nlet dientesData=" . $dientes_js . ";\n" . <<<'JSRAW'
let dienteActivo = null;

const colMap = {
    caries:'#E05252', obturado:'#00D4EE', ausente:'#F5A623',
    endodoncia:'#8B5CF6', corona:'#F59E0B', implante:'#10B981',
    fractura:'#EF4444', presupuesto:'#3B82F6', sellante:'#EC4899',
    protesis:'#6366F1', brackets:'#06B6D4', sano:'none'
};
const colColor = { rojo:'#E05252', azul:'#00D4EE', negro:'#607080', verde:'#10B981' };
const nombreEstado = {
    caries:'Caries', obturado:'Obturado/Restaurado', ausente:'Ausente/Extraído',
    endodoncia:'Endodoncia', corona:'Corona', implante:'Implante',
    fractura:'Fractura', presupuesto:'Presupuesto', sellante:'Sellante',
    protesis:'Prótesis/Puente', brackets:'Brackets/Ortodoncia', sano:'Sano'
};
const nombreDiente = {
    11:'Incisivo Central Sup Der', 12:'Incisivo Lateral Sup Der', 13:'Canino Sup Der',
    14:'1° Premolar Sup Der', 15:'2° Premolar Sup Der', 16:'1° Molar Sup Der',
    17:'2° Molar Sup Der', 18:'3° Molar (Cordal) Sup Der',
    21:'Incisivo Central Sup Izq', 22:'Incisivo Lateral Sup Izq', 23:'Canino Sup Izq',
    24:'1° Premolar Sup Izq', 25:'2° Premolar Sup Izq', 26:'1° Molar Sup Izq',
    27:'2° Molar Sup Izq', 28:'3° Molar (Cordal) Sup Izq',
    31:'Incisivo Central Inf Izq', 32:'Incisivo Lateral Inf Izq', 33:'Canino Inf Izq',
    34:'1° Premolar Inf Izq', 35:'2° Premolar Inf Izq', 36:'1° Molar Inf Izq',
    37:'2° Molar Inf Izq', 38:'3° Molar (Cordal) Inf Izq',
    41:'Incisivo Central Inf Der', 42:'Incisivo Lateral Inf Der', 43:'Canino Inf Der',
    44:'1° Premolar Inf Der', 45:'2° Premolar Inf Der', 46:'1° Molar Inf Der',
    47:'2° Molar Inf Der', 48:'3° Molar (Cordal) Inf Der'
};

// ── HERRAMIENTA ──────────────────────────────────────────────
function setHerramienta(h) {
    herramienta = h;
    document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('tool-'+h);
    if (btn) btn.classList.add('active');
}

function setColor(c) {
    colorActual = c;
    document.querySelectorAll('[id^="col-"]').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('col-'+c);
    if (btn) btn.classList.add('active');
}

function setTipo(t) {
    tipoDentadura = t;
    document.getElementById('btnPerm').className = 'btn btn-sm flex-fill ' + (t==='permanente'?'btn-primary':'btn-dk');
    document.getElementById('btnTemp').className = 'btn btn-sm flex-fill ' + (t==='temporal'?'btn-primary':'btn-dk');
    document.getElementById('gDecidua').style.display = t==='temporal' ? 'block' : 'none';
}

// ── CLICK EN DIENTE ──────────────────────────────────────────
function clickDiente(g) {
    const num = g.dataset.num;
    dienteActivo = num;
    const cara = document.getElementById('selCara').value;

    if (!dientesData[num]) dientesData[num] = [];

    if (herramienta === 'sano') {
        // Borrar marca de esa cara (o todas si es total)
        if (cara === 'total') {
            dientesData[num] = [];
        } else {
            dientesData[num] = dientesData[num].filter(e => e.cara !== cara);
        }
    } else {
        // Quitar marca existente de esa cara
        dientesData[num] = dientesData[num].filter(e => e.cara !== cara && !(cara==='total'));
        // Agregar nueva
        dientesData[num].push({
            cara: cara,
            estado: herramienta,
            color: colorActual,
            notas: ''
        });
    }

    redrawTooth(num, g);
    updateToothInfo(num);
    saveData();
}

// ── REDIBUJAR DIENTE ─────────────────────────────────────────
function redrawTooth(num, g) {
    const estados = dientesData[num] || [];
    const circle  = g.querySelector('circle');
    const rect    = g.querySelector('rect');
    if (!circle) return;

    // Color principal
    let mainColor = null, mainEst = null;
    for (const e of estados) {
        if (e.cara === 'total' || e.cara === 'oclusal') {
            mainColor = colColor[e.color] || colMap[e.estado];
            mainEst = e.estado; break;
        }
    }
    if (!mainColor && estados.length) {
        mainColor = colColor[estados[0].color] || colMap[estados[0].estado];
        mainEst = estados[0].estado;
    }

    // Actualizar corona
    if (rect) {
        if (mainEst === 'ausente') {
            rect.setAttribute('fill','rgba(245,166,35,.08)');
            rect.setAttribute('stroke','#F5A623');
        } else if (mainColor && mainColor !== 'none') {
            rect.setAttribute('fill', mainColor+'33');
            rect.setAttribute('stroke', mainColor);
            rect.setAttribute('stroke-width','2');
        } else {
            rect.setAttribute('fill','url(#toothShade)');
            rect.setAttribute('stroke','#8899A6');
            rect.setAttribute('stroke-width','1');
        }
    }

    // Actualizar círculo de caras
    const r = parseFloat(circle.getAttribute('r'));
    const cx = parseFloat(circle.getAttribute('cx'));
    const cy = parseFloat(circle.getAttribute('cy'));
    
    // Limpiar segmentos previos
    g.querySelectorAll('.face-seg').forEach(el => el.remove());

    // Dibujar segmentos
    for (const e of estados) {
        if (e.estado === 'sano') continue;
        const c = colColor[e.color] || colMap[e.estado] || '#E05252';
        const seg = drawFaceSegment(cx, cy, r, e.cara, c);
        if (seg) {
            seg.classList.add('face-seg');
            circle.after(seg);
        }
    }

    // Actualizar stroke del círculo
    if (mainColor && mainColor !== 'none') {
        circle.setAttribute('stroke', mainColor);
        circle.setAttribute('stroke-width', '2');
    } else {
        circle.setAttribute('stroke', '#607080');
        circle.setAttribute('stroke-width', '1.5');
    }
}

function drawFaceSegment(cx, cy, r, cara, color) {
    const ns = 'http://www.w3.org/2000/svg';
    const p = r - 2;
    let el;
    
    if (cara === 'total') {
        el = document.createElementNS(ns,'circle');
        el.setAttribute('cx', cx); el.setAttribute('cy', cy);
        el.setAttribute('r', p); el.setAttribute('fill', color);
        el.setAttribute('opacity','0.75');
    } else if (cara === 'oclusal') {
        el = document.createElementNS(ns,'circle');
        el.setAttribute('cx', cx); el.setAttribute('cy', cy);
        el.setAttribute('r', Math.round(r*0.45)); el.setAttribute('fill', color);
        el.setAttribute('opacity','0.9');
    } else if (cara === 'vestibular') {
        el = document.createElementNS(ns,'path');
        el.setAttribute('d',`M ${cx-p},${cy-p} L ${cx+p},${cy-p} L ${cx},${cy-2} Z`);
        el.setAttribute('fill',color); el.setAttribute('opacity','0.85');
    } else if (cara === 'lingual') {
        el = document.createElementNS(ns,'path');
        el.setAttribute('d',`M ${cx-p},${cy+p} L ${cx+p},${cy+p} L ${cx},${cy+2} Z`);
        el.setAttribute('fill',color); el.setAttribute('opacity','0.85');
    } else if (cara === 'mesial') {
        el = document.createElementNS(ns,'path');
        el.setAttribute('d',`M ${cx-p},${cy-p} L ${cx-p},${cy+p} L ${cx-2},${cy} Z`);
        el.setAttribute('fill',color); el.setAttribute('opacity','0.85');
    } else if (cara === 'distal') {
        el = document.createElementNS(ns,'path');
        el.setAttribute('d',`M ${cx+p},${cy-p} L ${cx+p},${cy+p} L ${cx+2},${cy} Z`);
        el.setAttribute('fill',color); el.setAttribute('opacity','0.85');
    }
    return el || null;
}

// ── INFO PANEL ───────────────────────────────────────────────
function updateToothInfo(num) {
    const info = document.getElementById('toothInfo');
    const nota = document.getElementById('notaDiente');
    const estados = dientesData[num] || [];
    const nombre = nombreDiente[num] || ('Diente '+num);

    let html = '<div style="font-weight:700;color:var(--c);font-size:13px;margin-bottom:8px">🦷 '+num+' — '+nombre+'</div>';
    
    if (!estados.length) {
        html += '<div style="color:var(--t2);font-size:12px">Sin marcas</div>';
    } else {
        for (const e of estados) {
            const c = colColor[e.color] || colMap[e.estado] || '#607080';
            html += `<div style="display:flex;align-items:center;gap:6px;font-size:11px;margin-bottom:4px">
                <div style="width:10px;height:10px;border-radius:2px;background:${c};flex-shrink:0"></div>
                <strong style="color:var(--t)">${nombreEstado[e.estado]||e.estado}</strong>
                <span style="color:var(--t2)">· ${e.cara}</span>
            </div>`;
        }
    }
    info.innerHTML = html;
    
    // Nota
    nota.disabled = false;
    const notas = estados.map(e=>e.notas).filter(n=>n).join('; ');
    nota.value = notas;
}

function saveNota() {
    if (!dienteActivo) return;
    const nota = document.getElementById('notaDiente').value;
    if (dientesData[dienteActivo]) {
        dientesData[dienteActivo].forEach(e => e.notas = nota);
        saveData();
    }
}

// ── GUARDAR ──────────────────────────────────────────────────
function saveData() {
    document.getElementById('datosJson').value = JSON.stringify(dientesData);
    // Contar marcas
    let cnt = Object.values(dientesData).filter(v=>v.length>0).length;
    document.getElementById('contadorMarcas').textContent = 'Marcas: '+cnt+' diente'+(cnt!==1?'s':'');
}

function limpiarTodo() {
    if (!confirm('¿Limpiar todo el odontograma? Esta acción no se puede deshacer.')) return;
    dientesData = {};
    document.querySelectorAll('.tooth-group').forEach(g => {
        const rect = g.querySelector('rect');
        if (rect) { rect.setAttribute('fill','url(#toothShade)'); rect.setAttribute('stroke','#8899A6'); rect.setAttribute('stroke-width','1'); }
        const circ = g.querySelector('circle');
        if (circ) { circ.setAttribute('stroke','#607080'); circ.setAttribute('stroke-width','1.5'); }
        g.querySelectorAll('.face-seg').forEach(el=>el.remove());
    });
    document.getElementById('datosJson').value = '{}';
    document.getElementById('contadorMarcas').textContent = 'Marcas: 0 dientes';
    document.getElementById('toothInfo').innerHTML = '<div style="color:var(--t2);font-size:12px;text-align:center;padding-top:20px">Haz clic en un diente</div>';
}

// ── INIT ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Activar herramienta por defecto
    setHerramienta('caries');
    setColor('rojo');
    document.getElementById('btnPerm').className = 'btn btn-sm flex-fill btn-primary';
    document.getElementById('btnTemp').className = 'btn btn-sm flex-fill btn-dk';
    
    // Redibujar dientes con datos cargados del servidor
    Object.keys(dientesData).forEach(num => {
        const g = document.querySelector(`.tooth-group[data-num="${num}"]`);
        if (g) redrawTooth(num, g);
    });
    saveData();
});
</script>
JS;
JSRAW;

require_once __DIR__.'/../includes/footer.php';
