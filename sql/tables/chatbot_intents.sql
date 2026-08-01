DROP TABLE IF EXISTS chatbot_intents CASCADE;

CREATE TABLE chatbot_intents (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
