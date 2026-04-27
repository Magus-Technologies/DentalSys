<?php
/**
 * pagos.php — Caja diaria y cobros (recibos internos).
 * La emisión electrónica (boleta/factura SUNAT) vive en pages/facturacion.php.
 */
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$accion=$_GET['accion']??'lista'; $id=(int)($_GET['id']??0);
$pac_id=(int)($_GET['paciente_id']??0); $cita_id=(int)($_GET['cita_id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar_pago'){
  // Verificar/crear caja abierta
  $caja=db()->query("SELECT id FROM cajas WHERE estado='abierta' ORDER BY fecha_apertura DESC LIMIT 1")->fetchColumn();
  if(!$caja){
   db()->prepare("INSERT INTO cajas(usuario_id,fecha_apertura,monto_inicial,estado)VALUES(?,NOW(),0,'abierta')")->execute([$_SESSION['uid']]);
   $caja=db()->lastInsertId();
  }
  $cod=genCodigo('PAG','pagos');
  $sub=(float)$_POST['subtotal']; $desc=(float)($_POST['descuento']??0); $tot=$sub-$desc;

  db()->prepare("INSERT INTO pagos(codigo,paciente_id,caja_id,plan_id,cita_id,fecha,subtotal,descuento,total,metodo,referencia,tipo_comprobante,estado,notas,created_by)VALUES(?,?,?,?,?,NOW(),?,?,?,?,?,'ticket',?,?,?)")
   ->execute([$cod,(int)$_POST['paciente_id'],$caja,$_POST['plan_id']?:null,$_POST['cita_id']?:null,$sub,$desc,$tot,$_POST['metodo'],$_POST['referencia']??'',$_POST['estado']??'pagado',trim($_POST['notas']??''),$_SESSION['uid']]);
  $pid=db()->lastInsertId();
  $concs=$_POST['concepto']??[]; $cants=$_POST['cantidad']??[]; $precios=$_POST['precio']??[];
  foreach($concs as $i=>$con){ if(!trim($con)) continue;
   $cant=(float)($cants[$i]??1); $pr=(float)($precios[$i]??0);
   db()->prepare("INSERT INTO pago_detalles(pago_id,concepto,cantidad,precio,subtotal)VALUES(?,?,?,?,?)")->execute([$pid,trim($con),$cant,$pr,$cant*$pr]);
  }
  auditar('CREAR_PAGO','pagos',$pid);
  flash('ok',"Pago registrado: $cod — ".mon($tot));
  go("pages/pagos.php?accion=ver&id=$pid");
 }

 if($ap==='apertura_caja'){
  $mi=(float)$_POST['monto_inicial'];
  db()->prepare("INSERT INTO cajas(usuario_id,fecha_apertura,monto_inicial,estado)VALUES(?,NOW(),?,'abierta')")->execute([$_SESSION['uid'],$mi]);
  flash('ok','Caja aperturada con '.mon($mi)); go('pages/pagos.php');
 }
 if($ap==='cierre_caja'){
  $cid=(int)$_POST['caja_id']; $mf=(float)$_POST['monto_final'];
  db()->prepare("UPDATE cajas SET estado='cerrada',fecha_cierre=NOW(),monto_final=? WHERE id=?")->execute([$mf,$cid]);
  auditar('CIERRE_CAJA','cajas',$cid); flash('ok','Caja cerrada.'); go('pages/pagos.php');
 }
 if($ap==='anular'){
  $pid=(int)$_POST['pago_id'];
  db()->prepare("UPDATE pagos SET estado='anulado' WHERE id=?")->execute([$pid]);
  auditar('ANULAR_PAGO','pagos',$pid); flash('warn','Pago anulado.'); go("pages/pagos.php?accion=ver&id=$pid");
 }
}

// CAJA ACTUAL
$caja_act=db()->query("SELECT * FROM cajas WHERE estado='abierta' ORDER BY fecha_apertura DESC LIMIT 1")->fetch();

if($accion==='lista'){
 $titulo='Caja y Pagos'; $pagina_activa='pagos';
 $fecha_sel=$_GET['fecha']??date('Y-m-d'); $q=trim($_GET['q']??'');
 $w="WHERE DATE(p.fecha)=?"; $pm=[$fecha_sel];
 if($q){$w.=" AND(pa.nombres LIKE ? OR pa.apellido_paterno LIKE ? OR p.codigo LIKE ?)";$b="%$q%";$pm[]=$b;$pm[]=$b;$pm[]=$b;}
 $st=db()->prepare("SELECT p.*,CONCAT(pa.nombres,' ',pa.apellido_paterno) AS pac FROM pagos p JOIN pacientes pa ON p.paciente_id=pa.id $w ORDER BY p.fecha DESC");
 $st->execute($pm); $lista=$st->fetchAll();
 $ing_dia=db()->prepare("SELECT COALESCE(SUM(total),0) FROM pagos WHERE DATE(fecha)=? AND estado='pagado'"); $ing_dia->execute([$fecha_sel]); $ing_dia=(float)$ing_dia->fetchColumn();
 $topbar_act='<a href="?accion=nuevo" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Registrar pago</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<!-- Caja -->
<div class="card mb-4" style="border-color:<?=$caja_act?'rgba(46,204,142,.3)':'rgba(224,82,82,.25)'?>">
 <div class="p-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
  <div class="d-flex align-items-center gap-3">
   <div class="kpi-ico rounded-3 <?=$caja_act?'kg':'kr'?>" style="width:44px;height:44px"><i class="bi bi-safe2-fill" style="font-size:18px"></i></div>
   <div>
    <div style="font-weight:800;font-size:15px">Caja <?=$caja_act?'<span class="badge bg">ABIERTA</span>':'<span class="badge br">CERRADA</span>'?></div>
    <?php if($caja_act): ?><small>Apertura: <?=fDT($caja_act['fecha_apertura'])?> · Monto inicial: <?=mon((float)$caja_act['monto_inicial'])?></small><?php endif; ?>
   </div>
  </div>
  <div class="d-flex gap-2">
   <?php if(!$caja_act): ?>
   <button type="button" class="btn btn-ok" data-bs-toggle="modal" data-bs-target="#modApertura"><i class="bi bi-safe2 me-1"></i>Aperturar caja</button>
   <?php else: ?>
   <button type="button" class="btn btn-del" data-bs-toggle="modal" data-bs-target="#modCierre"><i class="bi bi-safe me-1"></i>Cerrar caja</button>
   <?php endif; ?>
  </div>
 </div>
</div>
<div class="row g-3 mb-4">
 <div class="col-6 col-md-3"><div class="kpi kc"><div class="kpi-ico"><i class="bi bi-receipt"></i></div><div class="kpi-v"><?=count($lista)?></div><div class="kpi-l">Cobros <?=$fecha_sel==date('Y-m-d')?'hoy':'del día'?></div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-md-3"><div class="kpi kg"><div class="kpi-ico"><i class="bi bi-cash-coin"></i></div><div class="kpi-v mon" style="font-size:17px"><?=mon($ing_dia)?></div><div class="kpi-l">Ingresos <?=$fecha_sel==date('Y-m-d')?'hoy':'del día'?></div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-md-3"><div class="kpi ka"><div class="kpi-ico"><i class="bi bi-calendar3"></i></div><div class="kpi-v mon" style="font-size:17px"><?=mon((float)db()->query("SELECT COALESCE(SUM(total),0) FROM pagos WHERE MONTH(fecha)=MONTH(NOW()) AND YEAR(fecha)=YEAR(NOW()) AND estado='pagado'")->fetchColumn())?></div><div class="kpi-l">Ingresos del mes</div><div class="kpi-s"></div></div></div>
</div>
<div class="card mb-3 p-3"><form method="GET" class="d-flex gap-2 flex-wrap">
 <div><input type="date" name="fecha" class="form-control" value="<?=$fecha_sel?>"></div>
 <div class="flex-fill" style="min-width:180px"><input type="text" name="q" class="form-control" placeholder="Paciente o código..." value="<?=e($q)?>"></div>
 <button type="submit" class="btn btn-dk">Buscar</button>
 <div class="d-flex gap-1">
  <a href="?fecha=<?=date('Y-m-d',strtotime($fecha_sel.' -1 day'))?>" class="btn btn-dk">‹</a>
  <a href="?" class="btn btn-dk" title="Hoy">•</a>
  <a href="?fecha=<?=date('Y-m-d',strtotime($fecha_sel.' +1 day'))?>" class="btn btn-dk">›</a>
 </div>
</form></div>
<div class="card"><div class="table-responsive"><table class="table mb-0">
 <thead><tr><th>Código</th><th>Paciente</th><th>Fecha/Hora</th><th>Total</th><th>Método</th><th>Estado</th><th></th></tr></thead>
 <tbody>
 <?php foreach($lista as $pg):
  $ec=['pagado'=>'bg','pendiente'=>'ba','anulado'=>'br'];
  $tc=$pg['tipo_comprobante']??'ticket';
 ?><tr>
  <td class="mon" style="color:var(--c);font-size:11px"><?=e($pg['codigo'])?>
   <?php if(in_array($tc,['factura','boleta'],true)): ?><br><small style="color:var(--t2)"><?=e($pg['serie']??'')?>-<?=str_pad((string)($pg['numero']??0),8,'0',STR_PAD_LEFT)?></small><?php endif; ?>
  </td>
  <td><strong><?=e($pg['pac'])?></strong></td>
  <td><small><?=fDT($pg['fecha'])?></small></td>
  <td class="mon fw-bold"><?=mon((float)$pg['total'])?></td>
  <td><span class="badge bgr"><?=strtoupper($pg['metodo'])?></span></td>
  <td><span class="badge <?=$ec[$pg['estado']]?>"><?=$pg['estado']?></span></td>
  <td>
   <?php if(in_array($tc,['factura','boleta'],true)): ?>
    <a href="<?=BASE_URL?>/pages/facturacion.php?accion=ver&id=<?=$pg['id']?>" class="btn btn-dk btn-ico" title="Ver comprobante"><i class="bi bi-receipt"></i></a>
   <?php else: ?>
    <a href="?accion=ver&id=<?=$pg['id']?>" class="btn btn-dk btn-ico" title="Ver"><i class="bi bi-eye"></i></a>
   <?php endif; ?>
  </td>
 </tr><?php endforeach; if(!$lista): ?>
 <tr><td colspan="7" class="text-center py-4" style="color:var(--t2)">No hay cobros para este período</td></tr>
 <?php endif; ?></tbody>
</table></div></div>

<!-- Modal apertura -->
<div class="modal fade" id="modApertura" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
 <div class="modal-header"><h5 class="modal-title">💰 Apertura de Caja</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
 <form method="POST">
  <input type="hidden" name="accion" value="apertura_caja">
  <div class="modal-body p-4"><label class="form-label">Monto inicial (efectivo en caja)</label>
  <div class="input-group"><span class="input-group-text">S/</span><input type="number" name="monto_inicial" class="form-control" value="0" step="0.01" min="0" required></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-dk" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Aperturar caja</button></div>
 </form>
</div></div></div>
<!-- Modal cierre -->
<?php if($caja_act): ?>
<div class="modal fade" id="modCierre" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
 <div class="modal-header"><h5 class="modal-title">🔒 Cierre de Caja</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
 <form method="POST">
  <input type="hidden" name="accion" value="cierre_caja"><input type="hidden" name="caja_id" value="<?=$caja_act['id']?>">
  <div class="modal-body p-4">
   <?php $ing=db()->query("SELECT COALESCE(SUM(total),0) FROM pagos WHERE caja_id=".$caja_act['id']." AND estado='pagado'")->fetchColumn(); ?>
   <div class="p-3 rounded mb-3" style="background:var(--bg3)"><strong>Ingresos registrados en esta caja:</strong> <span class="mon" style="color:var(--g)"><?=mon((float)$ing)?></span></div>
   <label class="form-label">Monto contado en físico</label>
   <div class="input-group"><span class="input-group-text">S/</span><input type="number" name="monto_final" class="form-control" value="<?=number_format((float)$ing+$caja_act['monto_inicial'],2,'.','')?>" step="0.01" min="0" required></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-dk" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-del">Cerrar caja</button></div>
 </form>
</div></div></div>
<?php endif; ?>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='ver'&&$id){
 $st=db()->prepare("SELECT p.*,CONCAT(pa.nombres,' ',pa.apellido_paterno) AS pac,pa.telefono AS ptl FROM pagos p JOIN pacientes pa ON p.paciente_id=pa.id WHERE p.id=?");
 $st->execute([$id]); $pago=$st->fetch(); if(!$pago){flash('error','Pago no encontrado');go('pages/pagos.php');}
 $dets=db()->prepare("SELECT * FROM pago_detalles WHERE pago_id=?"); $dets->execute([$id]); $dets=$dets->fetchAll();
 $titulo='Pago '.$pago['codigo']; $pagina_activa='pagos';
 require_once __DIR__.'/../includes/header.php';
 $est=['pagado'=>'bg','pendiente'=>'ba','anulado'=>'br'];
?>
<div class="row g-4">
 <div class="col-12 col-lg-7">
  <div class="card">
   <div class="card-header"><span><i class="bi bi-receipt me-1"></i><?=e($pago['codigo'])?></span><span class="badge <?=$est[$pago['estado']]?>" style="font-size:12px"><?=strtoupper($pago['estado'])?></span></div>
   <div class="p-4">
    <div class="d-flex align-items-center gap-2 mb-4">
     <div class="ava"><?=strtoupper(substr($pago['pac'],0,1))?></div>
     <div><strong style="font-size:15px"><?=e($pago['pac'])?></strong><br><small><?=fDT($pago['fecha'])?></small></div>
    </div>
    <div class="table-responsive"><table class="table mb-0">
     <thead><tr><th>Concepto</th><th>Cant.</th><th>Precio</th><th>Subtotal</th></tr></thead>
     <tbody>
     <?php if($dets): foreach($dets as $d): ?><tr>
      <td><?=e($d['concepto'])?></td>
      <td><?=$d['cantidad']?></td>
      <td class="mon"><?=mon((float)$d['precio'])?></td>
      <td class="mon fw-bold"><?=mon((float)$d['subtotal'])?></td>
     </tr><?php endforeach; endif; ?></tbody>
    </table></div>
    <div class="mt-3 p-3 rounded" style="background:var(--bg3)">
     <div class="d-flex justify-content-between"><span style="color:var(--t2)">Subtotal</span><span class="mon"><?=mon((float)$pago['subtotal'])?></span></div>
     <?php if($pago['descuento']>0): ?><div class="d-flex justify-content-between"><span style="color:var(--g)">Descuento</span><span class="mon" style="color:var(--g)">-<?=mon((float)$pago['descuento'])?></span></div><?php endif; ?>
     <hr>
     <div class="d-flex justify-content-between"><strong>TOTAL</strong><span class="mon fw-bold" style="font-size:22px;color:var(--c)"><?=mon((float)$pago['total'])?></span></div>
     <div class="mt-2"><span class="badge bgr"><?=strtoupper($pago['metodo'])?></span><?php if($pago['referencia']): ?><span style="color:var(--t2);font-size:12px;margin-left:8px">Ref: <?=e($pago['referencia'])?></span><?php endif; ?></div>
    </div>
    <?php if($pago['notas']): ?><div class="mt-3" style="color:var(--t2);font-size:12px"><?=e($pago['notas'])?></div><?php endif; ?>
   </div>
  </div>
 </div>
 <div class="col-12 col-lg-5">
  <div class="card mb-4">
   <div class="card-header"><span><i class="bi bi-lightning me-1"></i>Acciones</span></div>
   <div class="p-3 d-grid gap-2">
    <a href="<?=BASE_URL?>/pages/pacientes.php?accion=ver&id=<?=$pago['paciente_id']?>" class="btn btn-dk"><i class="bi bi-person me-2"></i>Ver paciente</a>
    <?php if($pago['estado']==='pagado'&&$pago['ptl']): ?>
    <?php $msg_pago="Estimado(a) *".e($pago['pac'])."*, su pago de *".mon((float)$pago['total'])."* ha sido registrado. Código: ".$pago['codigo']." — ".getCfg('clinica_nombre'); ?>
    <a href="<?=urlWA($pago['ptl'],$msg_pago)?>" target="_blank" class="btn btn-wa"><i class="bi bi-whatsapp me-2"></i>Enviar confirmación WA</a>
    <?php endif; ?>
    <?php if($pago['estado']!=='anulado'): ?>
    <form method="POST" onsubmit="return confirm('¿Anular este pago?')">
     <input type="hidden" name="accion" value="anular"><input type="hidden" name="pago_id" value="<?=$id?>">
     <button type="submit" class="btn btn-del w-100"><i class="bi bi-x-circle me-2"></i>Anular pago</button>
    </form>
    <?php endif; ?>
   </div>
  </div>

  <?php if(in_array($pago['tipo_comprobante']??'', ['factura','boleta'], true)): ?>
  <div class="card">
   <div class="card-header"><span><i class="bi bi-bank me-1"></i>Comprobante electrónico</span></div>
   <div class="p-3">
    <div class="mb-2" style="font-size:12px;color:var(--t2)">
     Este pago tiene comprobante electrónico asociado.
    </div>
    <a href="<?=BASE_URL?>/pages/facturacion.php?accion=ver&id=<?=$id?>" class="btn btn-primary btn-sm w-100">
     <i class="bi bi-receipt-cutoff me-1"></i>Ver en Facturación
    </a>
   </div>
  </div>
  <?php endif; ?>
 </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='nuevo'){
 $titulo='Registrar Pago'; $pagina_activa='pagos';
 $pacs=db()->query("SELECT id,codigo,nombres,apellido_paterno,telefono FROM pacientes WHERE activo=1 ORDER BY apellido_paterno LIMIT 500")->fetchAll();
 $pac_pre=null; if($pac_id){$s=db()->prepare("SELECT * FROM pacientes WHERE id=?");$s->execute([$pac_id]);$pac_pre=$s->fetch();}
 $plan_pre=null; if($pac_id){$s=db()->prepare("SELECT pt.*,(SELECT GROUP_CONCAT(pd.nombre_tratamiento SEPARATOR ', ') FROM plan_detalles pd WHERE pd.plan_id=pt.id) AS trats FROM planes_tratamiento pt WHERE pt.paciente_id=? AND pt.estado IN('aprobado','en_proceso') ORDER BY pt.created_at DESC LIMIT 1");$s->execute([$pac_id]);$plan_pre=$s->fetch();}
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
<form method="POST">
 <input type="hidden" name="accion" value="guardar_pago">
 <input type="hidden" name="cita_id" value="<?=$cita_id?>">
 <div class="card mb-4"><div class="card-header"><span><i class="bi bi-person me-1"></i>Paciente</span></div>
 <div class="p-4">
 <?php if($pac_pre): ?>
  <input type="hidden" name="paciente_id" value="<?=$pac_pre['id']?>">
  <div class="d-flex align-items-center gap-2"><div class="ava"><?=strtoupper(substr($pac_pre['nombres'],0,1))?></div>
  <div><strong><?=e($pac_pre['nombres'].' '.$pac_pre['apellido_paterno'])?></strong><br><small><?=e($pac_pre['codigo'])?></small></div></div>
 <?php else: ?>
  <label class="form-label">Paciente *</label>
  <select name="paciente_id" class="form-select" required>
   <option value="">— Seleccionar —</option>
   <?php foreach($pacs as $p): ?><option value="<?=$p['id']?>"><?=e($p['nombres'].' '.$p['apellido_paterno'])?> (<?=$p['codigo']?>)</option><?php endforeach; ?>
  </select>
 <?php endif; ?>
 <?php if($plan_pre): ?>
  <div class="mt-3 p-3 rounded" style="background:var(--bg3);border:1px solid var(--bd)">
   <input type="hidden" name="plan_id" value="<?=$plan_pre['id']?>">
   <small style="color:var(--t2)">Plan activo:</small> <span class="badge bc"><?=$plan_pre['estado']?></span>
   <div style="font-size:12px;margin-top:4px"><?=e(mb_substr($plan_pre['trats']??'',0,80))?></div>
   <div class="mt-1"><strong class="mon" style="color:var(--c)"><?=mon((float)$plan_pre['total'])?></strong></div>
  </div>
 <?php else: ?><input type="hidden" name="plan_id" value=""><?php endif; ?>
 </div></div>
 <!-- Detalles del pago -->
 <div class="card mb-4"><div class="card-header"><span><i class="bi bi-list-check me-1"></i>Conceptos a cobrar</span>
 <button type="button" class="btn btn-primary btn-sm" onclick="addConcepto()">+ Línea</button></div>
 <div class="p-4">
  <div class="table-responsive"><table class="table mb-0" id="tblDet">
   <thead><tr><th>Concepto</th><th>Cant.</th><th>Precio</th><th>Subtotal</th><th></th></tr></thead>
   <tbody id="tbDet">
    <tr>
     <td><input type="text" name="concepto[]" class="form-control form-control-sm" placeholder="Ej: Profilaxis dental" required></td>
     <td><input type="number" name="cantidad[]" class="form-control form-control-sm c-inp" value="1" min="0.01" step="0.01" style="width:65px" oninput="calcRow(this)"></td>
     <td><input type="number" name="precio[]" class="form-control form-control-sm p-inp" value="0" step="0.01" min="0" style="width:90px" oninput="calcRow(this)"></td>
     <td><span class="mon sub-lbl" style="font-size:12px">S/ 0.00</span></td>
     <td></td>
    </tr>
   </tbody>
  </table></div>
  <div class="text-end mt-3 p-3 rounded" style="background:var(--bg3)">
   <div class="d-flex justify-content-between mb-2">
    <span style="color:var(--t2)">Descuento S/</span>
    <input type="number" name="descuento" id="descInp" value="0" step="0.01" min="0" class="form-control form-control-sm text-end" style="width:100px" oninput="calcTotal()">
   </div>
   <input type="hidden" name="subtotal" id="subInp" value="0">
   <div style="font-size:14px;color:var(--t2)">TOTAL A COBRAR:</div>
   <div class="mon fw-bold" style="font-size:28px;color:var(--c)" id="totalLbl">S/ 0.00</div>
  </div>
 </div></div>
 <!-- Método de pago -->
 <div class="card mb-4"><div class="card-header"><span><i class="bi bi-credit-card me-1"></i>Método de pago</span></div>
 <div class="p-4"><div class="row g-3">
  <div class="col-12 col-md-6"><label class="form-label">Método *</label>
  <select name="metodo" class="form-select" required>
   <option value="efectivo">💵 Efectivo</option><option value="yape">📱 Yape</option>
   <option value="plin">📱 Plin</option><option value="tarjeta_debito">💳 Tarjeta débito</option>
   <option value="tarjeta_credito">💳 Tarjeta crédito</option><option value="transferencia">🔄 Transferencia</option><option value="otro">📋 Otro</option>
  </select></div>
  <div class="col-12 col-md-6"><label class="form-label">Estado</label>
  <select name="estado" class="form-select">
   <option value="pagado">✅ Pagado</option><option value="pendiente">⏳ Pendiente</option>
  </select></div>
  <div class="col-12 col-md-6"><label class="form-label">N° operación / Referencia</label><input type="text" name="referencia" class="form-control" placeholder="Número de operación"></div>
  <div class="col-12"><label class="form-label">Notas</label><textarea name="notas" class="form-control" rows="2"></textarea></div>
 </div></div></div>
 <div class="alert alert-info" style="font-size:12px">
  <i class="bi bi-info-circle me-1"></i>
  Este registro genera un <strong>recibo interno</strong> (sin SUNAT). Para emitir <strong>boleta o factura</strong>, ve a
  <a href="<?=BASE_URL?>/pages/facturacion.php?accion=nueva<?=$pac_pre?'&paciente_id='.$pac_pre['id']:''?>" style="color:var(--c);font-weight:700">Facturación</a>.
 </div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="?" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-cash-coin me-2"></i>Registrar pago</button>
 </div>
</form>
</div></div>
<?php
$xscript='<script>
let rowN=1;
function addConcepto(){
 rowN++;
 const tr=document.createElement("tr");
 tr.innerHTML=`<td><input type="text" name="concepto[]" class="form-control form-control-sm" placeholder="Concepto"></td>
  <td><input type="number" name="cantidad[]" class="form-control form-control-sm c-inp" value="1" min="0.01" step="0.01" style="width:65px" oninput="calcRow(this)"></td>
  <td><input type="number" name="precio[]" class="form-control form-control-sm p-inp" value="0" step="0.01" min="0" style="width:90px" oninput="calcRow(this)"></td>
  <td><span class="mon sub-lbl" style="font-size:12px">S/ 0.00</span></td>
  <td><button type="button" class="btn btn-del btn-ico btn-sm" onclick="this.closest(\'tr\').remove();calcTotal()"><i class="bi bi-trash"></i></button></td>`;
 document.getElementById("tbDet").appendChild(tr); calcTotal();
}
function calcRow(inp){
 const tr=inp.closest("tr");
 const cant=parseFloat(tr.querySelector(".c-inp").value)||0;
 const pr=parseFloat(tr.querySelector(".p-inp").value)||0;
 tr.querySelector(".sub-lbl").textContent="S/ "+(cant*pr).toFixed(2);
 calcTotal();
}
function calcTotal(){
 let sub=0; document.querySelectorAll(".sub-lbl").forEach(s=>sub+=parseFloat(s.textContent.replace("S/ ","")||0));
 const desc=parseFloat(document.getElementById("descInp").value)||0;
 const tot=Math.max(0,sub-desc);
 document.getElementById("subInp").value=sub.toFixed(2);
 document.getElementById("totalLbl").textContent="S/ "+tot.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,",");
}
</script>';
require_once __DIR__.'/../includes/footer.php';
}
