CREATE TABLE IF NOT EXISTS peer_evaluation_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    peer_assignment_id INT NULL,
    evaluator_id INT NOT NULL,
    evaluatee_id INT NOT NULL,
    evaluatee_faculty_id INT NULL,
    department_id INT NULL,
    evaluation_period_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'completed', 'overdue') NOT NULL DEFAULT 'pending',
    locked_at DATETIME NULL,
    regenerated_from_id INT NULL,
    UNIQUE KEY uq_peer_eval_evaluator_period (evaluator_id, evaluation_period_id),
    UNIQUE KEY uq_peer_eval_pair_period (evaluator_id, evaluatee_id, evaluation_period_id),
    KEY idx_peer_eval_evaluatee (evaluatee_id),
    KEY idx_peer_eval_department_period (department_id, evaluation_period_id),
    KEY idx_peer_eval_status (status),
    CONSTRAINT fk_peer_eval_assignment
        FOREIGN KEY (peer_assignment_id) REFERENCES peer_assignments(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_peer_eval_evaluator
        FOREIGN KEY (evaluator_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_peer_eval_evaluatee
        FOREIGN KEY (evaluatee_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_peer_eval_faculty
        FOREIGN KEY (evaluatee_faculty_id) REFERENCES faculty(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_peer_eval_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_peer_eval_period
        FOREIGN KEY (evaluation_period_id) REFERENCES appraisal_periods(id)
        ON DELETE CASCADE
);
