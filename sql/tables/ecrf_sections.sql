DROP TABLE IF EXISTS ecrf_sections;

CREATE TABLE ecrf_sections (
    id INT PRIMARY KEY,
    section_name VARCHAR(100) NOT NULL UNIQUE
);
