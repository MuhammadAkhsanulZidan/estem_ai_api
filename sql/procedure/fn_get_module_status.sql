CREATE OR REPLACE FUNCTION fn_get_module_status(
    p_is_posted BOOLEAN,
    p_is_reviewed BOOLEAN,
    p_is_approved BOOLEAN,
    p_is_revised BOOLEAN
)
RETURNS VARCHAR(50) AS $$
BEGIN
    -- Draft is only if not posted and not reviewed
    IF NOT p_is_posted AND NOT p_is_reviewed THEN
        RETURN 'draft';
    END IF;

    IF p_is_reviewed THEN
        IF p_is_approved THEN
            RETURN 'approved';
        END IF;
        IF p_is_revised THEN
            RETURN 'revision';
        END IF;
        RETURN 'rejected';
    END IF;

    RETURN 'submitted';
END;
$$ LANGUAGE plpgsql IMMUTABLE;
