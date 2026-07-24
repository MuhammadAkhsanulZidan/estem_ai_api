CREATE TABLE IF NOT EXISTS ecrf_sections (
    id INT PRIMARY KEY,
    section_name VARCHAR(100) NOT NULL UNIQUE
);

-- Seed initial data
INSERT INTO ecrf_sections (id, section_name) VALUES
(1, 'Persiapan Pasien'),
(2, 'Pelaksanaan Intervensi'),
(3, 'Monitoring Pasca Intervensi'),
(4, 'Evaluasi Outcome')
ON CONFLICT (id) DO UPDATE SET section_name = EXCLUDED.section_name;
