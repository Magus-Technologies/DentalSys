<?php
require_once __DIR__.'/../../includes/config.php';
requiereRol('admin');
$accion=$_GET['accion']??'lista'; $id=(int)($_GET['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 $ap=$_POST['accion']??'';
 if($ap==='guardar'){
  $ei=(int)($_POST['id']??0);
  $d=['rol_id'=>(int)$_POST['rol_id'],'nombre'=>trim($_POST['nombre']??''),'apellidos'=>trim($_POST['apellidos']??''),'dni'=>trim($_POST['dni']??''),'email'=>trim($_POST['email']??''),'telefono'=>trim($_POST['telefono']??''),'especialidad'=>trim($_POST['especialidad']??''),'cmp'=>trim($_POST['cmp']??''),'activo'=>isset($_POST['activo'])?1:0];
  if($ei){
   $sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($d)));
   if(!empty($_POST['password'])) {$sets.=',password=?';db()->prepare("UPDATE usuarios SET $sets,updated_at=NOW() WHERE id=?")->execute([...array_values($d),password_hash($_POST['password'],PASSWORD_BCRYPT),$ei]);}
   else{db()->prepare("UPDATE usuarios SET $sets,updated_at=NOW() WHERE id=?")->execute([...array_values($d),$ei]);}
   flash('ok','Usuario actualizado.');
  } else {
   if(empty($_POST['password'])){flash('error','La contraseña es obligatoria para nuevos usuarios.');go('pages/admin/usuarios.php?accion=nuevo');}
   $d['password']=password_hash($_POST['password'],PASSWORD_BCRYPT);
   $cols=implode(',',array_keys($d));$phs=implode(',',array_fill(0,count($d),'?'));
   db()->prepare("INSERT INTO usuarios($cols)VALUES($phs)")->execute(array_values($d));
   flash('ok','Usuario creado.');
  }
  go('pages/admin/usuarios.php');
 }
}

if($accion==='lista'){
 $titulo='Usuarios y Roles'; $pagina_activa='usr';
 $lista=db()->query("SELECT u.*,r.nombre AS rol FROM usuarios u JOIN roles r ON u.rol_id=r.id ORDER BY u.activo DESC,r.id,u.nombre")->fetchAll();
 $topbar_act='<a href="?accion=nuevo" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Nuevo usuario</a>';
 require_once __DIR__.'/../../includes/header.php';
?>
<div class="card"><div class="table-responsive"><table class="table mb-0">
 <thead><tr><th>Usuario</th><th>Email</th><th>Rol</th><th>CMP/Esp.</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
 <tbody>
 <?php $rcls=['admin'=>'br','doctor'=>'bc','recepcion'=>'bg','contador'=>'ba','paciente'=>'bgr'];
 foreach($lista as $u): ?>
 <tr>
  <td><div class="d-flex align-items-center gap-2"><div class="ava" style="width:30px;height:30px;font-size:11px"><?=strtoupper(substr($u['nombre'],0,1))?></div><div><strong><?=e($u['nombre'].' '.$u['apellidos'])?></strong><?php if($u['dni']): ?><br><small><?=e($u['dni'])?></small><?php endif; ?></div></div></td>
  <td><small><?=e($u['email'])?></small></td>
  <td><span class="badge <?=$rcls[$u['rol']]??'bgr'?>"><?=strtoupper($u['rol'])?></span></td>
  <td><small><?=e($u['cmp']??'').' '.e($u['especialidad']??'')?></small></td>
  <td><span class="badge <?=$u['activo']?'bg':'br'?>"><?=$u['activo']?'Activo':'Inactivo'?></span></td>
  <td><small><?=$u['ultimo_acceso']?fDT($u['ultimo_acceso']):'Nunca'?></small></td>
  <td><a href="?accion=editar&id=<?=$u['id']?>" class="btn btn-dk btn-ico"><i class="bi bi-pencil"></i></a></td>
 </tr>
 <?php endforeach; ?></tbody>
</table></div></div>
<?php require_once __DIR__.'/../../includes/footer.php';

}elseif(in_array($accion,['nuevo','editar'])){
 $u=['id'=>0,'rol_id'=>3,'nombre'=>'','apellidos'=>'','dni'=>'','email'=>'','telefono'=>'','especialidad'=>'','cmp'=>'','activo'=>1];
 if($accion==='editar'&&$id){$s=db()->prepare("SELECT * FROM usuarios WHERE id=?");$s->execute([$id]);$u=$s->fetch()?:$u;}
 $roles=db()->query("SELECT * FROM roles ORDER BY id")->fetchAll();
 $titulo=$accion==='nuevo'?'Nuevo Usuario':'Editar: '.$u['nombre'].' '.$u['apellidos']; $pagina_activa='usr';
 require_once __DIR__.'/../../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-7">
<form method="POST">
 <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?=$u['id']?>">
 <div class="card mb-4"><div class="card-header"><span><i class="bi bi-person-badge me-1"></i>Datos del usuario</span></div>
 <div class="p-4"><div class="row g-3">
  <div class="col-12 col-md-4"><label class="form-label">Nombres *</label><input type="text" name="nombre" class="form-control" value="<?=e($u['nombre'])?>" required></div>
  <div class="col-12 col-md-4"><label class="form-label">Apellidos *</label><input type="text" name="apellidos" class="form-control" value="<?=e($u['apellidos'])?>" required></div>
  <div class="col-12 col-md-4"><label class="form-label">DNI</label><input type="text" name="dni" class="form-control" value="<?=e($u['dni']??'')?>"></div>
  <div class="col-12 col-md-6"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" value="<?=e($u['email'])?>" required></div>
  <div class="col-12 col-md-6"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?=e($u['telefono']??'')?>"></div>
  <div class="col-12 col-md-4"><label class="form-label">Rol *</label>
  <select name="rol_id" class="form-select" required>
   <?php foreach($roles as $r): ?><option value="<?=$r['id']?>" <?=$u['rol_id']==$r['id']?'selected':''?>><?=ucfirst($r['nombre'])?></option><?php endforeach; ?>
  </select></div>
  <div class="col-12 col-md-4"><label class="form-label">Especialidad</label><input type="text" name="especialidad" class="form-control" value="<?=e($u['especialidad']??'')?>" placeholder="Odontología General"></div>
  <div class="col-12 col-md-4"><label class="form-label">CMP (si es doctor)</label><input type="text" name="cmp" class="form-control" value="<?=e($u['cmp']??'')?>" placeholder="CMP-12345"></div>
  <div class="col-12 col-md-6"><label class="form-label">Contraseña <?=$accion==='editar'?'(dejar vacío para no cambiar)':'*'?></label><input type="password" name="password" class="form-control" placeholder="Mínimo 6 caracteres" <?=$accion==='nuevo'?'required':''?>></div>
  <div class="col-12 col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="activo" id="ckAct" <?=$u['activo']?'checked':''?>><label class="form-check-label" for="ckAct">Usuario activo</label></div></div>
 </div></div></div>
 <div class="d-flex gap-2 justify-content-end">
  <a href="?" class="btn btn-dk">Cancelar</a>
  <button type="submit" class="btn btn-primary px-4"><i class="bi bi-floppy me-2"></i>Guardar</button>
 </div>
</form>
</div></div>
<?php require_once __DIR__.'/../../includes/footer.php';
}
