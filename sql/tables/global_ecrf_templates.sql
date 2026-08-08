DROP TABLE IF EXISTS global_ecrf_templates;

CREATE TABLE global_ecrf_templates (
    id SERIAL PRIMARY KEY,
    section_id INT NOT NULL REFERENCES ecrf_sections(id),
    questions_schema JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_by INT REFERENCES users(id),
    updated_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_global_section UNIQUE (section_id)
);
