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
        ['cek_protokol_detail', 'Detail summary of protocol submissions and documents'],
        ['cek_registrasi_pengampuan_detail', 'Detail summary of hospital site visit and supervisions'],
        ['cek_pasien_detail', 'Detail patient medical file, eCRF progress, and side effects'],
        ['cek_efek_samping_detail', 'Detail patient adverse event reports and safety monitoring'],
        ['cek_kriteria_pasien', 'Detail of patient inclusion and exclusion criteria check'],
        ['cek_ecrf_template', 'Detail list of questions configured for eCRF sections'],
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
        // cek_protokol_detail (30 phrases)
        ['status pengajuan protokol', 'cek_protokol_detail'],
        ['apakah protokol saya sudah disetujui', 'cek_protokol_detail'],
        ['bagaimana progres pengajuan protokol', 'cek_protokol_detail'],
        ['cek status pengajuan', 'cek_protokol_detail'],
        ['apakah pengajuan saya diterima', 'cek_protokol_detail'],
        ['detail protokol saya', 'cek_protokol_detail'],
        ['tampilkan informasi lengkap protokol', 'cek_protokol_detail'],
        ['informasi protokol terbaru', 'cek_protokol_detail'],
        ['cek reviewer protokol', 'cek_protokol_detail'],
        ['siapa yang meninjau protokol saya', 'cek_protokol_detail'],
        ['status persetujuan protokol', 'cek_protokol_detail'],
        ['rincian protokol penelitian', 'cek_protokol_detail'],
        ['lihat dokumen protokol yang diajukan', 'cek_protokol_detail'],
        ['perkembangan pengajuan protokol', 'cek_protokol_detail'],
        ['apakah ada catatan perbaikan protokol', 'cek_protokol_detail'],
        ['protokol saya nomor berapa', 'cek_protokol_detail'],
        ['detail reviewer pengajuan protokol', 'cek_protokol_detail'],
        ['tampilkan draf protokol', 'cek_protokol_detail'],
        ['status persetujuan berkas protokol', 'cek_protokol_detail'],
        ['cek kelengkapan dokumen protokol', 'cek_protokol_detail'],
        ['bagaimana status protokol klinis', 'cek_protokol_detail'],
        ['berkas pengajuan protokol medis', 'cek_protokol_detail'],
        ['detail reviewer yang ditugaskan', 'cek_protokol_detail'],
        ['cek nama reviewer protokol saya', 'cek_protokol_detail'],
        ['status revisi protokol', 'cek_protokol_detail'],
        ['apakah protokol sudah di-review', 'cek_protokol_detail'],
        ['berkas protokol disetujui atau belum', 'cek_protokol_detail'],
        ['lihat riwayat pengajuan protokol', 'cek_protokol_detail'],
        ['progres review protokol penelitian', 'cek_protokol_detail'],
        ['rincian informasi pengajuan protokol', 'cek_protokol_detail'],

        // cek_registrasi_pengampuan_detail (30 phrases)
        ['status registrasi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['apakah pengampuan rumah sakit disetujui', 'cek_registrasi_pengampuan_detail'],
        ['cek hasil supervisi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['bagaimana status pengampuan faskes', 'cek_registrasi_pengampuan_detail'],
        ['detail registrasi pengampuan rs', 'cek_registrasi_pengampuan_detail'],
        ['catatan perbaikan pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['catatan revisi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['apakah berkas pengampuan sudah lengkap', 'cek_registrasi_pengampuan_detail'],
        ['hasil evaluasi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['status visitasi pengampuan faskes', 'cek_registrasi_pengampuan_detail'],
        ['lihat data pengampuan rs jejaring', 'cek_registrasi_pengampuan_detail'],
        ['cek kelayakan pengampuan rumah sakit', 'cek_registrasi_pengampuan_detail'],
        ['siapa pic registrasi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['tampilkan detail pengampuan faskes', 'cek_registrasi_pengampuan_detail'],
        ['status perizinan pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['catatan dari tim pengampu', 'cek_registrasi_pengampuan_detail'],
        ['perkembangan verifikasi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['cek persetujuan berkas pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['rs kami sudah disetujui pengampuannya', 'cek_registrasi_pengampuan_detail'],
        ['tampilkan hasil audit pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['dokumen supervisi pengampuan faskes', 'cek_registrasi_pengampuan_detail'],
        ['bagaimanakah status pengampuan terbaru', 'cek_registrasi_pengampuan_detail'],
        ['catatan verifikasi pengampuan faskes', 'cek_registrasi_pengampuan_detail'],
        ['cek berkas registrasi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['progres persetujuan registrasi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['detail verifikasi pengampuan rumah sakit', 'cek_registrasi_pengampuan_detail'],
        ['evaluasi visitasi pengampuan rs', 'cek_registrasi_pengampuan_detail'],
        ['cek status pengampuan rumah sakit', 'cek_registrasi_pengampuan_detail'],
        ['rincian catatan revisi pengampuan', 'cek_registrasi_pengampuan_detail'],
        ['lihat perkembangan registrasi pengampuan', 'cek_registrasi_pengampuan_detail'],

        // cek_pasien_detail (30 phrases)
        ['detail data pasien', 'cek_pasien_detail'],
        ['cek rekam medis pasien', 'cek_pasien_detail'],
        ['tampilkan status pasien stem cell', 'cek_pasien_detail'],
        ['bagaimana status ecrf pasien', 'cek_pasien_detail'],
        ['kelengkapan ecrf pasien', 'cek_pasien_detail'],
        ['ecrf yang belum diisi untuk pasien', 'cek_pasien_detail'],
        ['informasi pasien berdasarkan nomor registrasi', 'cek_pasien_detail'],
        ['data medis pasien Ani', 'cek_pasien_detail'],
        ['cek inisial pasien', 'cek_pasien_detail'],
        ['daftar ecrf pasien yang belum lengkap', 'cek_pasien_detail'],
        ['rincian data klinis pasien', 'cek_pasien_detail'],
        ['progres pengisian ecrf pasien', 'cek_pasien_detail'],
        ['cek biodata pasien stemcell', 'cek_pasien_detail'],
        ['siapa dokter pelaksana pasien ini', 'cek_pasien_detail'],
        ['riwayat terapi pasien', 'cek_pasien_detail'],
        ['tanggal registrasi pasien di sistem', 'cek_pasien_detail'],
        ['cek status pengisian form pasien', 'cek_pasien_detail'],
        ['detail ecrf pasien terbaru', 'cek_pasien_detail'],
        ['data klinis pasien stem cell', 'cek_pasien_detail'],
        ['apakah ecrf pasien sudah selesai', 'cek_pasien_detail'],
        ['cek kelengkapan form screening pasien', 'cek_pasien_detail'],
        ['status kunjungan/follow-up pasien', 'cek_pasien_detail'],
        ['rincian rekam medis pasien terdaftar', 'cek_pasien_detail'],
        ['data ecrf pasca tindakan pasien', 'cek_pasien_detail'],
        ['cek data awal pasien masuk', 'cek_pasien_detail'],
        ['status ecrf dosis 1 pasien', 'cek_pasien_detail'],
        ['kelengkapan berkas pasien stem cell', 'cek_pasien_detail'],
        ['cek data monitoring pasien', 'cek_pasien_detail'],
        ['detail pasien berdasarkan inisial', 'cek_pasien_detail'],
        ['progres ecrf pasien uji klinis', 'cek_pasien_detail'],

        // cek_efek_samping_detail (30 phrases)
        ['laporan efek samping pasien', 'cek_efek_samping_detail'],
        ['cek kejadian efek samping terbaru', 'cek_efek_samping_detail'],
        ['adverse event pasien stem cell', 'cek_efek_samping_detail'],
        ['keluhan efek samping setelah tindakan', 'cek_efek_samping_detail'],
        ['rincian laporan efek samping', 'cek_efek_samping_detail'],
        ['daftar efek samping yang dilaporkan', 'cek_efek_samping_detail'],
        ['pasien mengalami keluhan tidak diinginkan', 'cek_efek_samping_detail'],
        ['tingkat keparahan efek samping pasien', 'cek_efek_samping_detail'],
        ['cek laporan ktd pasien', 'cek_efek_samping_detail'],
        ['apa saja keluhan pasien pasca terapi', 'cek_efek_samping_detail'],
        ['laporan efek samping terbaru', 'cek_efek_samping_detail'],
        ['cek ktd atau adverse event', 'cek_efek_samping_detail'],
        ['detail keluhan reaksi pasien', 'cek_efek_samping_detail'],
        ['keluhan nyeri atau kemerahan pasien', 'cek_efek_samping_detail'],
        ['ktd yang dilaporkan rumah sakit', 'cek_efek_samping_detail'],
        ['monitoring kejadian efek samping', 'cek_efek_samping_detail'],
        ['rincian ktd pasien stem cell', 'cek_efek_samping_detail'],
        ['tingkat keparahan ktd pasien', 'cek_efek_samping_detail'],
        ['cek laporan adverse event faskes', 'cek_efek_samping_detail'],
        ['keluhan pasca injeksi sel punca', 'cek_efek_samping_detail'],
        ['apakah ada pasien mengalami efek samping', 'cek_efek_samping_detail'],
        ['laporan kejadian tidak diinginkan terbaru', 'cek_efek_samping_detail'],
        ['detail ktd yang tercatat di sistem', 'cek_efek_samping_detail'],
        ['keluhan alergi setelah tindakan', 'cek_efek_samping_detail'],
        ['cek keluhan reaksi lokal pasien', 'cek_efek_samping_detail'],
        ['rekapitulasi efek samping pasien', 'cek_efek_samping_detail'],
        ['rincian keluhan pasca tindakan medis', 'cek_efek_samping_detail'],
        ['cek laporan keamanan pasien', 'cek_efek_samping_detail'],
        ['status laporan efek samping pasien', 'cek_efek_samping_detail'],

        // cek_kriteria_pasien (30 phrases)
        ['cek inklusi pasien', 'cek_kriteria_pasien'],
        ['apakah pasien memenuhi syarat inklusi', 'cek_kriteria_pasien'],
        ['kriteria eksklusi pasien', 'cek_kriteria_pasien'],
        ['apa saja syarat inklusi untuk protokol', 'cek_kriteria_pasien'],
        ['cek kriteria kelayakan pasien', 'cek_kriteria_pasien'],
        ['apakah pasien ini eksklusi', 'cek_kriteria_pasien'],
        ['bagaimana status inklusi pasien', 'cek_kriteria_pasien'],
        ['tampilkan kriteria eksklusi', 'cek_kriteria_pasien'],
        ['lihat kriteria inklusi dan eksklusi', 'cek_kriteria_pasien'],
        ['kriteria kelayakan pasien ecrf', 'cek_kriteria_pasien'],
        ['apakah pasien memenuhi syarat', 'cek_kriteria_pasien'],
        ['cek syarat eksklusi', 'cek_kriteria_pasien'],
        ['daftar kriteria inklusi protokol', 'cek_kriteria_pasien'],
        ['apakah pasien layak masuk penelitian', 'cek_kriteria_pasien'],
        ['cek data persiapan pasien', 'cek_kriteria_pasien'],
        ['kriteria persiapan pasien ecrf', 'cek_kriteria_pasien'],
        ['apakah pasien ini masuk kriteria eksklusi', 'cek_kriteria_pasien'],
        ['syarat penerimaan pasien', 'cek_kriteria_pasien'],
        ['kriteria pembatalan pasien', 'cek_kriteria_pasien'],
        ['eksklusi pasien stem cell', 'cek_kriteria_pasien'],
        ['inklusi pasien terapi', 'cek_kriteria_pasien'],
        ['apakah pasien aman untuk pemberian terapi', 'cek_kriteria_pasien'],
        ['cek tanda vital persiapan pasien', 'cek_kriteria_pasien'],
        ['laporan kelayakan pasien', 'cek_kriteria_pasien'],
        ['apakah pasien lolos kriteria inklusi', 'cek_kriteria_pasien'],
        ['cek kelayakan klinis pasien', 'cek_kriteria_pasien'],
        ['kriteria inklusi klinis', 'cek_kriteria_pasien'],
        ['cek eksklusi ecrf', 'cek_kriteria_pasien'],
        ['status kelayakan ecrf pasien', 'cek_kriteria_pasien'],

        // cek_ecrf_template (30 phrases)
        ['pertanyaan apa saja di ecrf', 'cek_ecrf_template'],
        ['ecrf secretome', 'cek_ecrf_template'],
        ['variabel wajib ecrf', 'cek_ecrf_template'],
        ['form ecrf yang diisi', 'cek_ecrf_template'],
        ['detail ecrf', 'cek_ecrf_template'],
        ['cek ecrf', 'cek_ecrf_template'],
        ['apa saja form ecrf', 'cek_ecrf_template'],
        ['lihat template ecrf', 'cek_ecrf_template'],
        ['tampilkan isi ecrf', 'cek_ecrf_template'],
        ['daftar isian ecrf', 'cek_ecrf_template'],
        ['kuesioner ecrf', 'cek_ecrf_template'],
        ['variabel ecrf', 'cek_ecrf_template'],
        ['format ecrf', 'cek_ecrf_template'],
        ['struktur ecrf', 'cek_ecrf_template'],
        ['apa saja variabel ecrf', 'cek_ecrf_template'],
        ['pertanyaan persiapan pasien ecrf', 'cek_ecrf_template'],
        ['form intervensi ecrf', 'cek_ecrf_template'],
        ['cek list pertanyaan ecrf', 'cek_ecrf_template'],
        ['daftar pertanyaan ecrf', 'cek_ecrf_template'],
        ['variabel wajib diisi ecrf', 'cek_ecrf_template'],
        ['pertanyaan ecrf ucmsc', 'cek_ecrf_template'],
        ['apakah ecrf memiliki kriteria', 'cek_ecrf_template'],
        ['isi form ecrf', 'cek_ecrf_template'],
        ['tampilkan ecrf', 'cek_ecrf_template'],
        ['lihat variabel ecrf', 'cek_ecrf_template'],
        ['cek pertanyaan ecrf', 'cek_ecrf_template'],
        ['apa saja kolom ecrf', 'cek_ecrf_template'],
        ['detail formulir ecrf', 'cek_ecrf_template'],
        ['panduan variabel ecrf', 'cek_ecrf_template'],
        ['pertanyaan wajib ecrf', 'cek_ecrf_template'],

        // document intents
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
