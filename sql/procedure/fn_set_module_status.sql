CREATE OR REPLACE FUNCTION fn_set_module_status(
    p_status VARCHAR(50)
) 
RETURNS TABLE(
    is_posted BOOLEAN,
    is_reviewed BOOLEAN,
    is_approved BOOLEAN,
    is_revised BOOLEAN
) AS $$
BEGIN
    CASE p_status
        WHEN 'draft' THEN
            is_posted := FALSE;
            is_reviewed := FALSE;
            is_approved := FALSE;
            is_revised := FALSE;
        WHEN 'submitted' THEN
            is_posted := TRUE;
            is_reviewed := FALSE;
            is_approved := FALSE;
            is_revised := FALSE;
        WHEN 'revision' THEN
            is_posted := TRUE;
            is_reviewed := TRUE;
            is_approved := FALSE;
            is_revised := TRUE;
        WHEN 'approved', 'active' THEN
            is_posted := TRUE;
            is_reviewed := TRUE;
            is_approved := TRUE;
            is_revised := FALSE;
        WHEN 'rejected', 'review' THEN
            is_posted := TRUE;
            is_reviewed := TRUE;
            is_approved := FALSE;
            is_revised := FALSE;
        ELSE
            is_posted := FALSE;
            is_reviewed := FALSE;
            is_approved := FALSE;
            is_revised := FALSE;
    END CASE;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql IMMUTABLE;
