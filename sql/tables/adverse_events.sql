DROP TABLE IF EXISTS adverse_events CASCADE;

CREATE TABLE adverse_events (
    id SERIAL PRIMARY KEY,
    affiliator_id INT NOT NULL REFERENCES affiliators(id) ON DELETE CASCADE,
    report_number VARCHAR(100) NOT NULL UNIQUE,
    patient_id INT NOT NULL REFERENCES patient_ecrfs(id) ON DELETE CASCADE,
    protocol_id INT NOT NULL REFERENCES admin_protocols(id) ON DELETE CASCADE,
    event_type VARCHAR(255) NOT NULL,
    severity INT NOT NULL, -- Ringan, Sedang, Serius (SAE)
    is_finished BOOLEAN NOT NULL DEFAULT FALSE,
    action_taken TEXT,
    reporter_name VARCHAR(150),
    report_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT REFERENCES users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INT REFERENCES users(id)
);
