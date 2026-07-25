DROP TABLE IF EXISTS affiliator_profile_documents;

CREATE TABLE affiliator_profile_documents (
    id BIGSERIAL PRIMARY KEY,
    affiliator_id BIGINT NOT NULL REFERENCES affiliators(id) ON DELETE CASCADE,
    document_name VARCHAR(250) NOT NULL,
    document_path TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
