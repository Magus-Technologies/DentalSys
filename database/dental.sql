-- DentalSys — Schema completo MySQL/MariaDB
-- Norma: NT N°022-MINSA/DGSP-V.02 | RM 593-2006/MINSA | SIHCE 2025
SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS roles(id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,nombre VARCHAR(30) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO roles VALUES(1,'admin'),(2,'doctor'),(3,'recepcion'),(4,'contador'),(5,'paciente');

CREATE TABLE IF NOT EXISTS usuarios(id INT UNSIGNED NOT NULL AUTO_INCREMENT,rol_id TINYINT UNSIGNED NOT NULL DEFAULT 3,nombre VARCHAR(100) NOT NULL,apellidos VARCHAR(100) NOT NULL,dni VARCHAR(15) DEFAULT NULL,email VARCHAR(150) NOT NULL,telefono VARCHAR(20) DEFAULT NULL,password VARCHAR(255) NOT NULL,especialidad VARCHAR(100) DEFAULT NULL,cmp VARCHAR(20) DEFAULT NULL,activo TINYINT(1) NOT NULL DEFAULT 1,ultimo_acceso DATETIME DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY email(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auditoria(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,usuario_id INT UNSIGNED DEFAULT NULL,accion VARCHAR(100) NOT NULL,tabla VARCHAR(100) DEFAULT NULL,registro_id INT UNSIGNED DEFAULT NULL,ip VARCHAR(45) DEFAULT NULL,datos TEXT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_usr(usuario_id),KEY idx_date(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pacientes(id INT UNSIGNED NOT NULL AUTO_INCREMENT,codigo VARCHAR(20) NOT NULL,dni VARCHAR(15) DEFAULT NULL,nombres VARCHAR(100) NOT NULL,apellido_paterno VARCHAR(80) NOT NULL,apellido_materno VARCHAR(80) DEFAULT NULL,fecha_nacimiento DATE DEFAULT NULL,sexo ENUM('M','F','O') DEFAULT NULL,estado_civil VARCHAR(30) DEFAULT NULL,ocupacion VARCHAR(100) DEFAULT NULL,telefono VARCHAR(20) DEFAULT NULL,email VARCHAR(150) DEFAULT NULL,direccion TEXT DEFAULT NULL,distrito VARCHAR(100) DEFAULT NULL,tipo_seguro ENUM('ninguno','sis','essalud','privado','otros') DEFAULT 'ninguno',num_seguro VARCHAR(50) DEFAULT NULL,alergias TEXT DEFAULT NULL,enfermedades_base TEXT DEFAULT NULL,medicacion_actual TEXT DEFAULT NULL,cirugia_previa TEXT DEFAULT NULL,embarazo TINYINT(1) DEFAULT 0,fuma TINYINT(1) DEFAULT 0,alcohol TINYINT(1) DEFAULT 0,antecedentes_obs TEXT DEFAULT NULL,contacto_nombre VARCHAR(150) DEFAULT NULL,contacto_telefono VARCHAR(20) DEFAULT NULL,contacto_parentesco VARCHAR(50) DEFAULT NULL,activo TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY codigo(codigo),KEY idx_dni(dni)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS historias_clinicas(id INT UNSIGNED NOT NULL AUTO_INCREMENT,paciente_id INT UNSIGNED NOT NULL,numero_hc VARCHAR(20) NOT NULL,doctor_id INT UNSIGNED DEFAULT NULL,fecha_apertura DATE NOT NULL,motivo_consulta TEXT NOT NULL,enfermedad_actual TEXT DEFAULT NULL,anamnesis TEXT DEFAULT NULL,presion_arterial VARCHAR(20) DEFAULT NULL,peso DECIMAL(5,2) DEFAULT NULL,talla DECIMAL(5,2) DEFAULT NULL,examen_extraoral TEXT DEFAULT NULL,tejidos_blandos TEXT DEFAULT NULL,diagnostico_cie10 VARCHAR(20) DEFAULT NULL,diagnostico_desc TEXT DEFAULT NULL,plan_tratamiento TEXT DEFAULT NULL,estado ENUM('activa','cerrada','archivada') DEFAULT 'activa',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY numero_hc(numero_hc),KEY idx_pac(paciente_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS odontogramas(id INT UNSIGNED NOT NULL AUTO_INCREMENT,hc_id INT UNSIGNED NOT NULL,paciente_id INT UNSIGNED NOT NULL,doctor_id INT UNSIGNED DEFAULT NULL,fecha DATE NOT NULL,observaciones TEXT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_hc(hc_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS odontograma_dientes(id INT UNSIGNED NOT NULL AUTO_INCREMENT,odontograma_id INT UNSIGNED NOT NULL,numero_diente VARCHAR(3) NOT NULL,cara VARCHAR(20) NOT NULL DEFAULT 'total',estado VARCHAR(50) NOT NULL,color ENUM('rojo','azul','negro','verde') DEFAULT 'azul',notas VARCHAR(300) DEFAULT NULL,PRIMARY KEY(id),KEY idx_odont(odontograma_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sillones(id INT UNSIGNED NOT NULL AUTO_INCREMENT,nombre VARCHAR(50) NOT NULL,numero TINYINT NOT NULL,activo TINYINT(1) DEFAULT 1,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO sillones VALUES(1,'Sillón 1',1,1),(2,'Sillón 2',2,1),(3,'Sillón 3',3,1);

CREATE TABLE IF NOT EXISTS citas(id INT UNSIGNED NOT NULL AUTO_INCREMENT,codigo VARCHAR(20) NOT NULL,paciente_id INT UNSIGNED NOT NULL,doctor_id INT UNSIGNED NOT NULL,sillon_id INT UNSIGNED DEFAULT NULL,fecha DATE NOT NULL,hora_inicio TIME NOT NULL,hora_fin TIME NOT NULL,especialidad VARCHAR(100) DEFAULT NULL,motivo TEXT DEFAULT NULL,estado ENUM('pendiente','confirmado','en_atencion','atendido','no_asistio','cancelado') DEFAULT 'pendiente',tipo ENUM('primera_vez','control','urgencia','tratamiento') DEFAULT 'primera_vez',notas TEXT DEFAULT NULL,created_by INT UNSIGNED DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY codigo(codigo),KEY idx_fecha(fecha),KEY idx_pac(paciente_id),KEY idx_doc(doctor_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categorias_tratamiento(id INT UNSIGNED NOT NULL AUTO_INCREMENT,nombre VARCHAR(100) NOT NULL,color VARCHAR(7) DEFAULT '#00D4EE',PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO categorias_tratamiento VALUES(1,'Preventivo','#2ECC8E'),(2,'Restauraciones','#00D4EE'),(3,'Endodoncia','#F5A623'),(4,'Cirugía','#E05252'),(5,'Periodoncia','#8B5CF6'),(6,'Ortodoncia','#EC4899'),(7,'Prótesis','#6366F1'),(8,'Estética','#F59E0B'),(9,'Implantología','#10B981');

CREATE TABLE IF NOT EXISTS tratamientos_catalogo(id INT UNSIGNED NOT NULL AUTO_INCREMENT,categoria_id INT UNSIGNED DEFAULT NULL,codigo VARCHAR(20) DEFAULT NULL,nombre VARCHAR(200) NOT NULL,descripcion TEXT DEFAULT NULL,precio_base DECIMAL(10,2) NOT NULL DEFAULT 0.00,duracion_min INT DEFAULT 60,activo TINYINT(1) DEFAULT 1,PRIMARY KEY(id),KEY idx_cat(categoria_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS planes_tratamiento(id INT UNSIGNED NOT NULL AUTO_INCREMENT,hc_id INT UNSIGNED NOT NULL,paciente_id INT UNSIGNED NOT NULL,doctor_id INT UNSIGNED DEFAULT NULL,fecha DATE NOT NULL,total DECIMAL(10,2) DEFAULT 0.00,estado ENUM('borrador','aprobado','en_proceso','completado','cancelado') DEFAULT 'borrador',aprobado_at DATETIME DEFAULT NULL,notas TEXT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_hc(hc_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plan_detalles(id INT UNSIGNED NOT NULL AUTO_INCREMENT,plan_id INT UNSIGNED NOT NULL,tratamiento_id INT UNSIGNED DEFAULT NULL,nombre_tratamiento VARCHAR(200) NOT NULL,diente VARCHAR(10) DEFAULT NULL,cara VARCHAR(50) DEFAULT NULL,precio DECIMAL(10,2) NOT NULL,sesiones_total TINYINT DEFAULT 1,sesiones_realizadas TINYINT DEFAULT 0,estado ENUM('pendiente','en_proceso','completado','cancelado') DEFAULT 'pendiente',notas TEXT DEFAULT NULL,orden TINYINT DEFAULT 1,PRIMARY KEY(id),KEY idx_plan(plan_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evoluciones(id INT UNSIGNED NOT NULL AUTO_INCREMENT,hc_id INT UNSIGNED NOT NULL,cita_id INT UNSIGNED DEFAULT NULL,doctor_id INT UNSIGNED DEFAULT NULL,fecha DATETIME NOT NULL,descripcion TEXT NOT NULL,procedimiento VARCHAR(200) DEFAULT NULL,diente VARCHAR(20) DEFAULT NULL,medicacion TEXT DEFAULT NULL,proximo_control DATE DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_hc(hc_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS adjuntos(id INT UNSIGNED NOT NULL AUTO_INCREMENT,hc_id INT UNSIGNED NOT NULL,tipo ENUM('radiografia','foto_intraoral','foto_extraoral','documento','otro') NOT NULL,nombre VARCHAR(300) NOT NULL,ruta VARCHAR(500) NOT NULL,descripcion TEXT DEFAULT NULL,subido_por INT UNSIGNED DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_hc(hc_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consentimientos(id INT UNSIGNED NOT NULL AUTO_INCREMENT,paciente_id INT UNSIGNED NOT NULL,hc_id INT UNSIGNED DEFAULT NULL,tipo VARCHAR(100) NOT NULL,contenido TEXT NOT NULL,firma_data MEDIUMTEXT DEFAULT NULL,firmado_at DATETIME DEFAULT NULL,pdf_ruta VARCHAR(500) DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_pac(paciente_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cajas(id INT UNSIGNED NOT NULL AUTO_INCREMENT,usuario_id INT UNSIGNED NOT NULL,fecha_apertura DATETIME NOT NULL,fecha_cierre DATETIME DEFAULT NULL,monto_inicial DECIMAL(10,2) DEFAULT 0.00,monto_final DECIMAL(10,2) DEFAULT NULL,estado ENUM('abierta','cerrada') DEFAULT 'abierta',notas TEXT DEFAULT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagos(id INT UNSIGNED NOT NULL AUTO_INCREMENT,codigo VARCHAR(20) NOT NULL,paciente_id INT UNSIGNED NOT NULL,caja_id INT UNSIGNED DEFAULT NULL,plan_id INT UNSIGNED DEFAULT NULL,cita_id INT UNSIGNED DEFAULT NULL,fecha DATETIME NOT NULL,subtotal DECIMAL(10,2) DEFAULT 0.00,descuento DECIMAL(10,2) DEFAULT 0.00,total DECIMAL(10,2) NOT NULL,metodo ENUM('efectivo','tarjeta_debito','tarjeta_credito','yape','plin','transferencia','otro') DEFAULT 'efectivo',referencia VARCHAR(100) DEFAULT NULL,estado ENUM('pagado','pendiente','anulado') DEFAULT 'pagado',notas TEXT DEFAULT NULL,created_by INT UNSIGNED DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY codigo(codigo),KEY idx_pac(paciente_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pago_detalles(id INT UNSIGNED NOT NULL AUTO_INCREMENT,pago_id INT UNSIGNED NOT NULL,concepto VARCHAR(300) NOT NULL,cantidad DECIMAL(8,2) DEFAULT 1,precio DECIMAL(10,2) NOT NULL,subtotal DECIMAL(10,2) NOT NULL,PRIMARY KEY(id),KEY idx_pago(pago_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventario_categorias(id INT UNSIGNED NOT NULL AUTO_INCREMENT,nombre VARCHAR(100) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO inventario_categorias VALUES(1,'Anestésicos'),(2,'Materiales de restauración'),(3,'Instrumental'),(4,'Medicamentos'),(5,'Insumos generales');

CREATE TABLE IF NOT EXISTS inventario(id INT UNSIGNED NOT NULL AUTO_INCREMENT,categoria_id INT UNSIGNED DEFAULT NULL,codigo VARCHAR(50) DEFAULT NULL,nombre VARCHAR(200) NOT NULL,descripcion TEXT DEFAULT NULL,unidad VARCHAR(30) DEFAULT 'unidad',stock_actual DECIMAL(10,2) DEFAULT 0,stock_minimo DECIMAL(10,2) DEFAULT 0,precio_costo DECIMAL(10,2) DEFAULT 0,proveedor VARCHAR(200) DEFAULT NULL,activo TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_cat(categoria_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventario_lotes(id INT UNSIGNED NOT NULL AUTO_INCREMENT,producto_id INT UNSIGNED NOT NULL,lote VARCHAR(100) DEFAULT NULL,fecha_venc DATE DEFAULT NULL,cantidad DECIMAL(10,2) NOT NULL,precio_costo DECIMAL(10,2) DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_prod(producto_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventario_movimientos(id INT UNSIGNED NOT NULL AUTO_INCREMENT,producto_id INT UNSIGNED NOT NULL,tipo ENUM('entrada','salida','ajuste') NOT NULL,cantidad DECIMAL(10,2) NOT NULL,stock_antes DECIMAL(10,2) DEFAULT 0,stock_despues DECIMAL(10,2) DEFAULT 0,motivo VARCHAR(200) DEFAULT NULL,usuario_id INT UNSIGNED DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_prod(producto_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS configuracion(id INT UNSIGNED NOT NULL AUTO_INCREMENT,clave VARCHAR(100) NOT NULL,valor TEXT DEFAULT NULL,grupo VARCHAR(50) DEFAULT 'general',PRIMARY KEY(id),UNIQUE KEY clave(clave)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notificaciones(id INT UNSIGNED NOT NULL AUTO_INCREMENT,tipo ENUM('whatsapp','email','sistema') DEFAULT 'sistema',destinatario VARCHAR(200) DEFAULT NULL,asunto VARCHAR(300) DEFAULT NULL,mensaje TEXT NOT NULL,estado ENUM('pendiente','enviado','fallido') DEFAULT 'pendiente',referencia_tipo VARCHAR(50) DEFAULT NULL,referencia_id INT UNSIGNED DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS turnos(id INT UNSIGNED NOT NULL AUTO_INCREMENT,cita_id INT UNSIGNED NOT NULL,numero INT NOT NULL,nombre_mostrar VARCHAR(100) DEFAULT NULL,estado ENUM('esperando','llamado','en_atencion','atendido') DEFAULT 'esperando',llamado_at DATETIME DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;

-- password: "password" (bcrypt)
INSERT IGNORE INTO usuarios(id,rol_id,nombre,apellidos,email,password,especialidad,cmp,activo) VALUES
(1,1,'Administrador','Sistema','admin@dental.com','$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',NULL,NULL,1),
(2,2,'Dr. Carlos','Mendoza Ríos','doctor@dental.com','$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Odontología General','CMP-12345',1),
(3,3,'María','López García','recepcion@dental.com','$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',NULL,NULL,1);

INSERT IGNORE INTO tratamientos_catalogo(categoria_id,codigo,nombre,precio_base,duracion_min) VALUES
(1,'P001','Profilaxis dental (limpieza)',80.00,45),(1,'P002','Aplicación de flúor',40.00,20),(1,'P003','Sellantes dentales (x pieza)',35.00,30),
(2,'R001','Resina clase I',120.00,45),(2,'R002','Resina clase II',150.00,60),(2,'R003','Resina clase III',130.00,45),(2,'R004','Resina clase IV',180.00,60),
(3,'E001','Conductos premolar',350.00,90),(3,'E002','Conductos molar',450.00,120),(3,'E003','Conductos incisivo',300.00,75),
(4,'C001','Extracción simple',150.00,30),(4,'C002','Extracción compleja',250.00,60),(4,'C003','Extracción cordal',300.00,60),(4,'C004','Cirugía cordal retenido',500.00,90),
(5,'PE001','Raspado y alisado (cuadrante)',200.00,60),(5,'PE002','Curetaje periodontal',80.00,30),(5,'PE003','Gingivectomía',350.00,60),
(6,'O001','Consulta ortodoncia',100.00,45),(6,'O002','Ortodoncia fija metálica',2800.00,60),(6,'O003','Ortodoncia fija cerámica',3500.00,60),(6,'O005','Control ortodoncia',80.00,30),
(7,'PR001','Corona metal-porcelana',650.00,90),(7,'PR002','Corona zirconio',950.00,90),(7,'PR005','Prótesis total (arcada)',1200.00,60),
(8,'ES001','Blanqueamiento consultorio',350.00,90),(8,'ES003','Carilla de porcelana',800.00,90),(8,'ES004','Carilla de resina',250.00,60),
(9,'I001','Implante unitario',1800.00,90),(9,'I002','Implante All-on-4',8000.00,180);

INSERT IGNORE INTO inventario(categoria_id,codigo,nombre,unidad,stock_actual,stock_minimo,precio_costo,proveedor) VALUES
(1,'ANE001','Lidocaína 2% c/epinefrina (caja 50)','caja',5,3,45.00,'Dental Supply SAC'),
(2,'MAT001','Resina compuesta A2 (jeringa 4g)','unidad',12,5,28.00,'3M Dental Peru'),
(2,'MAT002','Cemento de ionómero vítreo (polvo+líq)','kit',4,2,65.00,'GC Dental'),
(4,'MED001','Ibuprofeno 400mg (caja 100 tab)','caja',8,3,18.00,'Farmindustria'),
(5,'INS001','Guantes nitrilo M (caja 100)','caja',6,4,25.00,'Medic Pro'),
(5,'INS002','Mascarillas N95 (caja 20)','caja',3,2,35.00,'3M Peru');

INSERT IGNORE INTO configuracion(clave,valor,grupo) VALUES
('clinica_nombre','Clínica Dental Magus','general'),('clinica_ruc','20123456789','general'),
('clinica_direccion','Av. Principal 123, Lima','general'),('clinica_telefono','01-2345678','general'),
('clinica_email','info@dental.com','general'),('director_nombre','Dr. Carlos Mendoza','general'),
('director_cmp','CMP-12345','general'),('moneda','S/','general'),
('hora_inicio','08:00','agenda'),('hora_fin','20:00','agenda'),('duracion_cita','30','agenda'),
('cuenta_yape','987654321','pagos'),('cuenta_bcp','191-123456789','pagos'),
('igv','18','pagos'),('plantilla_wa_cita','Estimado(a) *{nombre}*, le recordamos su cita en *{clinica}* el *{fecha}* a las *{hora}*. Ante dudas: {telefono}','notificaciones');
