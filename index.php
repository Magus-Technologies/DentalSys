<?php
require_once __DIR__.'/includes/config.php';
requiereLogin();
$titulo='Dashboard'; $pagina_activa='dash';

$pac_total   = db()->query("SELECT COUNT(*) FROM pacientes WHERE activo=1")->fetchColumn();
$citas_hoy   = db()->query("SELECT COUNT(*) FROM citas WHERE fecha=CURDATE() AND estado NOT IN('cancelado')")->fetchColumn();
$ing_mes     = db()->query("SELECT COALESCE(SUM(total),0) FROM pagos WHERE MONTH(fecha)=MONTH(NOW()) AND YEAR(fecha)=YEAR(NOW()) AND estado='pagado'")->fetchColumn();
$stock_bajo  = db()->query("SELECT COUNT(*) FROM inventario WHERE stock_actual<=stock_minimo AND activo=1")->fetchColumn();
$ing_hoy     = db()->query("SELECT COALESCE(SUM(total),0) FROM pagos WHERE DATE(fecha)=CURDATE() AND estado='pagado'")->fetchColumn();

$citas_lista = db()->query("SELECT c.*,CONCAT(p.nombres,' ',p.apellido_paterno) AS pac,CONCAT(u.nombre,' ',u.apellidos) AS dr,s.nombre AS sillon FROM citas c JOIN pacientes p ON c.paciente_id=p.id JOIN usuarios u ON c.doctor_id=u.id LEFT JOIN sillones s ON c.sillon_id=s.id WHERE c.fecha=CURDATE() ORDER BY c.hora_inicio")->fetchAll();

$ult_pacs = db()->query("SELECT id,codigo,nombres,apellido_paterno,telefono,created_at FROM pacientes ORDER BY created_at DESC LIMIT 5")->fetchAll();

$chart_data = [];
for($i=6;$i>=0;$i--){
 $d=date('Y-m-d',strtotime("-$i days"));
 $s=db()->prepare("SELECT COALESCE(SUM(total),0) FROM pagos WHERE DATE(fecha)=? AND estado='pagado'");
 $s->execute([$d]);
 $chart_data[]=['dia'=>date('D',strtotime($d)),'v'=>(float)$s->fetchColumn()];
}

$ec=['pendiente'=>'ba','confirmado'=>'bc','en_atencion'=>'bb','atendido'=>'bg','no_asistio'=>'br','cancelado'=>'bgr'];
$el=['pendiente'=>'Pendiente','confirmado'=>'Confirmado','en_atencion'=>'En atención','atendido'=>'Atendido','no_asistio'=>'No asistió','cancelado'=>'Cancelado'];

$xhead = '<style>
@media(max-width:576px){
  .kpi-v.mon{font-size:14px!important}
  .table td:first-child{max-width:100px;overflow:hidden;text-overflow:ellipsis}
}
</style>';
require_once __DIR__.'/includes/header.php';
?>
<div class="row g-3 mb-4">
 <div class="col-6 col-lg-3"><div class="kpi kc"><div class="kpi-ico"><i class="bi bi-people-fill"></i></div><div class="kpi-v"><?=$pac_total?></div><div class="kpi-l">Pacientes activos</div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-lg-3"><div class="kpi kg"><div class="kpi-ico"><i class="bi bi-calendar-check-fill"></i></div><div class="kpi-v"><?=$citas_hoy?></div><div class="kpi-l">Citas hoy</div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-lg-3"><div class="kpi ka"><div class="kpi-ico"><i class="bi bi-cash-stack"></i></div><div class="kpi-v mon" style="font-size:17px"><?=mon((float)$ing_mes)?></div><div class="kpi-l">Ingresos del mes</div><div class="kpi-s"></div></div></div>
 <div class="col-6 col-lg-3"><div class="kpi kr"><div class="kpi-ico"><i class="bi bi-box-seam"></i></div><div class="kpi-v"><?=$stock_bajo?></div><div class="kpi-l">Stock bajo alerta</div><div class="kpi-s"></div></div></div>
</div>
<div class="row g-4 mb-4">
 <div class="col-12 col-lg-8">
  <div class="card">
   <div class="card-header"><span><i class="bi bi-calendar2-week me-2"></i>Citas de hoy — <?=date('d/m/Y')?></span>
   <a href="<?=BASE_URL?>/pages/citas.php?accion=nueva" class="btn btn-primary btn-sm">+ Nueva cita</a></div>
   <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Hora</th><th>Paciente</th><th>Doctor</th><th>Sillón</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach($citas_lista as $c): ?>
    <tr>
     <td class="mon" style="color:var(--c)"><?=substr($c['hora_inicio'],0,5)?></td>
     <td><strong><?=e($c['pac'])?></strong><?php if($c['motivo']): ?><br><small><?=e(substr($c['motivo'],0,35))?></small><?php endif; ?></td>
     <td><small><?=e($c['dr'])?></small></td>
     <td><small><?=e($c['sillon']??'—')?></small></td>
     <td><span class="badge <?=$ec[$c['estado']]?>"><?=$el[$c['estado']]?></span></td>
     <td><a href="<?=BASE_URL?>/pages/citas.php?accion=ver&id=<?=$c['id']?>" class="btn btn-dk btn-ico"><i class="bi bi-eye"></i></a></td>
    </tr>
    <?php endforeach; if(!$citas_lista): ?>
    <tr><td colspan="6" class="text-center py-4" style="color:var(--t2)"><i class="bi bi-calendar-x" style="font-size:32px;display:block;margin-bottom:8px"></i>No hay citas para hoy</td></tr>
    <?php endif; ?>
    </tbody>
   </table></div>
  </div>
 </div>
 <div class="col-12 col-lg-4">
  <div class="card mb-4">
   <div class="card-header"><span style="color:var(--t)"><i class="bi bi-graph-up me-2"></i>Ingresos últimos 7 días</span></div>
   <div class="p-4"><canvas id="chartIng" style="max-height:200px"></canvas></div>
  </div>
  <?php if($stock_bajo>0):
  $prods=db()->query("SELECT nombre,stock_actual,stock_minimo,unidad FROM inventario WHERE stock_actual<=stock_minimo AND activo=1 LIMIT 4")->fetchAll(); ?>
  <div class="card" style="border-color:rgba(224,82,82,.25)">
   <div class="card-header" style="background:rgba(224,82,82,.07)"><span style="color:var(--r)"><i class="bi bi-exclamation-triangle-fill me-1"></i>⚠ Stock bajo</span></div>
   <div class="p-3">
    <?php foreach($prods as $pr): ?>
    <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom:1px solid var(--bd2)">
     <span style="font-size:12px"><?=e($pr['nombre'])?></span>
     <span class="badge br"><?=$pr['stock_actual']?> <?=$pr['unidad']?></span>
    </div>
    <?php endforeach; ?>
    <a href="<?=BASE_URL?>/pages/inventario.php" class="btn btn-del btn-sm w-100 mt-2">Ver inventario</a>
   </div>
  </div>
  <?php endif; ?>
 </div>
</div>
<div class="card">
 <div class="card-header"><span style="color:var(--t)"><i class="bi bi-clock-history me-2"></i>Últimos pacientes registrados</span>
 <a href="<?=BASE_URL?>/pages/pacientes.php?accion=nuevo" class="btn btn-primary btn-sm">+ Nuevo paciente</a></div>
 <div class="table-responsive"><table class="table mb-0">
  <thead><tr><th>Código</th><th>Paciente</th><th>Teléfono</th><th>Registro</th><th></th></tr></thead>
  <tbody>
  <?php foreach($ult_pacs as $p): ?>
  <tr>
   <td class="mon" style="color:var(--c);font-size:11px"><?=e($p['codigo'])?></td>
   <td><strong><?=e($p['nombres'].' '.$p['apellido_paterno'])?></strong></td>
   <td><?=e($p['telefono']??'—')?></td>
   <td><small><?=fDate($p['created_at'])?></small></td>
   <td><a href="<?=BASE_URL?>/pages/pacientes.php?accion=ver&id=<?=$p['id']?>" class="btn btn-dk btn-ico"><i class="bi bi-eye"></i></a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
</div>
<?php
$xscript='<script>
new Chart(document.getElementById("chartIng"),{
 type:"bar",
 data:{labels:'.json_encode(array_column($chart_data,'dia')).',
 datasets:[{label:"Ingresos S/",data:'.json_encode(array_column($chart_data,'v')).',
 backgroundColor:"rgba(0,212,238,.65)",borderRadius:5,borderSkipped:false}]},
 options:{responsive:true,plugins:{legend:{display:false}},
 scales:{y:{beginAtZero:true,ticks:{callback:v=>"S/"+v,font:{size:10}}},x:{grid:{display:false},ticks:{font:{size:10}}}}}
});
</script>';
require_once __DIR__.'/includes/footer.php';
