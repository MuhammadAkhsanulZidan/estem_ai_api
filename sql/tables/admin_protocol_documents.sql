DROP TABLE IF EXISTS admin_protocol_documents;

CREATE TABLE admin_protocol_documents (
    id BIGSERIAL PRIMARY KEY,
    protocol_id BIGINT NOT NULL,
    document_path   VARCHAR(100)
);
