<?php require_once __DIR__.'/includes/config.php'; requiereLogin();
$titulo='Sin permiso'; $pagina_activa=''; require_once __DIR__.'/includes/header.php'; ?>
<div class="text-center py-5"><div style="font-size:60px">🚫</div><h2 class="mt-3">Acceso denegado</h2><p style="color:var(--t2)">No tienes permisos para ver esta página.</p><a href="<?=BASE_URL?>/index.php" class="btn btn-primary">Volver al inicio</a></div>
<?php require_once __DIR__.'/includes/footer.php';
