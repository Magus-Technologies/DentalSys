<?php
require_once __DIR__.'/../../includes/config.php';
requiereRol('admin');
$titulo='Configuración del Sistema'; $pagina_activa='cfg';

if($_SERVER['REQUEST_METHOD']==='POST'){
 foreach($_POST as $k=>$v){
  if($k==='accion') continue;
  db()->prepare("INSERT INTO configuracion(clave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=?")->execute([$k,trim($v),trim($v)]);
 }
 flash('ok','Configuración guardada.'); go('pages/admin/configuracion.php');
}

$cfg=[];
$st=db()->query("SELECT clave,valor FROM configuracion"); foreach($st->fetchAll() as $r) $cfg[$r['clave']]=$r['valor'];
function cfgVal(array $cfg, string $k, string $d=''): string { return e($cfg[$k]??$d); }

require_once __DIR__.'/../../includes/header.php';
?>
<form method="POST">
<div class="row g-4">
 <div class="col-12 col-lg-6">
  <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">🏥 Datos de la clínica</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12"><label class="form-label">Nombre de la clínica *</label><input type="text" name="clinica_nombre" class="form-control" value="<?=cfgVal($cfg,'clinica_nombre')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">RUC</label><input type="text" name="clinica_ruc" class="form-control" value="<?=cfgVal($cfg,'clinica_ruc')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">Teléfono</label><input type="text" name="clinica_telefono" class="form-control" value="<?=cfgVal($cfg,'clinica_telefono')?>"></div>
   <div class="col-12"><label class="form-label">Dirección</label><input type="text" name="clinica_direccion" class="form-control" value="<?=cfgVal($cfg,'clinica_direccion')?>"></div>
   <div class="col-12"><label class="form-label">Email</label><input type="email" name="clinica_email" class="form-control" value="<?=cfgVal($cfg,'clinica_email')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">Director / CMP responsable</label><input type="text" name="director_nombre" class="form-control" value="<?=cfgVal($cfg,'director_nombre')?>"></div>
   <div class="col-12 col-md-6"><label class="form-label">CMP del director</label><input type="text" name="director_cmp" class="form-control" value="<?=cfgVal($cfg,'director_cmp')?>"></div>
  </div></div></div>

  <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">💰 Configuración financiera</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12 col-md-4"><label class="form-label">Moneda</label><input type="text" name="moneda" class="form-control" value="<?=cfgVal($cfg,'moneda','S/')?>" placeholder="S/"></div>
   <div class="col-12 col-md-4"><label class="form-label">IGV %</label><input type="number" name="igv" class="form-control" value="<?=cfgVal($cfg,'igv','18')?>" min="0" max="100"></div>
   <div class="col-12"><label class="form-label">N° Yape</label><input type="text" name="cuenta_yape" class="form-control" value="<?=cfgVal($cfg,'cuenta_yape')?>"></div>
   <div class="col-12"><label class="form-label">N° BCP</label><input type="text" name="cuenta_bcp" class="form-control" value="<?=cfgVal($cfg,'cuenta_bcp')?>"></div>
  </div></div></div>
 </div>
 <div class="col-12 col-lg-6">
  <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">📅 Agenda</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12 col-md-4"><label class="form-label">Hora inicio</label><input type="time" name="hora_inicio" class="form-control" value="<?=cfgVal($cfg,'hora_inicio','08:00')?>"></div>
   <div class="col-12 col-md-4"><label class="form-label">Hora fin</label><input type="time" name="hora_fin" class="form-control" value="<?=cfgVal($cfg,'hora_fin','20:00')?>"></div>
   <div class="col-12 col-md-4"><label class="form-label">Duración cita (min)</label><input type="number" name="duracion_cita" class="form-control" value="<?=cfgVal($cfg,'duracion_cita','30')?>" min="5"></div>
  </div></div></div>

  <div class="card mb-4"><div class="card-header"><span style="color:var(--t)">📱 WhatsApp / Notificaciones</span></div>
  <div class="p-4"><div class="row g-3">
   <div class="col-12"><label class="form-label">Plantilla recordatorio de cita</label>
   <textarea name="plantilla_wa_cita" class="form-control" rows="4"><?=cfgVal($cfg,'plantilla_wa_cita','Estimado(a) *{nombre}*, le recordamos su cita en *{clinica}* el *{fecha}* a las *{hora}*. Ante consultas: {telefono}')?></textarea>
   <small style="color:var(--t2);font-size:11px">Variables disponibles: {nombre} {clinica} {fecha} {hora} {telefono}</small></div>
  </div></div></div>

  <div class="card"><div class="card-header"><span style="color:var(--t)">📜 Info SIHCE / MINSA</span></div>
  <div class="p-4" style="font-size:13px">
   <div class="p-3 rounded mb-3" style="background:rgba(0,212,238,.06);border:1px solid rgba(0,212,238,.15)">
    <strong style="color:var(--c)">Cumplimiento SIHCE</strong>
    <ul class="mt-2 mb-0" style="color:var(--t2);font-size:12px;padding-left:16px">
     <li>✅ Auditoría de accesos y cambios</li>
     <li>✅ Historia clínica NT N°022-MINSA</li>
     <li>✅ Odontograma FDI (RM 593-2006)</li>
     <li>✅ Control de sesiones y roles</li>
     <li>✅ Logs de actividad completos</li>
    </ul>
   </div>
   <div style="color:var(--t2);font-size:11px">DentalSys v2.0 — Desarrollado para cumplir NT N°022-MINSA/DGSP-V.02 y RM 593-2006/MINSA</div>
  </div></div>
 </div>
</div>
<div class="d-flex justify-content-end mt-3">
 <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar configuración</button>
</div>
</form>
<?php require_once __DIR__.'/../../includes/footer.php';
