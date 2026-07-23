DROP TABLE IF EXISTS affiliator_protocols;

CREATE TABLE affiliator_protocols (
    id BIGSERIAL PRIMARY KEY,
    affiliator_id BIGINT NOT NULL,
    protocol_name   VARCHAR(255) NOT NULL,
    indication  VARCHAR(100),
    protocol_version VARCHAR(20),
    status_id VARCHAR(20) NOT NULL,
    creator_note    VARCHAR(100),
    reviewer_note VARCHAR(100),
    created_at TIMESTAMP DEFAULT NOW(),
    create_by  BIGINT,
    updated_at TIMESTAMP DEFAULT NOW(),
    updated_by  BIGINT
);
