DROP TABLE users;
DROP TABLE roles;

CREATE TABLE roles (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(25) NOT NULL UNIQUE
); -- Removed the trailing comma and added a semicolon here

INSERT INTO roles (name) VALUES ('admin', 'affiliator', 'reviewer')

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

INSERT INTO users (username, role_id, email, password_hash)
VALUES ('admin', 1, '', 'Rsp@d12345')
