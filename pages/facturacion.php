<?php
/**
 * facturacion.php — Emisión de comprobantes electrónicos (boleta/factura) SUNAT.
 * Módulo separado de Caja y Pagos: vincula ítems a inventario, descuenta stock
 * y orquesta el flujo XML → envío SUNAT → CDR.
 */
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/config_sunat.php';
require_once __DIR__.'/../includes/sunat/SunatService.php';
requiereRol('admin','contador','recepcion');

$accion = $_GET['accion'] ?? 'lista';
$id     = (int)($_GET['id'] ?? 0);

// ─── POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
 $ap = $_POST['accion'] ?? '';

 if ($ap==='emitir') {
  $tipo = $_POST['tipo_comprobante'] ?? 'boleta';
  if (!in_array($tipo,['boleta','factura','nota_venta'],true)) { flash('error','Tipo de comprobante inválido.'); go('pages/facturacion.php'); }

  $pac_id = (int)$_POST['paciente_id'];
  $pac = db()->prepare("SELECT * FROM pacientes WHERE id=?"); $pac->execute([$pac_id]); $pac = $pac->fetch();
  if (!$pac) { flash('error','Paciente no encontrado.'); go('pages/facturacion.php?accion=nueva'); }
  if ($tipo==='factura' && empty($pac['ruc'])) {
   flash('error','El paciente no tiene RUC registrado. Edita su ficha o emite boleta.');
   go('pages/facturacion.php?accion=nueva&paciente_id='.$pac_id);
  }

  // Items
  $invs   = $_POST['inventario_id'] ?? [];
  $concs  = $_POST['concepto']      ?? [];
  $cants  = $_POST['cantidad']      ?? [];
  $precis = $_POST['precio']        ?? [];
  $items  = [];
  foreach ($concs as $i=>$c) {
   $c = trim((string)$c);
   $cant = (float)($cants[$i] ?? 0);
   $pr   = (float)($precis[$i] ?? 0);
   $iid  = (int)($invs[$i] ?? 0) ?: null;
   if ($c==='' || $cant<=0 || $pr<0) continue;
   $items[] = ['inv'=>$iid,'concepto'=>$c,'cantidad'=>$cant,'precio'=>$pr,'subtotal'=>round($cant*$pr,2)];
  }
  if (!$items) { flash('error','Agrega al menos un ítem válido.'); go('pages/facturacion.php?accion=nueva'); }

  // Validar stock antes de descontar
  foreach ($items as $it) {
   if (!$it['inv']) continue;
   $st = db()->prepare("SELECT nombre,stock_actual FROM inventario WHERE id=?");
   $st->execute([$it['inv']]); $inv = $st->fetch();
   if ($inv && (float)$inv['stock_actual'] < $it['cantidad']) {
    flash('error','Stock insuficiente de "'.$inv['nombre'].'" (disponible: '.$inv['stock_actual'].').');
    go('pages/facturacion.php?accion=nueva&paciente_id='.$pac_id);
   }
  }

  $sub  = array_sum(array_column($items,'subtotal'));
  $desc = max(0,(float)($_POST['descuento'] ?? 0));
  $tot  = max(0,$sub - $desc);

  // Correlativo desde la tabla documentos_empresa (gestionable desde admin)
  $cor = siguienteCorrelativo($tipo);
  if (!$cor) {
   flash('error','No hay serie activa para "'.$tipo.'". Configúrala en Admin → Series y Correlativos.');
   go('pages/facturacion.php?accion=nueva&paciente_id='.$pac_id);
  }
  $serie  = $cor['serie'];
  $numero = $cor['numero'];

  // Caja abierta (opcional, igual que pagos.php)
  $caja = db()->query("SELECT id FROM cajas WHERE estado='abierta' ORDER BY fecha_apertura DESC LIMIT 1")->fetchColumn();
  if (!$caja) {
   db()->prepare("INSERT INTO cajas(usuario_id,fecha_apertura,monto_inicial,estado)VALUES(?,NOW(),0,'abierta')")->execute([$_SESSION['uid']]);
   $caja = db()->lastInsertId();
  }

  $prefijos = ['boleta'=>'BOL', 'factura'=>'FAC', 'nota_venta'=>'NV'];
  $cod = genCodigo($prefijos[$tipo] ?? 'DOC', 'pagos');
  db()->beginTransaction();
  try {
   db()->prepare("
    INSERT INTO pagos(codigo,paciente_id,caja_id,fecha,subtotal,descuento,total,metodo,referencia,
                      tipo_comprobante,serie,numero,estado,notas,created_by)
    VALUES(?,?,?,NOW(),?,?,?,?,?,?,?,?,'pagado',?,?)
   ")->execute([
    $cod,$pac_id,$caja,$sub,$desc,$tot,
    $_POST['metodo']??'efectivo',$_POST['referencia']??'',
    $tipo,$serie,$numero,trim($_POST['notas']??''),$_SESSION['uid']
   ]);
   $pid = (int)db()->lastInsertId();

   $stIns = db()->prepare("INSERT INTO pago_detalles(pago_id,inventario_id,concepto,cantidad,precio,subtotal)VALUES(?,?,?,?,?,?)");
   $stkUp = db()->prepare("UPDATE inventario SET stock_actual = stock_actual - ? WHERE id=?");
   foreach ($items as $it) {
    $stIns->execute([$pid,$it['inv'],$it['concepto'],$it['cantidad'],$it['precio'],$it['subtotal']]);
    if ($it['inv']) $stkUp->execute([$it['cantidad'],$it['inv']]);
   }
   db()->commit();
  } catch (Throwable $e) {
   db()->rollBack();
   flash('error','No se pudo emitir: '.$e->getMessage());
   go('pages/facturacion.php?accion=nueva&paciente_id='.$pac_id);
  }
  auditar('EMITIR_COMPROBANTE','pagos',$pid);

  // Solo boleta/factura van a SUNAT. Nota de venta es interna.
  if (in_array($tipo, ['boleta','factura'], true)) {
   $r = (new SunatService(db()))->generarXml($pid);
   $extra = $r['ok'] ? ' · XML generado, listo para enviar.' : ' · XML falló: '.$r['mensaje'];
   flash($r['ok']?'ok':'warn',"$cod emitido por ".mon($tot).$extra);
  } else {
   flash('ok',"$cod (Nota de venta) emitida por ".mon($tot).' · No se envía a SUNAT.');
  }
  go("pages/facturacion.php?accion=ver&id=$pid");
 }

 if ($ap==='enviar_sunat') {
  $pid = (int)$_POST['id'];
  $r = (new SunatService(db()))->enviarSunat($pid);
  flash($r['ok']?'ok':'error', ($r['ok']?'SUNAT aceptó: ':'SUNAT rechazó: ').$r['mensaje']);
  go("pages/facturacion.php?accion=ver&id=$pid");
 }

 if ($ap==='regenerar') {
  $pid = (int)$_POST['id'];
  $r = (new SunatService(db()))->generarXml($pid);
  flash($r['ok']?'ok':'error', $r['ok']?'XML regenerado.':'Error: '.$r['mensaje']);
  go("pages/facturacion.php?accion=ver&id=$pid");
 }

 if ($ap==='anular') {
  $pid = (int)$_POST['id'];
  db()->prepare("UPDATE pagos SET estado='anulado' WHERE id=?")->execute([$pid]);
  auditar('ANULAR_COMPROBANTE','pagos',$pid);
  flash('warn','Comprobante anulado en sistema. Para SUNAT genera una nota de crédito.');
  go("pages/facturacion.php?accion=ver&id=$pid");
 }
}

// ─── DESCARGAS XML / CDR (antes de imprimir HTML) ─────────────────
if (in_array($accion,['xml','cdr'],true) && $id) {
 $st = db()->prepare("SELECT tipo_comprobante,serie,numero,sunat_xml,sunat_cdr FROM pagos WHERE id=?");
 $st->execute([$id]); $v = $st->fetch();
 if (!$v) { http_response_code(404); echo 'No encontrado.'; exit; }
 if (!in_array($v['tipo_comprobante'],['factura','boleta'],true)) { http_response_code(400); echo 'No SUNAT.'; exit; }
 $base = SunatService::nombreArchivo($v);
 if ($accion==='xml') {
  if (empty($v['sunat_xml'])) { http_response_code(404); echo 'Sin XML.'; exit; }
  header('Content-Type: application/xml; charset=utf-8');
  if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="'.$base.'.xml"');
  echo $v['sunat_xml']; exit;
 }
 if (empty($v['sunat_cdr'])) { http_response_code(404); echo 'Sin CDR.'; exit; }
 $bin = base64_decode($v['sunat_cdr'],true);
 header('Content-Type: application/zip');
 header('Content-Disposition: attachment; filename="R-'.$base.'.zip"');
 echo $bin!==false ? $bin : $v['sunat_cdr']; exit;
}

// ─── PDF (genera token público y redirige a la ruta abierta) ──────
if ($accion==='pdf' && $id) {
 $st = db()->prepare("SELECT pdf_token,tipo_comprobante FROM pagos WHERE id=?");
 $st->execute([$id]); $row = $st->fetch();
 if (!$row || !in_array($row['tipo_comprobante'],['boleta','factura','nota_venta'],true)) {
  flash('error','Comprobante no encontrado.'); go('pages/facturacion.php');
 }
 $token = $row['pdf_token'];
 if (!$token) {
  $token = bin2hex(random_bytes(20)); // 40 chars hex
  db()->prepare("UPDATE pagos SET pdf_token=? WHERE id=?")->execute([$token,$id]);
 }
 $url = BASE_URL.'/pages/comprobante_pdf.php?token='.$token.(isset($_GET['dl'])?'&dl=1':'');
 header('Location: '.$url); exit;
}

// ─── LISTA ─────────────────────────────────────────────────────────
if ($accion==='lista') {
 $titulo='Facturación Electrónica'; $pagina_activa='fact';
 $fdesde = $_GET['desde'] ?? date('Y-m-01');
 $fhasta = $_GET['hasta'] ?? date('Y-m-d');
 $tipo   = $_GET['tipo']  ?? '';
 $sun    = $_GET['sun']   ?? '';
 $q      = trim($_GET['q'] ?? '');

 $w = "WHERE p.tipo_comprobante IN('boleta','factura','nota_venta') AND DATE(p.fecha) BETWEEN ? AND ?";
 $pm = [$fdesde,$fhasta];
 if ($tipo)            { $w .= " AND p.tipo_comprobante=?"; $pm[]=$tipo; }
 if ($sun==='sin_xml') { $w .= " AND (p.sunat_xml IS NULL OR p.sunat_xml='')"; }
 elseif ($sun)         { $w .= " AND p.sunat_estado=?"; $pm[]=$sun; }
 if ($q)               { $w .= " AND (pa.nombres LIKE ? OR pa.apellido_paterno LIKE ? OR p.codigo LIKE ? OR pa.dni LIKE ? OR pa.ruc LIKE ?)";
                         $b="%$q%"; for($k=0;$k<5;$k++) $pm[]=$b; }

 $st = db()->prepare("
  SELECT p.*, CONCAT(pa.nombres,' ',pa.apellido_paterno) AS pac, pa.dni, pa.ruc
  FROM pagos p JOIN pacientes pa ON p.paciente_id=pa.id
  $w ORDER BY p.fecha DESC
 ");
 $st->execute($pm); $lista = $st->fetchAll();

 // KPIs
 $kpi = db()->prepare("
  SELECT
   SUM(CASE WHEN tipo_comprobante='factura' THEN 1 ELSE 0 END) AS n_fac,
   SUM(CASE WHEN tipo_comprobante='boleta'  THEN 1 ELSE 0 END) AS n_bol,
   SUM(CASE WHEN sunat_estado='aceptado'    THEN 1 ELSE 0 END) AS n_ok,
   SUM(CASE WHEN sunat_estado='rechazado'   THEN 1 ELSE 0 END) AS n_rj,
   SUM(CASE WHEN sunat_estado='pendiente'   THEN 1 ELSE 0 END) AS n_pn,
   COALESCE(SUM(CASE WHEN estado='pagado' THEN total ELSE 0 END),0) AS tot
  FROM pagos
  WHERE tipo_comprobante IN('boleta','factura','nota_venta') AND DATE(fecha) BETWEEN ? AND ?
 ");
 $kpi->execute([$fdesde,$fhasta]); $kpi = $kpi->fetch();

 $topbar_act = '<a href="?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva emisión</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row g-3 mb-4">
 <div class="col-6 col-md-3"><div class="kpi kc"><div class="kpi-ico"><i class="bi bi-receipt"></i></div><div class="kpi-v"><?=(int)$kpi['n_bol']?></div><div class="kpi-l">Boletas</div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-md-3"><div class="kpi kb"><div class="kpi-ico"><i class="bi bi-file-earmark-text"></i></div><div class="kpi-v"><?=(int)$kpi['n_fac']?></div><div class="kpi-l">Facturas</div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-md-3"><div class="kpi kg"><div class="kpi-ico"><i class="bi bi-bank"></i></div>
  <div class="kpi-v" style="font-size:18px"><?=(int)$kpi['n_ok']?> <small style="color:var(--t2);font-size:11px">/ <?=(int)$kpi['n_pn']?> pend · <?=(int)$kpi['n_rj']?> rech</small></div>
  <div class="kpi-l">SUNAT aceptados</div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-md-3"><div class="kpi ka"><div class="kpi-ico"><i class="bi bi-cash-stack"></i></div><div class="kpi-v mon" style="font-size:17px"><?=mon((float)$kpi['tot'])?></div><div class="kpi-l">Facturado período</div><div class="kpi-s"></div></div></div>
</div>

<div class="card mb-3 p-3">
 <form method="GET" class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label">Desde</label><input type="date" name="desde" class="form-control" value="<?=e($fdesde)?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">Hasta</label><input type="date" name="hasta" class="form-control" value="<?=e($fhasta)?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">Tipo</label>
   <select name="tipo" class="form-select">
    <option value="">— Todos —</option>
    <option value="boleta"     <?=$tipo==='boleta'?'selected':''?>>Boleta</option>
    <option value="factura"    <?=$tipo==='factura'?'selected':''?>>Factura</option>
    <option value="nota_venta" <?=$tipo==='nota_venta'?'selected':''?>>Nota de venta</option>
   </select>
  </div>
  <div class="col-6 col-md-2"><label class="form-label">Estado SUNAT</label>
   <select name="sun" class="form-select">
    <option value="">— Todos —</option>
    <option value="sin_xml"   <?=$sun==='sin_xml'?'selected':''?>>Sin XML</option>
    <option value="pendiente" <?=$sun==='pendiente'?'selected':''?>>Pendiente</option>
    <option value="aceptado"  <?=$sun==='aceptado'?'selected':''?>>Aceptado</option>
    <option value="rechazado" <?=$sun==='rechazado'?'selected':''?>>Rechazado</option>
   </select>
  </div>
  <div class="col-12 col-md-3"><label class="form-label">Buscar</label><input type="text" name="q" class="form-control" placeholder="Paciente, código, DNI/RUC..." value="<?=e($q)?>"></div>
  <div class="col-12 col-md-1 d-grid"><button type="submit" class="btn btn-dk"><i class="bi bi-search"></i></button></div>
 </form>
</div>

<div class="card"><div class="table-responsive"><table class="table mb-0">
 <thead><tr><th>Comprobante</th><th>Paciente</th><th>Documento</th><th>Fecha</th><th class="text-end">Total</th><th>Estado</th><th>SUNAT</th><th></th></tr></thead>
 <tbody>
 <?php foreach($lista as $pg):
  $se = $pg['sunat_estado']; $tc = $pg['tipo_comprobante'];
  $sc = $se==='aceptado'?'bg':($se==='rechazado'?'br':($se==='pendiente'?'ba':'bgr'));
  $ec = ['pagado'=>'bg','pendiente'=>'ba','anulado'=>'br'];
 ?>
  <tr>
   <td>
    <?php $tipoBadge=['factura'=>'bb','boleta'=>'bc','nota_venta'=>'ba']; $tipoLbl=['factura'=>'FACTURA','boleta'=>'BOLETA','nota_venta'=>'N. VENTA']; ?>
    <span class="badge <?=$tipoBadge[$tc]??'bgr'?>"><?=$tipoLbl[$tc]??strtoupper($tc)?></span>
    <div class="mon" style="font-size:12px;margin-top:2px"><?=e($pg['serie'])?>-<?=str_pad((string)$pg['numero'],8,'0',STR_PAD_LEFT)?></div>
    <small style="color:var(--t2)"><?=e($pg['codigo'])?></small>
   </td>
   <td><strong><?=e($pg['pac'])?></strong></td>
   <td>
    <?php if ($tc==='factura'): ?>
     <small><strong>RUC:</strong> <?=e($pg['ruc']?:'—')?></small>
    <?php else: ?>
     <small><strong>DNI:</strong> <?=e($pg['dni']?:'—')?></small>
    <?php endif; ?>
   </td>
   <td><small><?=fDT($pg['fecha'])?></small></td>
   <td class="mon fw-bold text-end"><?=mon((float)$pg['total'])?></td>
   <td><span class="badge <?=$ec[$pg['estado']]?>"><?=strtoupper($pg['estado'])?></span></td>
   <td><span class="badge <?=$sc?>"><?=$se?strtoupper($se):'SIN XML'?></span></td>
   <td>
    <div class="d-flex gap-1">
     <a href="?accion=ver&id=<?=$pg['id']?>" class="btn btn-dk btn-ico" title="Ver"><i class="bi bi-eye"></i></a>
     <a href="?accion=pdf&id=<?=$pg['id']?>" target="_blank" class="btn btn-dk btn-ico" title="Imprimir / PDF"><i class="bi bi-file-earmark-pdf"></i></a>
     <?php if(!empty($pg['sunat_xml'])): ?>
      <a href="?accion=xml&id=<?=$pg['id']?>" target="_blank" class="btn btn-dk btn-ico" title="Ver XML"><i class="bi bi-file-earmark-code"></i></a>
     <?php endif; ?>
     <?php if(!empty($pg['sunat_xml']) && $se !== 'aceptado'): ?>
      <form method="POST" onsubmit="return confirm('¿Enviar a SUNAT?')" style="display:inline">
       <input type="hidden" name="accion" value="enviar_sunat"><input type="hidden" name="id" value="<?=$pg['id']?>">
       <button type="submit" class="btn btn-primary btn-ico" title="Enviar a SUNAT"><i class="bi bi-send"></i></button>
      </form>
     <?php endif; ?>
     <?php if(!empty($pg['sunat_cdr'])): ?>
      <a href="?accion=cdr&id=<?=$pg['id']?>" class="btn btn-ok btn-ico" title="Descargar CDR"><i class="bi bi-download"></i></a>
     <?php endif; ?>
    </div>
   </td>
  </tr>
 <?php endforeach; if(!$lista): ?>
  <tr><td colspan="8" class="text-center py-4" style="color:var(--t2)">
   <i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:8px"></i>
   No hay comprobantes en este período.
  </td></tr>
 <?php endif; ?>
 </tbody>
</table></div></div>
<?php require_once __DIR__.'/../includes/footer.php';

// ─── DETALLE ───────────────────────────────────────────────────────
} elseif ($accion==='ver' && $id) {
 $st = db()->prepare("
  SELECT p.*, CONCAT(pa.nombres,' ',pa.apellido_paterno,' ',COALESCE(pa.apellido_materno,'')) AS pac,
         pa.dni, pa.ruc, pa.telefono, pa.email, pa.direccion
  FROM pagos p JOIN pacientes pa ON p.paciente_id=pa.id
  WHERE p.id=?");
 $st->execute([$id]); $pago = $st->fetch();
 if (!$pago || !in_array($pago['tipo_comprobante'],['boleta','factura','nota_venta'],true)) {
  flash('error','Comprobante no encontrado.'); go('pages/facturacion.php');
 }
 $dets = db()->prepare("
  SELECT pd.*, i.nombre AS inv_nombre, i.codigo AS inv_codigo
  FROM pago_detalles pd LEFT JOIN inventario i ON pd.inventario_id=i.id
  WHERE pd.pago_id=? ORDER BY pd.id
 ");
 $dets->execute([$id]); $dets = $dets->fetchAll();

 $titulo = strtoupper($pago['tipo_comprobante']).' '.$pago['serie'].'-'.str_pad((string)$pago['numero'],8,'0',STR_PAD_LEFT);
 $pagina_activa = 'fact';
 require_once __DIR__.'/../includes/header.php';

 $se = $pago['sunat_estado']; $tc = $pago['tipo_comprobante'];
 $sc = $se==='aceptado'?'bg':($se==='rechazado'?'br':($se==='pendiente'?'ba':'bgr'));
 $sl = $se ? strtoupper($se) : 'SIN EMITIR';
 $ec = ['pagado'=>'bg','pendiente'=>'ba','anulado'=>'br'];
?>
<div class="row g-4">
 <div class="col-12 col-lg-7">
  <div class="card">
   <div class="card-header">
    <span><i class="bi bi-<?=$tc==='factura'?'file-earmark-text':'receipt'?> me-1"></i><?=e($titulo)?></span>
    <span class="badge <?=$ec[$pago['estado']]?>"><?=strtoupper($pago['estado'])?></span>
   </div>
   <div class="p-4">
    <div class="row g-3 mb-4">
     <div class="col-md-6">
      <small style="color:var(--t2)">Cliente</small>
      <div><strong><?=e($pago['pac'])?></strong></div>
      <?php if($tc==='factura'): ?>
       <small><strong>RUC:</strong> <?=e($pago['ruc']?:'—')?></small>
      <?php else: ?>
       <small><strong>DNI:</strong> <?=e($pago['dni']?:'—')?></small>
      <?php endif; ?>
      <?php if($pago['direccion']): ?><br><small><?=e($pago['direccion'])?></small><?php endif; ?>
     </div>
     <div class="col-md-6 text-md-end">
      <small style="color:var(--t2)">Fecha emisión</small>
      <div class="mon"><?=fDT($pago['fecha'])?></div>
      <small style="color:var(--t2)">Código interno</small>
      <div class="mon" style="color:var(--c);font-size:11px"><?=e($pago['codigo'])?></div>
     </div>
    </div>

    <div class="table-responsive"><table class="table mb-0">
     <thead><tr><th>Concepto / Producto</th><th class="text-center">Cant.</th><th class="text-end">P. Unit.</th><th class="text-end">Subtotal</th></tr></thead>
     <tbody>
     <?php foreach($dets as $d): ?>
      <tr>
       <td>
        <?=e($d['concepto'])?>
        <?php if($d['inv_nombre']): ?>
         <br><small style="color:var(--t2)"><i class="bi bi-box"></i> <?=e($d['inv_codigo'])?> · <?=e($d['inv_nombre'])?></small>
        <?php endif; ?>
       </td>
       <td class="text-center mon"><?=rtrim(rtrim((string)$d['cantidad'],'0'),'.')?></td>
       <td class="text-end mon"><?=mon((float)$d['precio'])?></td>
       <td class="text-end mon fw-bold"><?=mon((float)$d['subtotal'])?></td>
      </tr>
     <?php endforeach; ?>
     </tbody>
    </table></div>

    <div class="mt-3 p-3 rounded" style="background:var(--bg3)">
     <div class="d-flex justify-content-between"><span style="color:var(--t2)">Subtotal</span><span class="mon"><?=mon((float)$pago['subtotal'])?></span></div>
     <?php if($pago['descuento']>0): ?>
      <div class="d-flex justify-content-between"><span style="color:var(--g)">Descuento</span><span class="mon" style="color:var(--g)">-<?=mon((float)$pago['descuento'])?></span></div>
     <?php endif; ?>
     <hr>
     <div class="d-flex justify-content-between align-items-center">
      <strong>TOTAL</strong>
      <span class="mon fw-bold" style="font-size:24px;color:var(--c)"><?=mon((float)$pago['total'])?></span>
     </div>
     <div class="mt-2"><span class="badge bgr"><?=strtoupper($pago['metodo'])?></span>
      <?php if($pago['referencia']): ?><small style="color:var(--t2);margin-left:8px">Ref: <?=e($pago['referencia'])?></small><?php endif; ?>
     </div>
    </div>
    <?php if($pago['notas']): ?><div class="mt-3" style="color:var(--t2);font-size:12px"><?=e($pago['notas'])?></div><?php endif; ?>
   </div>
  </div>
 </div>

 <div class="col-12 col-lg-5">
  <!-- SUNAT -->
  <div class="card mb-4">
   <div class="card-header"><span><i class="bi bi-bank me-1"></i>SUNAT</span><span class="badge <?=$sc?>"><?=$sl?></span></div>
   <div class="p-3">
    <?php if(!empty($pago['sunat_mensaje'])): ?>
     <div class="mb-2" style="font-size:11px;color:var(--t2)"><?=e($pago['sunat_mensaje'])?></div>
    <?php endif; ?>
    <?php if(!empty($pago['sunat_hash'])): ?>
     <div class="mb-3" style="font-size:10px;color:var(--t2);word-break:break-all"><strong>Hash:</strong> <?=e($pago['sunat_hash'])?></div>
    <?php endif; ?>
    <div class="d-grid gap-2">
     <?php if(!empty($pago['sunat_xml'])): ?>
      <div class="d-flex gap-2">
       <a href="?accion=xml&id=<?=$id?>" target="_blank" class="btn btn-dk btn-sm flex-fill"><i class="bi bi-file-earmark-code me-1"></i>Ver XML</a>
       <a href="?accion=xml&id=<?=$id?>&dl=1" class="btn btn-dk btn-sm" title="Descargar XML"><i class="bi bi-download"></i></a>
      </div>
     <?php endif; ?>
     <?php if(!empty($pago['sunat_xml']) && $se !== 'aceptado'): ?>
      <form method="POST" onsubmit="return confirm('¿Enviar este comprobante a SUNAT?')">
       <input type="hidden" name="accion" value="enviar_sunat"><input type="hidden" name="id" value="<?=$id?>">
       <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-send me-1"></i>Enviar a SUNAT</button>
      </form>
     <?php endif; ?>
     <?php if(!empty($pago['sunat_cdr'])): ?>
      <a href="?accion=cdr&id=<?=$id?>" class="btn btn-ok btn-sm"><i class="bi bi-download me-1"></i>Descargar CDR</a>
     <?php endif; ?>
     <?php if(empty($pago['sunat_xml']) || $se==='rechazado'): ?>
      <form method="POST" onsubmit="return confirm('¿Regenerar el XML?')">
       <input type="hidden" name="accion" value="regenerar"><input type="hidden" name="id" value="<?=$id?>">
       <button type="submit" class="btn btn-dk btn-sm w-100"><i class="bi bi-arrow-clockwise me-1"></i>Regenerar XML</button>
      </form>
     <?php endif; ?>
    </div>
   </div>
  </div>

  <?php
   // Genera token público si todavía no existe (un solo UPDATE por comprobante)
   if (empty($pago['pdf_token'])) {
    $pago['pdf_token'] = bin2hex(random_bytes(20));
    db()->prepare("UPDATE pagos SET pdf_token=? WHERE id=?")->execute([$pago['pdf_token'],$id]);
   }
   $proto = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['SERVER_PORT']??'')=='443')) ? 'https' : 'http';
   $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
   $pdfUrl     = $proto.'://'.$host.BASE_URL.'/pages/comprobante_pdf.php?token='.$pago['pdf_token'];
   $pdfUrlA4   = $pdfUrl.'&fmt=a4';
   $pdfUrlTk   = $pdfUrl.'&fmt=ticket';
   $pdfUrlDl   = $pdfUrl.'&fmt=a4&dl=1';
  ?>
  <div class="card mb-4">
   <div class="card-header"><span><i class="bi bi-file-earmark-pdf me-1"></i>PDF · Link compartible</span></div>
   <div class="p-3">
    <small style="color:var(--t2);font-size:11px">Cualquiera con este link puede ver el PDF (sin login):</small>
    <div class="input-group mt-1 mb-3">
     <input type="text" class="form-control form-control-sm" id="pdfLink" value="<?=e($pdfUrl)?>" readonly>
     <button type="button" class="btn btn-dk btn-sm" id="btnCopiarPdf" title="Copiar link"><i class="bi bi-clipboard"></i></button>
    </div>
    <div class="d-grid gap-2">
     <div class="d-flex gap-2">
      <a href="<?=e($pdfUrlA4)?>" target="_blank" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-file-earmark me-1"></i>A4</a>
      <a href="<?=e($pdfUrlTk)?>" target="_blank" class="btn btn-dk btn-sm flex-fill" style="border-color:rgba(0,212,238,.4);color:var(--c)"><i class="bi bi-receipt me-1"></i>Ticket 80mm</a>
     </div>
     <a href="<?=e($pdfUrlDl)?>" class="btn btn-dk btn-sm"><i class="bi bi-download me-1"></i>Descargar A4</a>
     <?php if($pago['telefono']):
      $msgPdf = "Estimado(a) *".e($pago['pac'])."*, su comprobante ".strtoupper(str_replace('_',' ',$tc))." ".$pago['serie']."-".str_pad((string)$pago['numero'],8,'0',STR_PAD_LEFT)." por *".mon((float)$pago['total'])."* está disponible aquí: ".$pdfUrlA4." — ".(empresa('razon_social') ?: getCfg('clinica_nombre','DentalSys'));
     ?>
     <a href="<?=urlWA($pago['telefono'],$msgPdf)?>" target="_blank" class="btn btn-wa btn-sm"><i class="bi bi-whatsapp me-1"></i>Enviar PDF por WhatsApp</a>
     <?php endif; ?>
    </div>
   </div>
  </div>

  <div class="card">
   <div class="card-header"><span><i class="bi bi-lightning me-1"></i>Acciones</span></div>
   <div class="p-3 d-grid gap-2">
    <a href="<?=BASE_URL?>/pages/pacientes.php?accion=ver&id=<?=$pago['paciente_id']?>" class="btn btn-dk"><i class="bi bi-person me-2"></i>Ver paciente</a>
    <?php if($pago['estado']!=='anulado'): ?>
    <form method="POST" onsubmit="return confirm('¿Anular este comprobante? (Para SUNAT requiere nota de crédito)')">
     <input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?=$id?>">
     <button type="submit" class="btn btn-del w-100"><i class="bi bi-x-circle me-2"></i>Anular en sistema</button>
    </form>
    <?php endif; ?>
   </div>
  </div>
  <script>
   document.getElementById('btnCopiarPdf').addEventListener('click', () => {
    const inp = document.getElementById('pdfLink');
    inp.select(); inp.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(inp.value).then(() => {
     const btn = document.getElementById('btnCopiarPdf');
     const o = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check2"></i>';
     setTimeout(() => btn.innerHTML = o, 1500);
    });
   });
  </script>
 </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php';

// ─── NUEVA EMISIÓN ─────────────────────────────────────────────────
} elseif ($accion==='nueva') {
 $titulo='Nueva emisión'; $pagina_activa='fact';
 $pac_id = (int)($_GET['paciente_id'] ?? 0);
 $pacs = db()->query("SELECT id,codigo,nombres,apellido_paterno,dni,ruc FROM pacientes WHERE activo=1 ORDER BY apellido_paterno LIMIT 500")->fetchAll();
 $pac_pre = null;
 if ($pac_id) { $s=db()->prepare("SELECT * FROM pacientes WHERE id=?"); $s->execute([$pac_id]); $pac_pre=$s->fetch(); }
 $invs = db()->query("SELECT id,codigo,nombre,unidad,stock_actual,precio_costo FROM inventario WHERE activo=1 AND stock_actual>0 ORDER BY nombre LIMIT 800")->fetchAll();
 require_once __DIR__.'/../includes/header.php';
?>
<form method="POST" id="frm">
 <input type="hidden" name="accion" value="emitir">
 <div class="row g-4">
  <div class="col-12 col-lg-7">
   <!-- Cliente -->
   <div class="card mb-4">
    <div class="card-header"><span><i class="bi bi-person me-1"></i>Cliente</span></div>
    <div class="p-4">
     <?php if($pac_pre): ?>
      <input type="hidden" name="paciente_id" value="<?=$pac_pre['id']?>">
      <div class="d-flex align-items-center gap-2">
       <div class="ava"><?=strtoupper(substr($pac_pre['nombres'],0,1))?></div>
       <div>
        <strong><?=e($pac_pre['nombres'].' '.$pac_pre['apellido_paterno'])?></strong>
        <br><small>DNI: <?=e($pac_pre['dni']?:'—')?> · RUC: <?=e($pac_pre['ruc']?:'—')?></small>
       </div>
      </div>
     <?php else: ?>
      <label class="form-label">Paciente *</label>
      <select name="paciente_id" id="selPac" class="form-select" required>
       <option value="">— Seleccionar —</option>
       <?php foreach($pacs as $p): ?>
        <option value="<?=$p['id']?>" data-dni="<?=e($p['dni']?:'')?>" data-ruc="<?=e($p['ruc']?:'')?>">
         <?=e($p['nombres'].' '.$p['apellido_paterno'])?> — DNI <?=e($p['dni']?:'sin DNI')?><?=$p['ruc']?' · RUC '.e($p['ruc']):''?>
        </option>
       <?php endforeach; ?>
      </select>
      <div id="pacInfo" class="mt-2" style="font-size:12px;color:var(--t2)"></div>
     <?php endif; ?>
    </div>
   </div>

   <!-- Items -->
   <div class="card mb-4">
    <div class="card-header">
     <span><i class="bi bi-list-check me-1"></i>Ítems del comprobante</span>
     <button type="button" class="btn btn-primary btn-sm" onclick="addRow()">+ Línea</button>
    </div>
    <div class="p-4">
     <div class="table-responsive"><table class="table mb-0" id="tbl">
      <thead><tr><th style="min-width:280px">Producto / Servicio</th><th>Cant.</th><th>Precio</th><th>Subtotal</th><th></th></tr></thead>
      <tbody id="tb"></tbody>
     </table></div>
     <div class="mt-3 p-3 rounded" style="background:var(--bg3)">
      <div class="d-flex justify-content-between mb-2">
       <span style="color:var(--t2)">Descuento S/</span>
       <input type="number" name="descuento" id="desc" value="0" step="0.01" min="0" class="form-control form-control-sm text-end" style="width:120px" oninput="recalc()">
      </div>
      <div class="d-flex justify-content-between align-items-center">
       <strong>TOTAL</strong>
       <span class="mon fw-bold" style="font-size:26px;color:var(--c)" id="tot">S/ 0.00</span>
      </div>
     </div>
    </div>
   </div>
  </div>

  <div class="col-12 col-lg-5">
   <!-- Comprobante -->
   <div class="card mb-4">
    <div class="card-header"><span><i class="bi bi-file-earmark-text me-1"></i>Comprobante</span></div>
    <div class="p-4">
     <label class="form-label">Tipo *</label>
     <div class="btn-group w-100 mb-3" role="group">
      <input type="radio" class="btn-check" name="tipo_comprobante" id="tBol" value="boleta" checked>
      <label class="btn btn-dk" for="tBol"><i class="bi bi-receipt me-1"></i>Boleta</label>
      <input type="radio" class="btn-check" name="tipo_comprobante" id="tFac" value="factura">
      <label class="btn btn-dk" for="tFac"><i class="bi bi-file-earmark-text me-1"></i>Factura</label>
      <input type="radio" class="btn-check" name="tipo_comprobante" id="tNV" value="nota_venta">
      <label class="btn btn-dk" for="tNV"><i class="bi bi-journal-text me-1"></i>Nota venta</label>
     </div>
     <small style="color:var(--t2);font-size:11px;display:block;margin-bottom:8px"><i class="bi bi-info-circle me-1"></i>Nota de venta: comprobante interno, no se envía a SUNAT.</small>
     <div id="warnRuc" style="display:none;font-size:11px;color:var(--a);margin-bottom:10px">
      <i class="bi bi-exclamation-triangle"></i> Factura requiere RUC del paciente.
     </div>

     <label class="form-label">Método de pago *</label>
     <select name="metodo" class="form-select mb-3" required>
      <option value="efectivo">💵 Efectivo</option>
      <option value="yape">📱 Yape</option>
      <option value="plin">📱 Plin</option>
      <option value="tarjeta_debito">💳 Tarjeta débito</option>
      <option value="tarjeta_credito">💳 Tarjeta crédito</option>
      <option value="transferencia">🔄 Transferencia</option>
     </select>

     <label class="form-label">Referencia / N° operación</label>
     <input type="text" name="referencia" class="form-control mb-3" placeholder="(opcional)">

     <label class="form-label">Notas</label>
     <textarea name="notas" class="form-control" rows="2"></textarea>
    </div>
   </div>

   <div class="d-grid gap-2">
    <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-send-check me-2"></i>Emitir y generar XML</button>
    <a href="?" class="btn btn-dk">Cancelar</a>
   </div>
  </div>
 </div>
</form>

<datalist id="invList">
 <?php foreach($invs as $iv): ?>
  <option value="<?=e($iv['codigo'].' — '.$iv['nombre'])?>"></option>
 <?php endforeach; ?>
</datalist>

<?php
// Inventario JSON para autocomplete (precio + stock + id)
$invJson = json_encode(array_map(fn($x)=>[
 'id'    =>(int)$x['id'],
 'cod'   =>$x['codigo'],
 'name'  =>$x['nombre'],
 'unit'  =>$x['unidad'],
 'stock' =>(float)$x['stock_actual'],
 'price' =>(float)$x['precio_costo'],
],$invs), JSON_UNESCAPED_UNICODE);

$xscript = '<script>
const INV = '.$invJson.';
function addRow(){
 const tr=document.createElement("tr");
 tr.innerHTML=`
  <td>
   <input type="hidden" name="inventario_id[]" value="">
   <input type="text" class="form-control form-control-sm" name="concepto[]" placeholder="Buscar producto o escribir servicio..." list="invList" oninput="onConcepto(this)" required>
   <small class="stock-info" style="color:var(--t2);font-size:10px"></small>
  </td>
  <td><input type="number" name="cantidad[]" class="form-control form-control-sm c-inp" value="1" min="0.01" step="0.01" style="width:75px" oninput="rowCalc(this)"></td>
  <td><input type="number" name="precio[]" class="form-control form-control-sm p-inp" value="0" step="0.01" min="0" style="width:95px" oninput="rowCalc(this)"></td>
  <td><span class="mon sub" style="font-size:12px">S/ 0.00</span></td>
  <td><button type="button" class="btn btn-del btn-ico btn-sm" onclick="this.closest(\'tr\').remove();recalc()"><i class="bi bi-trash"></i></button></td>
 `;
 document.getElementById("tb").appendChild(tr);
 recalc();
}
function onConcepto(inp){
 const tr=inp.closest("tr");
 const v = (inp.value||"").trim();
 const m = INV.find(x =>
   v === x.cod+" — "+x.name ||
   v === x.cod+" - "+x.name ||
   v === x.name ||
   v === x.cod ||
   v.toLowerCase().startsWith((x.cod+" ").toLowerCase())
 );
 if(m){
  tr.querySelector("input[name=\'inventario_id[]\']").value = m.id;
  tr.querySelector(".p-inp").value = m.price.toFixed(2);
  tr.querySelector(".stock-info").innerHTML = "<i class=\"bi bi-box\"></i> Stock: "+m.stock+" "+(m.unit||"u")+" · "+m.cod;
 } else {
  tr.querySelector("input[name=\'inventario_id[]\']").value = "";
  tr.querySelector(".stock-info").textContent = "";
 }
 rowCalc(tr.querySelector(".c-inp"));
}
function rowCalc(inp){
 const tr=inp.closest("tr");
 const c=parseFloat(tr.querySelector(".c-inp").value)||0;
 const p=parseFloat(tr.querySelector(".p-inp").value)||0;
 tr.querySelector(".sub").textContent = "S/ "+(c*p).toFixed(2);
 recalc();
}
function recalc(){
 let s=0; document.querySelectorAll(".sub").forEach(x=>s+=parseFloat(x.textContent.replace("S/ ",""))||0);
 const d=parseFloat(document.getElementById("desc").value)||0;
 const t=Math.max(0,s-d);
 document.getElementById("tot").textContent = "S/ "+t.toFixed(2);
}
(function init(){
 addRow();
 const sel=document.getElementById("selPac");
 const info=document.getElementById("pacInfo");
 const warn=document.getElementById("warnRuc");
 const tFac=document.getElementById("tFac");
 function refresh(){
  const o = sel ? sel.options[sel.selectedIndex] : null;
  const ruc = o ? o.dataset.ruc : "";
  if(info) info.textContent = o && o.value ? ("DNI: "+(o.dataset.dni||"—")+" · RUC: "+(ruc||"sin RUC")) : "";
  if(warn) warn.style.display = (tFac && tFac.checked && !ruc) ? "block" : "none";
 }
 if(sel) sel.addEventListener("change", refresh);
 document.querySelectorAll("input[name=tipo_comprobante]").forEach(r=>r.addEventListener("change", refresh));
 refresh();
})();
</script>';
require_once __DIR__.'/../includes/footer.php';

} else {
 go('pages/facturacion.php');
}
