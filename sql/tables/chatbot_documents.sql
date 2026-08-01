DROP TABLE IF EXISTS chatbot_documents CASCADE;

CREATE TABLE chatbot_documents (
    id SERIAL PRIMARY KEY,
    file_path VARCHAR(512) NOT NULL UNIQUE,
    file_name VARCHAR(256) NOT NULL,
    last_modified TIMESTAMP NOT NULL,
    last_parsed TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
