<?php
require_once __DIR__.'/../includes/config.php';
requiereLogin();
$accion=$_GET['accion']??'lista'; $id=(int)($_GET['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar'){
  $ei=(int)($_POST['id']??0);
  $d=['nombres'=>trim($_POST['nombres']??''),'apellido_paterno'=>trim($_POST['apellido_paterno']??''),'apellido_materno'=>trim($_POST['apellido_materno']??''),'dni'=>trim($_POST['dni']??'')?:null,'fecha_nacimiento'=>$_POST['fecha_nacimiento']??'' ?:null,'sexo'=>$_POST['sexo']??null,'estado_civil'=>$_POST['estado_civil']??null,'ocupacion'=>trim($_POST['ocupacion']??''),'telefono'=>trim($_POST['telefono']??''),'email'=>trim($_POST['email']??''),'direccion'=>trim($_POST['direccion']??''),'distrito'=>trim($_POST['distrito']??''),'tipo_seguro'=>$_POST['tipo_seguro']??'ninguno','num_seguro'=>trim($_POST['num_seguro']??''),'alergias'=>trim($_POST['alergias']??''),'enfermedades_base'=>trim($_POST['enfermedades_base']??''),'medicacion_actual'=>trim($_POST['medicacion_actual']??''),'cirugia_previa'=>trim($_POST['cirugia_previa']??''),'embarazo'=>isset($_POST['embarazo'])?1:0,'fuma'=>isset($_POST['fuma'])?1:0,'alcohol'=>isset($_POST['alcohol'])?1:0,'antecedentes_obs'=>trim($_POST['antecedentes_obs']??''),'contacto_nombre'=>trim($_POST['contacto_nombre']??''),'contacto_telefono'=>trim($_POST['contacto_telefono']??''),'contacto_parentesco'=>trim($_POST['contacto_parentesco']??'')];
  if($ei){
   $sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d)));
   db()->prepare("UPDATE pacientes SET $sets,updated_at=NOW() WHERE id=?")->execute([...array_values($d),$ei]);
   auditar('EDITAR_PAC','pacientes',$ei); flash('ok','Paciente actualizado.'); go("pages/pacientes.php?accion=ver&id=$ei");
  } else {
   $cod=genCodigo('HCL','pacientes');
   $cols='codigo,'.implode(',',array_keys($d));
   $phs='?,'.implode(',',array_fill(0,count($d),'?'));
   db()->prepare("INSERT INTO pacientes($cols)VALUES($phs)")->execute([$cod,...array_values($d)]);
   $nid=db()->lastInsertId(); auditar('CREAR_PAC','pacientes',$nid);
   flash('ok',"Paciente registrado. Código: $cod"); go("pages/pacientes.php?accion=ver&id=$nid");
  }
 }
}

if($accion==='lista'){
 $titulo='Pacientes'; $pagina_activa='pac';
 $q=trim($_GET['q']??''); $pg=max(1,(int)($_GET['p']??1)); $pp=20;
 $w='WHERE activo=1'; $pm=[];
 if($q){$w.=' AND(nombres LIKE ? OR apellido_paterno LIKE ? OR dni LIKE ? OR telefono LIKE ? OR codigo LIKE ?)';$b="%$q%";$pm=[$b,$b,$b,$b,$b];}
 $st=db()->prepare("SELECT COUNT(*) FROM pacientes $w"); $st->execute($pm); $tot=(int)$st->fetchColumn();
 $pages=max(1,ceil($tot/$pp)); $pg=min($pg,$pages); $off=($pg-1)*$pp;
 $st2=db()->prepare("SELECT * FROM pacientes $w ORDER BY apellido_paterno LIMIT $pp OFFSET $off");
 $st2->execute($pm); $lista=$st2->fetchAll();
 $topbar_act='<a href="?accion=nuevo" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Nuevo paciente</a>';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="card mb-3 p-3">
 <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
  <div class="flex-fill" style="min-width:180px"><label class="form-label">Buscar</label>
  <input type="text" name="q" class="form-control" placeholder="Nombre, DNI, código, teléfono..." value="<?=e($q)?>"></div>
  <div class="d-flex gap-2 align-items-end pt-1">
   <button type="submit" class="btn btn-dk">🔍</button>
   <?php if($q): ?><a href="?" class="btn btn-dk">✕</a><?php endif; ?>
  </div>
  <small class="ms-auto mt-2" style="color:var(--t2)"><?=$tot?> paciente<?=$tot!=1?'s':''?></small>
 </form>
</div>
<div class="card">
 <div class="table-responsive"><table class="table mb-0">
  <thead><tr><th>Código</th><th>Paciente</th><th>DNI</th><th>Teléfono</th><th>Seguro</th><th>Edad</th><th></th></tr></thead>
  <tbody>
  <?php foreach($lista as $p): ?>
  <tr>
   <td class="mon" style="color:var(--c);font-size:11px"><?=e($p['codigo'])?></td>
   <td><div class="d-flex align-items-center gap-2">
    <div class="ava"><?=strtoupper(substr($p['nombres'],0,1))?></div>
    <div><strong><?=e($p['nombres'].' '.$p['apellido_paterno'])?></strong>
    <?php if($p['alergias']): ?><br><span class="badge br" style="font-size:9px">⚠ Alérgico</span><?php endif; ?></div>
   </div></td>
   <td class="mon"><?=e($p['dni']??'—')?></td>
   <td><?=e($p['telefono']??'—')?></td>
   <td><span class="badge bgr"><?=strtoupper($p['tipo_seguro'])?></span></td>
   <td><?=$p['fecha_nacimiento']?edad($p['fecha_nacimiento']):'—'?></td>
   <td><div class="d-flex gap-1">
    <a href="?accion=ver&id=<?=$p['id']?>" class="btn btn-dk btn-ico"><i class="bi bi-eye"></i></a>
    <a href="<?=BASE_URL?>/pages/historia_clinica.php?paciente_id=<?=$p['id']?>" class="btn btn-ico bc" style="border:1px solid rgba(0,212,238,.3)" title="HC"><i class="bi bi-file-medical-fill"></i></a>
    <a href="<?=BASE_URL?>/pages/citas.php?accion=nueva&paciente_id=<?=$p['id']?>" class="btn btn-ico btn-ok" title="Agendar"><i class="bi bi-calendar-plus"></i></a>
   </div></td>
  </tr>
  <?php endforeach; if(!$lista): ?>
  <tr><td colspan="7" class="text-center py-4" style="color:var(--t2)"><i class="bi bi-people" style="font-size:36px;display:block;margin-bottom:8px"></i>No se encontraron pacientes</td></tr>
  <?php endif; ?>
  </tbody>
 </table></div>
</div>
<?php if($pages>1): ?>
<nav class="mt-3 d-flex justify-content-end"><ul class="pagination pagination-sm">
 <?php for($i=1;$i<=$pages;$i++): ?>
 <li class="page-item <?=$i===$pg?'active':''?>"><a class="page-link" href="?q=<?=urlencode($q)?>&p=<?=$i?>"><?=$i?></a></li>
 <?php endfor; ?>
</ul></nav>
<?php endif;
 require_once __DIR__.'/../includes/footer.php';

}elseif($accion==='ver'&&$id){
 $st=db()->prepare("SELECT * FROM pacientes WHERE id=?"); $st->execute([$id]); $pac=$st->fetch();
 if(!$pac){flash('error','No encontrado');go('pages/pacientes.php');}
 $hcs=db()->prepare("SELECT hc.*,CONCAT(u.nombre,' ',u.apellidos) AS dr FROM historias_clinicas hc LEFT JOIN usuarios u ON hc.doctor_id=u.id WHERE hc.paciente_id=? ORDER BY hc.fecha_apertura DESC"); $hcs->execute([$id]); $hcs=$hcs->fetchAll();
 $cit=db()->prepare("SELECT c.*,CONCAT(u.nombre,' ',u.apellidos) AS dr FROM citas c JOIN usuarios u ON c.doctor_id=u.id WHERE c.paciente_id=? ORDER BY c.fecha DESC LIMIT 8"); $cit->execute([$id]); $cit=$cit->fetchAll();
 $pags=db()->prepare("SELECT * FROM pagos WHERE paciente_id=? ORDER BY fecha DESC LIMIT 6"); $pags->execute([$id]); $pags=$pags->fetchAll();
 $titulo=$pac['nombres'].' '.$pac['apellido_paterno']; $pagina_activa='pac';
 $topbar_act='<a href="?accion=editar&id='.$id.'" class="btn btn-dk btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
 <a href="'.BASE_URL.'/pages/citas.php?accion=nueva&paciente_id='.$id.'" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus me-1"></i>Agendar</a>';
 require_once __DIR__.'/../includes/header.php';
 $sb=['ninguno'=>'bgr','sis'=>'bg','essalud'=>'bb','privado'=>'bc','otros'=>'ba'];
 $ec=['pendiente'=>'ba','confirmado'=>'bc','en_atencion'=>'bb','atendido'=>'bg','no_asistio'=>'br','cancelado'=>'bgr'];
?>
<div class="row g-4">
 <div class="col-12 col-lg-4">
  <div class="card mb-4">
   <div class="p-4 text-center" style="border-bottom:1px solid var(--bd2)">
    <div class="ava mx-auto mb-3" style="width:60px;height:60px;font-size:24px"><?=strtoupper(substr($pac['nombres'],0,1))?></div>
    <h2 style="font-size:17px;font-weight:800;margin:0"><?=e($pac['nombres'].' '.$pac['apellido_paterno'].' '.($pac['apellido_materno']??''))?></h2>
    <p style="color:var(--t2);font-size:12px;margin:3px 0"><?=e($pac['codigo'])?></p>
    <div class="d-flex gap-2 justify-content-center flex-wrap mt-2">
     <span class="badge <?=$sb[$pac['tipo_seguro']]?>"><?=strtoupper($pac['tipo_seguro'])?></span>
     <?php if($pac['alergias']): ?><span class="badge br">⚠ ALERGIAS</span><?php endif; ?>
    </div>
   </div>
   <div class="p-4" style="font-size:13px">
    <?php $inf=[['bi-calendar3','Nacimiento/Edad',$pac['fecha_nacimiento']?fDate($pac['fecha_nacimiento']).' ('.edad($pac['fecha_nacimiento']).')':'—'],['bi-phone','Teléfono',$pac['telefono']??'—'],['bi-envelope','Email',$pac['email']??'—'],['bi-card-text','DNI',$pac['dni']??'—'],['bi-geo-alt','Distrito',$pac['distrito']??'—']];
    foreach($inf as[$ico,$lbl,$val]): ?>
    <div class="d-flex gap-2 mb-2"><i class="bi <?=$ico?>" style="color:var(--c);flex-shrink:0;margin-top:1px"></i><div><small style="color:var(--t2)"><?=$lbl?></small><div style="font-weight:600"><?=e($val)?></div></div></div>
    <?php endforeach; ?>
   </div>
  </div>
  <?php if($pac['alergias']||$pac['enfermedades_base']||$pac['medicacion_actual']): ?>
  <div class="card mb-4">
   <div class="card-header"><span style="color:var(--t)"><i class="bi bi-heart-pulse me-1"></i>Antecedentes médicos</span></div>
   <div class="p-4" style="font-size:12px">
    <?php if($pac['alergias']): ?><div class="mb-3"><div style="font-size:10px;font-weight:700;color:var(--t2);text-transform:uppercase;margin-bottom:3px">⚠️ Alergias</div><div style="background:rgba(224,82,82,.08);padding:8px 10px;border-radius:6px;border-left:3px solid var(--r)"><?=e($pac['alergias'])?></div></div><?php endif; ?>
    <?php if($pac['medicacion_actual']): ?><div class="mb-3"><div style="font-size:10px;font-weight:700;color:var(--t2);text-transform:uppercase;margin-bottom:3px">💊 Medicación</div><div style="background:var(--bg3);padding:8px 10px;border-radius:6px"><?=e($pac['medicacion_actual'])?></div></div><?php endif; ?>
    <?php if($pac['enfermedades_base']): ?><div><div style="font-size:10px;font-weight:700;color:var(--t2);text-transform:uppercase;margin-bottom:3px">🏥 Enfermedades base</div><div style="background:var(--bg3);padding:8px 10px;border-radius:6px"><?=e($pac['enfermedades_base'])?></div></div><?php endif; ?>
    <div class="d-flex gap-2 flex-wrap mt-2">
     <?php if($pac['embarazo']): ?><span class="badge ba">🤰 Embarazada</span><?php endif; ?>
     <?php if($pac['fuma']): ?><span class="badge br">🚬 Fumador</span><?php endif; ?>
     <?php if($pac['alcohol']): ?><span class="badge ba">🍺 Alcohol</span><?php endif; ?>
    </div>
   </div>
  </div>
  <?php endif; ?>
  <div class="card">
   <div class="card-header"><span style="color:var(--t)"><i class="bi bi-lightning me-1"></i>Acciones rápidas</span></div>
   <div class="p-3 d-grid gap-2">
    <a href="<?=BASE_URL?>/pages/historia_clinica.php?paciente_id=<?=$id?>" class="btn btn-primary"><i class="bi bi-file-medical-fill me-2"></i>Historia clínica</a>
    <a href="<?=BASE_URL?>/pages/odontograma.php?paciente_id=<?=$id?>" class="btn btn-dk" style="border-color:rgba(0,212,238,.3);color:var(--c)"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Odontograma</a>
        <a href="<?=BASE_URL?>/pages/citas.php?accion=nueva&paciente_id=<?=$id?>" class="btn btn-dk"><i class="bi bi-calendar-plus me-2"></i>Nueva cita</a>
    <a href="<?=BASE_URL?>/pages/pagos.php?accion=nuevo&paciente_id=<?=$id?>" class="btn btn-dk"><i class="bi bi-cash me-2"></i>Registrar pago</a>
    <?php if($pac['telefono']): ?>
    <a href="<?=urlWA($pac['telefono'],'Hola '.$pac['nombres'].', le contactamos desde '.getCfg('clinica_nombre').'. ')?>" target="_blank" class="btn btn-wa"><i class="bi bi-whatsapp me-2"></i>WhatsApp</a>
    <?php endif; ?>
   </div>
  </div>
 </div>
 <div class="col-12 col-lg-8">
  <ul class="nav nav-tabs mb-4">
   <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" data-bs-target="#th">📋 HC (<?=count($hcs)?>)</a></li>
   <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-bs-target="#tc">📅 Citas (<?=count($cit)?>)</a></li>
   <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-bs-target="#tp">💰 Pagos (<?=count($pags)?>)</a></li>
  </ul>
  <div class="tab-content">
   <div class="tab-pane fade show active" id="th">
    <?php if($hcs): ?><div class="d-grid gap-3"><?php foreach($hcs as $hc): ?>
    <div class="card p-4">
     <div class="d-flex justify-content-between mb-2">
      <div><span class="badge bc me-2"><?=e($hc['numero_hc'])?></span><span class="badge <?=$hc['estado']==='activa'?'bg':'bgr'?>"><?=$hc['estado']?></span></div>
      <small><?=fDate($hc['fecha_apertura'])?></small>
     </div>
     <p style="font-size:13px;margin:0 0 6px"><strong>Motivo:</strong> <?=e(substr($hc['motivo_consulta'],0,80))?></p>
     <?php if($hc['dr']): ?><small style="color:var(--t2)">Dr. <?=e($hc['dr'])?></small><?php endif; ?>
     <div class="mt-3"><a href="<?=BASE_URL?>/pages/historia_clinica.php?id=<?=$hc['id']?>" class="btn btn-primary btn-sm"><i class="bi bi-file-medical me-1"></i>Abrir HC</a></div>
    </div>
    <?php endforeach; ?></div>
    <?php else: ?>
    <div class="card p-4 text-center" style="color:var(--t2)"><i class="bi bi-file-medical" style="font-size:36px;display:block;margin-bottom:8px"></i>Sin historias clínicas<br>
    <a href="<?=BASE_URL?>/pages/historia_clinica.php?accion=nueva&paciente_id=<?=$id?>" class="btn btn-primary mt-3">Crear primera HC</a></div>
    <?php endif; ?>
   </div>
   <div class="tab-pane fade" id="tc">
    <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Fecha</th><th>Hora</th><th>Doctor</th><th>Estado</th><th></th></tr></thead><tbody>
    <?php foreach($cit as $c): ?><tr>
     <td><?=fDate($c['fecha'])?></td><td class="mon" style="color:var(--c)"><?=substr($c['hora_inicio'],0,5)?></td>
     <td><small><?=e($c['dr'])?></small></td><td><span class="badge <?=$ec[$c['estado']]?>"><?=$c['estado']?></span></td>
     <td><a href="<?=BASE_URL?>/pages/citas.php?accion=ver&id=<?=$c['id']?>" class="btn btn-dk btn-ico"><i class="bi bi-eye"></i></a></td>
    </tr><?php endforeach; ?></tbody></table></div>
   </div>
   <div class="tab-pane fade" id="tp">
    <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Código</th><th>Fecha</th><th>Total</th><th>Método</th><th>Estado</th></tr></thead><tbody>
    <?php foreach($pags as $pg): ?><tr>
     <td class="mon" style="color:var(--c);font-size:11px"><?=e($pg['codigo'])?></td><td><small><?=fDate($pg['fecha'])?></small></td>
     <td class="mon fw-bold"><?=mon((float)$pg['total'])?></td><td><span class="badge bgr"><?=strtoupper($pg['metodo'])?></span></td>
     <td><span class="badge <?=$pg['estado']==='pagado'?'bg':($pg['estado']==='anulado'?'br':'ba')?>"><?=$pg['estado']?></span></td>
    </tr><?php endforeach; ?></tbody></table></div>
   </div>
  </div>
 </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php';

}elseif(in_array($accion,['nuevo','editar'])){
 $pac=['id'=>0,'nombres'=>'','apellido_paterno'=>'','apellido_materno'=>'','dni'=>'','fecha_nacimiento'=>'','sexo'=>'','estado_civil'=>'','ocupacion'=>'','telefono'=>'','email'=>'','direccion'=>'','distrito'=>'','tipo_seguro'=>'ninguno','num_seguro'=>'','alergias'=>'','enfermedades_base'=>'','medicacion_actual'=>'','cirugia_previa'=>'','embarazo'=>0,'fuma'=>0,'alcohol'=>0,'antecedentes_obs'=>'','contacto_nombre'=>'','contacto_telefono'=>'','contacto_parentesco'=>''];
 if($accion==='editar'&&$id){$s=db()->prepare("SELECT * FROM pacientes WHERE id=?");$s->execute([$id]);$pac=$s->fetch()?:$pac;}
 $titulo=$accion==='nuevo'?'Nuevo Paciente':'Editar: '.$pac['nombres'].' '.$pac['apellido_paterno'];
 $pagina_activa='pac';
 require_once __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-xl-9">
<form method="POST">
 <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?=$pac['id']?>">
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-person-badge me-1"></i>Datos personales</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12 col-md-4"><label class="form-label">Nombres *</label><input type="text" name="nombres" class="form-control" value="<?=e($pac['nombres'])?>" required></div>
   <div class="col-12 col-md-4"><label class="form-label">Apellido paterno *</label><input type="text" name="apellido_paterno" class="form-control" value="<?=e($pac['apellido_paterno'])?>" required></div>
   <div class="col-12 col-md-4"><label class="form-label">Apellido materno</label><input type="text" name="apellido_materno" class="form-control" value="<?=e($pac['apellido_materno']??'')?>"></div>
   <div class="col-12 col-md-3"><label class="form-label">DNI</label><input type="text" name="dni" class="form-control" value="<?=e($pac['dni']??'')?>" maxlength="15" placeholder="12345678"></div>
   <div class="col-12 col-md-3"><label class="form-label">Fecha nacimiento</label><input type="date" name="fecha_nacimiento" class="form-control" value="<?=$pac['fecha_nacimiento']??''?>"></div>
   <div class="col-12 col-md-3"><label class="form-label">Sexo</label>
   <select name="sexo" class="form-select"><option value="">—</option><option value="M" <?=$pac['sexo']==='M'?'selected':''?>>Masculino</option><option value="F" <?=$pac['sexo']==='F'?'selected':''?>>Femenino</option><option value="O" <?=$pac['sexo']==='O'?'selected':''?>>Otro</option></select></div>
   <div class="col-12 col-md-3"><label class="form-label">Estado civil</label>
   <select name="estado_civil" class="form-select"><option value="">—</option>
   <?php foreach(['soltero','casado','conviviente','divorciado','viudo'] as $ec): ?><option value="<?=$ec?>" <?=$pac['estado_civil']===$ec?'selected':''?>><?=ucfirst($ec)?></option><?php endforeach; ?></select></div>
   <div class="col-12 col-md-4"><label class="form-label">Ocupación</label><input type="text" name="ocupacion" class="form-control" value="<?=e($pac['ocupacion']??'')?>"></div>
   <div class="col-12 col-md-4"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?=e($pac['telefono']??'')?>"></div>
   <div class="col-12 col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?=e($pac['email']??'')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?=e($pac['direccion']??'')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">Distrito</label><input type="text" name="distrito" class="form-control" value="<?=e($pac['distrito']??'')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">Tipo de seguro</label>
   <select name="tipo_seguro" class="form-select">
   <?php foreach(['ninguno'=>'Sin seguro','sis'=>'SIS','essalud'=>'EsSalud','privado'=>'Privado','otros'=>'Otros'] as $v=>$l): ?><option value="<?=$v?>" <?=$pac['tipo_seguro']===$v?'selected':''?>><?=$l?></option><?php endforeach; ?></select></div>
   <div class="col-12 col-md-6"><label class="form-label">N° seguro</label><input type="text" name="num_seguro" class="form-control" value="<?=e($pac['num_seguro']??'')?>"></div>
  </div></div>
 </div>
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-heart-pulse me-1"></i>Antecedentes médicos (NT N°022-MINSA)</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12 col-md-6"><label class="form-label">⚠️ Alergias conocidas</label><textarea name="alergias" class="form-control" rows="3" placeholder="Penicilina, látex, anestesia..."><?=e($pac['alergias']??'')?></textarea></div>
   <div class="col-12 col-md-6"><label class="form-label">🏥 Enfermedades de base</label><textarea name="enfermedades_base" class="form-control" rows="3" placeholder="Diabetes, HTA, cardiopatía..."><?=e($pac['enfermedades_base']??'')?></textarea></div>
   <div class="col-12 col-md-6"><label class="form-label">💊 Medicación actual</label><textarea name="medicacion_actual" class="form-control" rows="2"><?=e($pac['medicacion_actual']??'')?></textarea></div>
   <div class="col-12 col-md-6"><label class="form-label">🔪 Cirugías previas</label><textarea name="cirugia_previa" class="form-control" rows="2"><?=e($pac['cirugia_previa']??'')?></textarea></div>
   <div class="col-12">
    <div class="d-flex gap-4 flex-wrap">
     <div class="form-check"><input class="form-check-input" type="checkbox" name="embarazo" id="ck1" <?=$pac['embarazo']?'checked':''?>><label class="form-check-label" for="ck1">🤰 Embarazada</label></div>
     <div class="form-check"><input class="form-check-input" type="checkbox" name="fuma" id="ck2" <?=$pac['fuma']?'checked':''?>><label class="form-check-label" for="ck2">🚬 Fumador/a</label></div>
     <div class="form-check"><input class="form-check-input" type="checkbox" name="alcohol" id="ck3" <?=$pac['alcohol']?'checked':''?>><label class="form-check-label" for="ck3">🍺 Alcohol</label></div>
    </div>
   </div>
   <div class="col-12"><label class="form-label">Observaciones adicionales</label><textarea name="antecedentes_obs" class="form-control" rows="2"><?=e($pac['antecedentes_obs']??'')?></textarea></div>
  </div></div>
 </div>
 <div class="card mb-4">
  <div class="card-header"><span style="color:var(--t)"><i class="bi bi-phone-vibrate me-1"></i>Contacto de emergencia</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12 col-md-5"><label class="form-label">Nombre completo</label><input type="text" name="contacto_nombre" class="form-control" value="<?=e($pac['contacto_nombre']??'')?>"></div>
   <div class="col-12 col-md-4"><label class="form-label">Teléfono</label><input type="text" name="contacto_telefono" class="form-control" value="<?=e($pac['contacto_telefono']??'')?>"></div>
   <div class="col-12 col-md-3"><label class="form-label">Parentesco</label><input type="text" name="contacto_parentesco" class="form-control" value="<?=e($pac['contacto_parentesco']??'')?>" placeholder="Hijo, esposo/a..."></div>
  </div></div>
 </div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="<?=$accion==='editar'?'?accion=ver&id='.$id:'?'?>" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i><?=$accion==='nuevo'?'Registrar paciente':'Guardar cambios'?></button>
 </div>
</form>
</div></div>
<?php require_once __DIR__.'/../includes/footer.php';
}
