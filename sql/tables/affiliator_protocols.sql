DROP TABLE IF EXISTS affiliator_protocols;

CREATE TABLE affiliator_protocols (
    id BIGSERIAL PRIMARY KEY,
    affiliator_id BIGINT NOT NULL,
    protocol_name   VARCHAR(255) NOT NULL,
    indication  VARCHAR(255),
    protocol_version VARCHAR(20),
    is_posted BOOLEAN NOT NULL DEFAULT FALSE,
    is_revised BOOLEAN NOT NULL DEFAULT FALSE,
    is_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    is_approved BOOLEAN NOT NULL DEFAULT FALSE,
    creator_note    VARCHAR(100),
    reviewer_note VARCHAR(255),
    posted_date TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    create_by  BIGINT,
    updated_at TIMESTAMP DEFAULT NOW(),
    updated_by  BIGINT
);
