<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

try {
    echo "=== Starting Chatbot DB Migration ===\n";

    // 1. Create chatbot_documents table
    echo "Creating chatbot_documents table...\n";
    Database::execute("
        CREATE TABLE IF NOT EXISTS chatbot_documents (
            id SERIAL PRIMARY KEY,
            file_path VARCHAR(512) UNIQUE NOT NULL,
            file_name VARCHAR(256) NOT NULL,
            last_modified TIMESTAMP NOT NULL,
            last_parsed TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 2. Create chatbot_document_chunks table
    echo "Creating chatbot_document_chunks table...\n";
    Database::execute("
        CREATE TABLE IF NOT EXISTS chatbot_document_chunks (
            id SERIAL PRIMARY KEY,
            document_id INT REFERENCES chatbot_documents(id) ON DELETE CASCADE,
            page_number INT NOT NULL,
            chunk_index INT NOT NULL,
            content TEXT NOT NULL,
            search_vector TSVECTOR,
            embedding REAL[]
        )
    ");

    // 3. Create index on search_vector
    echo "Creating GIN index on search_vector...\n";
    Database::execute("
        CREATE INDEX IF NOT EXISTS chatbot_document_chunks_search_vector_idx 
        ON chatbot_document_chunks USING GIN(search_vector)
    ");

    // 4. Create chatbot_training_data table (for Intent Classifier)
    echo "Creating chatbot_training_data table...\n";
    Database::execute("
        CREATE TABLE IF NOT EXISTS chatbot_training_data (
            id SERIAL PRIMARY KEY,
            phrase TEXT UNIQUE NOT NULL,
            intent VARCHAR(100) NOT NULL
        )
    ");

    // 5. Seed initial training data
    echo "Seeding initial training data...\n";
    $initialData = [
        ['status protokol', 'cek_status_protokol'],
        ['apakah protokol sudah disetujui', 'cek_status_protokol'],
        ['bagaimana progres pengajuan protokol', 'cek_status_protokol'],
        ['cek status pengajuan', 'cek_status_protokol'],
        ['apakah pengajuan saya diterima', 'cek_status_protokol'],
        
        ['laporan efek samping', 'cek_efek_samping'],
        ['kejadian tidak diinginkan', 'cek_efek_samping'],
        ['adverse event terbaru', 'cek_efek_samping'],
        ['pasien mengalami keluhan efek samping', 'cek_efek_samping'],
        ['laporan efek samping stem cell', 'cek_efek_samping'],

        ['siapa reviewer protokol', 'cek_reviewer'],
        ['siapa yang mereview pengajuan', 'cek_reviewer'],
        ['siapa reviewer yang ditugaskan', 'cek_reviewer'],
        ['nama reviewer untuk protokol saya', 'cek_reviewer'],

        ['jumlah pasien terdaftar', 'cek_jumlah_pasien'],
        ['berapa banyak pasien', 'cek_jumlah_pasien'],
        ['berapa pasien yang terregistrasi', 'cek_jumlah_pasien'],
        ['total pasien stemcell', 'cek_jumlah_pasien'],

        ['cari dokumen persetujuan', 'general_search'],
        ['bagaimana cara terapi stem cell', 'general_search'],
        ['baca jurnal stemcell', 'general_search'],
        ['panduan pengisian ecrf', 'general_search']
    ];

    foreach ($initialData as $row) {
        try {
            Database::execute(
                "INSERT INTO chatbot_training_data (phrase, intent) VALUES (?, ?) ON CONFLICT (phrase) DO NOTHING",
                [$row[0], $row[1]]
            );
        } catch (\Exception $e) {
            // Ignore duplicate errors if they occur
        }
    }

    echo "=== Migration Completed Successfully ===\n";
} catch (\Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
