DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;

CREATE TABLE roles (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(25) NOT NULL UNIQUE
);

-- Fixed multi-row syntax and added semicolon
INSERT INTO roles (name) VALUES ('admin'), ('affiliator'), ('reviewer');

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(200) NOT NULL,
    role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    affiliator_id BIGINT,
    reviewer_id BIGINT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Added a valid placeholder email and the ending semicolon
INSERT INTO users (username, role_id, email, password_hash)
VALUES ('admin', 1, '', 'Rsp@d12345');
