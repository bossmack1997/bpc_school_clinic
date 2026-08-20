-- Migration: Add valid_id_path to students table for student registration uploads
-- Run this file in phpMyAdmin or MySQL CLI

USE bpc_clinic_system;

ALTER TABLE students
    ADD COLUMN valid_id_path VARCHAR(255) DEFAULT NULL AFTER section;
