DROP TABLE IF EXISTS admin_protocols;

CREATE TABLE admin_protocols (
    id BIGSERIAL PRIMARY KEY,
    protocol_name   VARCHAR(255) NOT NULL,
    indication  VARCHAR(100),
    protocol_version VARCHAR(20),
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    create_by  BIGINT,
    updated_at TIMESTAMP DEFAULT NOW(),
    updated_by  BIGINT
);
