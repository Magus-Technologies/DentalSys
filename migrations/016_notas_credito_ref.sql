-- Migration: 015_notas_credito_ref
-- Permite que una nota de crédito referencie a otra nota de crédito (NC-de-NC).
-- Necesario para corregir NCs ya aceptadas por SUNAT con error de IGV.

ALTER TABLE notas_credito
    ADD COLUMN nota_ref_id INT NULL AFTER pago_id;
