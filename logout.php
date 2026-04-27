<?php
require_once __DIR__.'/includes/config.php';
sesion(); auditar('LOGOUT'); session_destroy();
header('Location:'.BASE_URL.'/login.php'); exit;
