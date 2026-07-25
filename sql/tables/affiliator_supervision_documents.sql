DROP TABLE IF EXISTS affiliator_supervision_documents;

CREATE TABLE affiliator_supervision_documents (
    id SERIAL PRIMARY KEY,
    supervision_id INT NOT NULL REFERENCES affiliator_supervisions(id) ON DELETE CASCADE,
    document_path VARCHAR(250) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
