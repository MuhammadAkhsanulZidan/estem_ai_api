DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(200) NOT NULL,
    role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    level_id INT NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    affiliator_id BIGINT,
    reviewer_id BIGINT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
