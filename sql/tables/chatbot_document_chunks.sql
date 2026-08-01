DROP TABLE IF EXISTS chatbot_document_chunks CASCADE;

CREATE TABLE chatbot_document_chunks (
    id SERIAL PRIMARY KEY,
    document_id INTEGER NOT NULL REFERENCES chatbot_documents(id) ON DELETE CASCADE,
    page_number INTEGER,
    chunk_index INTEGER NOT NULL,
    content TEXT,
    intent VARCHAR(50),
    search_vector TSVECTOR,
    embedding DOUBLE PRECISION[]
);

CREATE INDEX IF NOT EXISTS chatbot_document_chunks_search_vector_idx ON chatbot_document_chunks USING GIN(search_vector);
