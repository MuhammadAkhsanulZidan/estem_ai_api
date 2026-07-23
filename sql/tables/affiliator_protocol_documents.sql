DROP TABLE IF EXISTS affiliator_protocol_documents;

CREATE TABLE affiliator_protocol_documents (
    id BIGSERIAL PRIMARY KEY,
    protocol_id BIGINT NOT NULL,
    document_path   VARCHAR(100)
);
