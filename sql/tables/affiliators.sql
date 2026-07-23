DROP TABLE IF EXISTS affiliators;

CREATE TABLE affiliators (
    id BIGSERIAL PRIMARY KEY,
    affiliator_name VARCHAR(250) NOT NULL,
    affiliator_type VARCHAR(100) NOT NULL,
    address VARCHAR(100) NOT NULL,
    contact_phone   VARCHAR(50) NOT NULL,
    contact_email   VARCHAR(50) NOT NULL,
    operational_number VARCHAR(50),
    director_name   VARCHAR(100),
    bed_number  INT,
    icu_bed INT,
    isolation_bed   INT,
    policlinic  INT,
    supporting_facility VARCHAR(250),
    app_verification_id BIGINT,
    specialist_number   INT,
    generalist_number   INT,
    nurse_number    INT,
    other_labor_number  INT,
    research_head   VARCHAR(100),
    reasearch_head_contact  VARCHAR(100),
    created_at TIMESTAMP DEFAULT NOW(),
    create_by  BIGINT,
    updated_at TIMESTAMP DEFAULT NOW(),
    updated_by  BIGINT
);
