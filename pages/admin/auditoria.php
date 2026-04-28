<?php
require_once __DIR__.'/../../includes/config.php';
requiereRol('admin');
$titulo='Auditoría SIHCE'; $pagina_activa='audit';

$pg=max(1,(int)($_GET['p']??1)); $pp=30; $off=($pg-1)*$pp;
$q=trim($_GET['q']??''); $fecha=$_GET['fecha']??'';
$w='WHERE 1=1'; $pm=[];
if($q){$w.=' AND a.accion LIKE ?';$pm[]="%$q%";}
if($fecha){$w.=' AND DATE(a.created_at)=?';$pm[]=$fecha;}
$st=db()->prepare("SELECT COUNT(*) FROM auditoria a $w"); $st->execute($pm); $tot=(int)$st->fetchColumn();
$pages=max(1,ceil($tot/$pp)); $pg=min($pg,$pages);
$st=db()->prepare("SELECT a.*,CONCAT(u.nombre,' ',u.apellidos) AS usr FROM auditoria a LEFT JOIN usuarios u ON a.usuario_id=u.id $w ORDER BY a.created_at DESC LIMIT $pp OFFSET $off");
$st->execute($pm); $lista=$st->fetchAll();

require_once __DIR__.'/../../includes/header.php';
?>
<div class="card mb-3 p-3"><form method="GET" class="d-flex gap-2 flex-wrap">
 <div class="flex-fill"><input type="text" name="q" class="form-control" placeholder="Buscar acción..." value="<?=e($q)?>"></div>
 <div><input type="date" name="fecha" class="form-control" value="<?=e($fecha)?>"></div>
 <button type="submit" class="btn btn-dk">Filtrar</button>
 <?php if($q||$fecha): ?><a href="?" class="btn btn-dk">✕</a><?php endif; ?>
 <small class="ms-auto mt-2" style="color:var(--t2)"><?=$tot?> registros</small>
</form></div>
<div class="card"><div class="table-responsive"><table class="table mb-0">
 <thead><tr><th>Fecha/Hora</th><th class="d-none d-md-table-cell">Usuario</th><th>Acción</th><th class="d-none d-lg-table-cell">Tabla</th><th class="d-none d-lg-table-cell">Registro</th><th class="d-none d-xl-table-cell">IP</th></tr></thead>
 <tbody>
 <?php foreach($lista as $a): ?>
 <tr>
  <td><small class="mon"><?=fDT($a['created_at'])?></small></td>
  <td class="d-none d-md-table-cell"><small><?=e($a['usr']??'Sistema')?></small></td>
  <td><span class="badge <?=str_contains($a['accion'],'LOGIN')?'bg':(str_contains($a['accion'],'CREAR')?'bc':(str_contains($a['accion'],'EDITAR')?'ba':(str_contains($a['accion'],'ANULAR')?'br':'bgr')))?>"><?=e($a['accion'])?></span></td>
  <td class="d-none d-lg-table-cell"><small style="color:var(--t2)"><?=e($a['tabla']??'—')?></small></td>
  <td class="d-none d-lg-table-cell"><small class="mon"><?=$a['registro_id']??'—'?></small></td>
  <td class="d-none d-xl-table-cell"><small style="color:var(--t3)"><?=e($a['ip']??'—')?></small></td>
 </tr>
 <?php endforeach; if(!$lista): ?><tr><td colspan="6" class="text-center py-4" style="color:var(--t2)">Sin registros</td></tr><?php endif; ?></tbody>
</table></div></div>
<?php if($pages>1): ?>
<nav class="mt-3 d-flex justify-content-end"><ul class="pagination pagination-sm">
 <?php for($i=1;$i<=$pages;$i++): ?>
 <li class="page-item <?=$i===$pg?'active':''?>"><a class="page-link" href="?q=<?=urlencode($q)?>&fecha=<?=e($fecha)?>&p=<?=$i?>"><?=$i?></a></li>
 <?php endfor; ?></ul></nav>
<?php endif;
require_once __DIR__.'/../../includes/footer.php';
