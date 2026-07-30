DROP TABLE IF EXISTS patient_ecrfs CASCADE;

CREATE TABLE patient_ecrfs (
    id SERIAL PRIMARY KEY,
    affiliator_id INT NOT NULL REFERENCES affiliators(id) ON DELETE CASCADE,
    protocol_id INT NOT NULL REFERENCES affiliator_protocols(id) ON DELETE CASCADE,
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

-- -- 1. Drop the existing foreign key constraint pointing to admin_protocols
-- ALTER TABLE patient_ecrfs
--   DROP CONSTRAINT IF EXISTS patient_ecrfs_protocol_id_fkey;

-- -- 2. Add the new foreign key constraint pointing to affiliator_protocols
-- ALTER TABLE patient_ecrfs
--   ADD CONSTRAINT patient_ecrfs_protocol_id_fkey
--   FOREIGN KEY (protocol_id)
--   REFERENCES affiliator_protocols(id)
--   ON DELETE CASCADE;
