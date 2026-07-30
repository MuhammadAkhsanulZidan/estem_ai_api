DROP TABLE IF EXISTS affiliators CASCADE;

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
    is_approved BOOLEAN NOT NULL DEFAULT FALSE,
    is_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    affiliator_code VARCHAR(50) UNIQUE,
    created_at TIMESTAMP DEFAULT NOW(),
    create_by  BIGINT,
    updated_at TIMESTAMP DEFAULT NOW(),
    updated_by  BIGINT
);

-- Trigger to auto generate unique affiliator_code based on name initials
CREATE OR REPLACE FUNCTION generate_affiliator_code()
RETURNS TRIGGER AS $$
DECLARE
    words text[];
    initial text;
    base_code text;
    new_code text;
    counter integer := 1;
BEGIN
    IF NEW.affiliator_code IS NULL OR NEW.affiliator_code = '' THEN
        -- Split name by spaces
        words := regexp_split_to_array(trim(NEW.affiliator_name), '\s+');
        IF array_length(words, 1) >= 2 THEN
            initial := upper(substring(words[1] from 1 for 1) || substring(words[array_length(words, 1)] from 1 for 1));
        ELSE
            initial := upper(substring(words[1] from 1 for 2));
        END IF;
        
        -- Clean to keep only alphanumeric character
        initial := regexp_replace(initial, '[^A-Z0-9]', '', 'g');
        IF initial = '' THEN
            initial := 'AF';
        END IF;
        
        base_code := initial;
        new_code := base_code;
        
        -- Loop to resolve duplicate keys
        WHILE EXISTS (SELECT 1 FROM affiliators WHERE affiliator_code = new_code) LOOP
            new_code := base_code || '-' || counter;
            counter := counter + 1;
        END LOOP;
        
        NEW.affiliator_code := new_code;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER trg_generate_affiliator_code
BEFORE INSERT ON affiliators
FOR EACH ROW
EXECUTE FUNCTION generate_affiliator_code();
