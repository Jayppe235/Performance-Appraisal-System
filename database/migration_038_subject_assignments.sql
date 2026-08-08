CREATE TABLE IF NOT EXISTS subject_areas (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    subject_code VARCHAR(30) NOT NULL,
    subject_name VARCHAR(150) NOT NULL,
    coordinator_faculty_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subject_department_code (department_id, subject_code),
    KEY idx_subject_department_active (department_id, is_active),
    CONSTRAINT fk_subject_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_subject_coordinator FOREIGN KEY (coordinator_faculty_id) REFERENCES faculty(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_subject_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    subject_area_id INT NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_faculty_subject (faculty_id, subject_area_id),
    CONSTRAINT fk_fsa_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    CONSTRAINT fk_fsa_subject FOREIGN KEY (subject_area_id) REFERENCES subject_areas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_period_faculty_subjects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    evaluation_period_id INT NOT NULL,
    user_id INT NOT NULL,
    faculty_id INT NOT NULL,
    subject_area_id INT NOT NULL,
    department_id INT NOT NULL,
    subject_code_snapshot VARCHAR(30) NOT NULL,
    subject_name_snapshot VARCHAR(150) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_coordinator TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period_faculty_subject (evaluation_period_id, faculty_id, subject_area_id),
    CONSTRAINT fk_epfs_period FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_epfs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_epfs_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    CONSTRAINT fk_epfs_subject FOREIGN KEY (subject_area_id) REFERENCES subject_areas(id) ON DELETE RESTRICT,
    CONSTRAINT fk_epfs_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE sa FROM subject_areas sa
JOIN departments d ON d.id=sa.department_id
LEFT JOIN faculty_subject_assignments fsa ON fsa.subject_area_id=sa.id
LEFT JOIN evaluation_period_faculty_subjects epfs ON epfs.subject_area_id=sa.id
WHERE d.department_code<>'CAS'
  AND (
    (sa.subject_code='RE' AND sa.subject_name='Religious Education')
    OR (sa.subject_code='MATH' AND sa.subject_name='Mathematics')
    OR (sa.subject_code='NSTP' AND sa.subject_name='National Service Training Program')
  )
  AND fsa.id IS NULL AND epfs.id IS NULL;

INSERT IGNORE INTO subject_areas (department_id,subject_code,subject_name)
SELECT id,'RE','Religious Education' FROM departments WHERE is_active=1 AND department_code='CAS';
INSERT IGNORE INTO subject_areas (department_id,subject_code,subject_name)
SELECT id,'MATH','Mathematics' FROM departments WHERE is_active=1 AND department_code='CAS';
INSERT IGNORE INTO subject_areas (department_id,subject_code,subject_name)
SELECT id,'NSTP','National Service Training Program' FROM departments WHERE is_active=1 AND department_code='CAS';
