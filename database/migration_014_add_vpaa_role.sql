ALTER TABLE users
  MODIFY role ENUM('admin_hr', 'vpaa', 'dean', 'program_head', 'teacher') NOT NULL;

ALTER TABLE peer_assignments
  MODIFY evaluator_role ENUM('vpaa', 'dean', 'program_head', 'teacher') NOT NULL;

ALTER TABLE evaluation_rules
  MODIFY evaluator_role ENUM('vpaa', 'dean', 'program_head', 'teacher') NOT NULL;

CREATE TABLE IF NOT EXISTS vpaa_departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vpaa_user_id INT NOT NULL,
  department_code VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vpaa_department (vpaa_user_id, department_code),
  CONSTRAINT fk_vpaa_department_user FOREIGN KEY (vpaa_user_id) REFERENCES users(id) ON DELETE CASCADE
);
