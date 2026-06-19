-- Migration 015: Claves SUNAT en tabla configuracion
-- Permite gestionar credenciales SOL y modo desde la BD sin tocar código.

INSERT IGNORE INTO configuracion (clave, valor, categoria) VALUES
  ('sunat_modo',        'produccion', 'sunat'),
  ('sunat_usuario_sol', 'MODDATOS',   'sunat'),
  ('sunat_clave_sol',   'MODDATOS',   'sunat'),
  ('sunat_ubigeo',      '150101',     'sunat'),
  ('sunat_distrito',    'LIMA',       'sunat'),
  ('sunat_provincia',   'LIMA',       'sunat'),
  ('sunat_departamento','LIMA',       'sunat');
