-- Migration 008: Add CITE Programs (BSIS, BSCS, BSCpE, BSECE)
-- Adds the new programs under the CITE department

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BSIS', 'Bachelor of Science in Information Systems', NULL
FROM departments d WHERE d.department_code = 'CITE'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BSCS', 'Bachelor of Science in Computer Science', NULL
FROM departments d WHERE d.department_code = 'CITE'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BSCpE', 'Bachelor of Science in Computer Engineering', d.dean_user_id
FROM departments d WHERE d.department_code = 'CITE'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name), program_head_user_id = VALUES(program_head_user_id);

INSERT INTO programs (department_id, program_code, program_name, program_head_user_id)
SELECT d.id, 'BSECE', 'Bachelor of Science in Electronics Engineering', NULL
FROM departments d WHERE d.department_code = 'CITE'
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);
