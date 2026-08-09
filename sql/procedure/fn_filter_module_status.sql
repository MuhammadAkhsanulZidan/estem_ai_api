CREATE OR REPLACE FUNCTION fn_filter_module_status(
    p_filter_status VARCHAR(50),
    p_is_posted BOOLEAN,
    p_is_reviewed BOOLEAN,
    p_is_approved BOOLEAN,
    p_is_revised BOOLEAN
)
RETURNS BOOLEAN AS $$
BEGIN
    RETURN fn_get_module_status(p_is_posted, p_is_reviewed, p_is_approved, p_is_revised) = p_filter_status;
END;
$$ LANGUAGE plpgsql IMMUTABLE;
