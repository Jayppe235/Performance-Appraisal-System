-- Migration 009: Add COED Education Programs (BEED, BTVTED, BPEd, BTLEd + update BSED)
-- Adds the new programs under the COED department

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BSED', 'Bachelor of Secondary Education (Major: Filipino, English, Science, Math, Social Studies)', NULL
FROM departments d WHERE d.department_code = 'COED'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BEED', 'Bachelor of Elementary Education', NULL
FROM departments d WHERE d.department_code = 'COED'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BTVTED', 'Bachelor of Technical-Vocational Teacher Education (Major: Food Services Management)', NULL
FROM departments d WHERE d.department_code = 'COED'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BPEd', 'Bachelor of Physical Education', NULL
FROM departments d WHERE d.department_code = 'COED'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BTLEd', 'Bachelor of Technology and Livelihood Education (Major: Home Economics)', NULL
FROM departments d WHERE d.department_code = 'COED'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);
