CREATE TABLE IF NOT EXISTS admin_protocol_ecrfs (
    id SERIAL PRIMARY KEY,
    protocol_id INT NOT NULL REFERENCES admin_protocols(id) ON DELETE CASCADE,
    section_id INT NOT NULL REFERENCES ecrf_sections(id),
    questions_schema JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_by INT REFERENCES users(id),
    updated_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_protocol_section UNIQUE (protocol_id, section_id)
);
