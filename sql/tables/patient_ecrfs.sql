DROP TABLE IF EXISTS patient_ecrfs CASCADE;

CREATE TABLE patient_ecrfs (
    id SERIAL PRIMARY KEY,
    affiliator_id INT NOT NULL REFERENCES affiliators(id) ON DELETE CASCADE,
    protocol_id INT NOT NULL REFERENCES admin_protocols(id) ON DELETE CASCADE,
    registration_number VARCHAR(100) NOT NULL UNIQUE,
    patient_initial VARCHAR(50) NOT NULL,
    gender VARCHAR(20),
    pic_doctor VARCHAR(150),
    birth_date DATE,
    registration_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT REFERENCES users(id),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INT REFERENCES users(id)
);
