CREATE TABLE IF NOT EXISTS affiliator_supervisions (
    id SERIAL PRIMARY KEY,
    affiliator_id INT NOT NULL REFERENCES affiliators(id) ON DELETE CASCADE,
    pic_name VARCHAR(150),
    status VARCHAR(50) DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'review', 'revision', 'approved', 'active')),
    review_notes TEXT,
    approved_by INT REFERENCES users(id),
    approved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT REFERENCES users(id),
    updated_by INT REFERENCES users(id),
    CONSTRAINT unique_affiliator_supervision UNIQUE (affiliator_id)
);
