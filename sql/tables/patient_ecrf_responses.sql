DROP TABLE IF EXISTS patient_ecrf_responses CASCADE;

CREATE TABLE patient_ecrf_responses (
    id SERIAL PRIMARY KEY,
    patient_id INT NOT NULL REFERENCES patient_ecrfs(id) ON DELETE CASCADE,
    protocol_id INT NOT NULL REFERENCES admin_protocols(id) ON DELETE CASCADE,
    section_id INT NOT NULL REFERENCES ecrf_sections(id) ON DELETE CASCADE,
    answers_data JSONB DEFAULT '{}'::jsonb,
    is_submitted BOOLEAN DEFAULT FALSE,
    is_approved BOOLEAN DEFAULT FALSE,
    reviewer_note TEXT,
    approved_by INT REFERENCES users(id),
    approved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT REFERENCES users(id),
    updated_by INT REFERENCES users(id),
    CONSTRAINT unique_patient_section UNIQUE (patient_id, protocol_id, section_id)
);
