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

    // 4. Create chatbot_intents table
    echo "Creating chatbot_intents table...\n";
    Database::execute("
        CREATE TABLE IF NOT EXISTS chatbot_intents (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) UNIQUE NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 5. Create chatbot_training_data table (for Intent Classifier)
    echo "Creating chatbot_training_data table...\n";
    Database::execute("
        CREATE TABLE IF NOT EXISTS chatbot_training_data (
            id SERIAL PRIMARY KEY,
            phrase TEXT UNIQUE NOT NULL,
            intent VARCHAR(100) REFERENCES chatbot_intents(name) ON UPDATE CASCADE ON DELETE CASCADE
        )
    ");

    // 6. Truncate existing intents and training data for clean seed
    echo "Truncating intents and training data...\n";
    Database::execute("TRUNCATE TABLE chatbot_intents CASCADE;");

    // 7. Seed initial intents
    echo "Seeding initial intents...\n";
    $intents = [
        ['definition', 'Pertanyaan mengenai pengertian/definisi medis'],
        ['procedure', 'Langkah pelaksanaan dan tahapan klinis'],
        ['safety_monitoring', 'Panduan keselamatan dan observasi pasca injeksi'],
        ['general_search', 'Pencarian dokumen umum/fallback']
    ];
    foreach ($intents as $intent) {
        try {
            Database::execute(
                "INSERT INTO chatbot_intents (name, description) VALUES (?, ?) ON CONFLICT (name) DO NOTHING",
                [$intent[0], $intent[1]]
            );
        } catch (\Exception $e) {}
    }

    // 8. Seed initial training data
    echo "Seeding initial training data...\n";
    $initialData = [
    ['apa itu', 'definition'], ['definisi', 'definition'], ['pengertian', 'definition'], ['adalah', 'definition'], ['jelaskan mengenai', 'definition'], ['jelaskan tentang', 'definition'], ['apa yang dimaksud', 'definition'], ['arti', 'definition'], ['maksud', 'definition'], ['apa arti', 'definition'], ['tolong jelaskan', 'definition'], ['uraikan', 'definition'], ['deskripsikan', 'definition'], ['keterangan', 'definition'], ['penjelasan', 'definition'], ['konsep', 'definition'], ['istilah', 'definition'], ['gambaran umum', 'definition'], ['ringkasan pengertian', 'definition'], ['makna', 'definition'], ['apa makna', 'definition'], ['jelaskan secara singkat', 'definition'], ['jelaskan secara umum', 'definition'], ['definisi singkat', 'definition'], ['pengertian umum', 'definition'], ['apa penjelasannya', 'definition'], ['bisa dijelaskan', 'definition'], ['jelaskan definisinya', 'definition'], ['jelaskan pengertiannya', 'definition'], ['informasi dasar', 'definition'], ['prosedur pelaksanaan', 'procedure'], ['tahapan injeksi', 'procedure'], ['bagaimana langkah', 'procedure'], ['cara menggunakan', 'procedure'], ['cara melakukan tindakan', 'procedure'], ['cara melakukan', 'procedure'], ['bagaimana cara', 'procedure'], ['langkah langkah', 'procedure'], ['urutan', 'procedure'], ['proses', 'procedure'], ['tahapan', 'procedure'], ['prosedur', 'procedure'], ['pelaksanaan', 'procedure'], ['cara kerja', 'procedure'], ['mekanisme', 'procedure'], ['bagaimana menjalankan', 'procedure'], ['bagaimana prosesnya', 'procedure'], ['apa saja tahapannya', 'procedure'], ['langkah awal', 'procedure'], ['langkah berikutnya', 'procedure'], ['langkah akhir', 'procedure'], ['instruksi pelaksanaan', 'procedure'], ['panduan pelaksanaan', 'procedure'], ['alur kerja', 'procedure'], ['metode pelaksanaan', 'procedure'], ['cara memulai', 'procedure'], ['cara melaksanakan', 'procedure'], ['tata cara', 'procedure'], ['urutan pelaksanaan', 'procedure'], ['langkah tindakan', 'procedure'], ['observasi pasca injeksi', 'safety_monitoring'], ['efek samping', 'safety_monitoring'], ['reaksi alergi', 'safety_monitoring'], ['keamanan tindakan', 'safety_monitoring'], ['keselamatan pasien', 'safety_monitoring'], ['pantau', 'safety_monitoring'], ['monitor', 'safety_monitoring'], ['periksa', 'safety_monitoring'], ['evaluasi', 'safety_monitoring'], ['amati', 'safety_monitoring'], ['cek kondisi', 'safety_monitoring'], ['cek keamanan', 'safety_monitoring'], ['apakah ada keluhan', 'safety_monitoring'], ['apakah terjadi reaksi', 'safety_monitoring'], ['tanda bahaya', 'safety_monitoring'], ['keluhan setelah tindakan', 'safety_monitoring'], ['pemantauan', 'safety_monitoring'], ['monitoring lanjutan', 'safety_monitoring'], ['observasi', 'safety_monitoring'], ['laporkan kejadian', 'safety_monitoring'], ['tindak lanjut keamanan', 'safety_monitoring'], ['kondisi pasca tindakan', 'safety_monitoring'], ['perubahan kondisi', 'safety_monitoring'], ['gejala yang muncul', 'safety_monitoring'], ['pemantauan keselamatan', 'safety_monitoring'], ['cek efek samping', 'safety_monitoring'], ['monitor kondisi pasien', 'safety_monitoring'], ['periksa keluhan', 'safety_monitoring'], ['amati perubahan', 'safety_monitoring'], ['laporkan efek', 'safety_monitoring'], ['cari', 'general_search'], ['tampilkan', 'general_search'], ['temukan', 'general_search'], ['lihat', 'general_search'], ['ringkas', 'general_search'], ['berikan ringkasan', 'general_search'], ['tampilkan semua', 'general_search'], ['cari informasi', 'general_search'], ['tampilkan bagian', 'general_search'], ['temukan bagian', 'general_search'], ['cari data', 'general_search'], ['tampilkan data', 'general_search'], ['cari hasil', 'general_search'], ['tampilkan hasil', 'general_search'], ['cari informasi terkait', 'general_search'], ['tampilkan ringkasan', 'general_search'], ['temukan informasi', 'general_search'], ['lihat detail', 'general_search'], ['cari pada dokumen', 'general_search'], ['tampilkan pada dokumen', 'general_search'], ['bagian terkait', 'general_search'], ['informasi lengkap', 'general_search'], ['ringkasan dokumen', 'general_search'], ['isi dokumen', 'general_search'], ['pencarian umum', 'general_search'], ['cari penjelasan', 'general_search'], ['tampilkan penjelasan', 'general_search'], ['temukan penjelasan', 'general_search'], ['lihat informasi', 'general_search'], ['cari bagian terkait', 'general_search']
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
