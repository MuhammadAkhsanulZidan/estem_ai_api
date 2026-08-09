DROP TABLE IF EXISTS affiliator_supervision_documents;

DROP TABLE IF EXISTS affiliator_supervisions;

CREATE TABLE affiliator_supervisions (
    id SERIAL PRIMARY KEY,
    reference_id VARCHAR(150),
    affiliator_id INT NOT NULL REFERENCES affiliators(id) ON DELETE CASCADE,
    pic_name VARCHAR(150),
    is_posted BOOLEAN NOT NULL DEFAULT FALSE,
    is_revised BOOLEAN NOT NULL DEFAULT FALSE,
    is_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    is_approved BOOLEAN NOT NULL DEFAULT FALSE,
    review_notes TEXT,
    approved_by INT REFERENCES users(id),
    approved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT REFERENCES users(id),
    updated_by INT REFERENCES users(id),

    CONSTRAINT unique_affiliator_supervision UNIQUE (affiliator_id),

    -- Rule 1: cannot be approved unless is_posted
    CONSTRAINT check_approved_requires_posted
        CHECK (NOT is_approved OR is_posted),

    -- Rule 3: cannot be approved if revised is 1 (true)
    CONSTRAINT check_approved_when_not_review
        CHECK (NOT is_approved OR is_reviewed),

    -- Rule 4: cannot make is_posted to 0 (false) unless approved/revised is 0 (false)
    CONSTRAINT check_unposted_requires_unapproved
        CHECK (is_posted OR (NOT is_approved))
);
