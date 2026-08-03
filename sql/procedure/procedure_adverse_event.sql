CREATE OR REPLACE PROCEDURE procedure_adverse_event(
    IN p_action VARCHAR(10),
    IN p_affiliator_id INT,
    IN p_protocol_id INT,
    IN p_start_date TIMESTAMP,
    IN p_end_date TIMESTAMP,
    IN p_is_finished BOOLEAN,
    IN p_search VARCHAR(100),
    IN p_limit INT,
    IN p_offset INT,
    OUT p_response JSONB
)
AS $$
DECLARE
    v_sql TEXT;
    v_where TEXT := ' WHERE 1=1';
    v_total_count INT;
    v_records JSONB;
BEGIN
    IF p_action = 'L' THEN
        -- 1. Build dynamic WHERE clauses conditionally
        IF p_affiliator_id IS NOT NULL THEN
            v_where := v_where || ' AND ae.affiliator_id = ' || p_affiliator_id;
        END IF;

        IF p_protocol_id IS NOT NULL THEN
            v_where := v_where || ' AND ae.protocol_id = ' || p_protocol_id;
        END IF;

        IF p_start_date IS NOT NULL THEN
            v_where := v_where || ' AND ae.report_date >= ' || quote_literal(p_start_date);
        END IF;

        IF p_end_date IS NOT NULL THEN
            v_where := v_where || ' AND ae.report_date <= ' || quote_literal(p_end_date);
        END IF;

        IF p_is_finished IS NOT NULL THEN
            v_where := v_where || ' AND ae.is_finished = ' || p_is_finished;
        END IF;

        IF p_search IS NOT NULL AND p_search <> '' THEN
            v_where := v_where || ' AND (p.patient_initial ILIKE ' || quote_literal('%' || p_search || '%') || 
                                  ' OR aff.affiliator_name ILIKE ' || quote_literal('%' || p_search || '%') || ')';
        END IF;

        -- 2. Execute dynamic SQL to calculate the total count
        v_sql := '
            SELECT COUNT(*)::INT
            FROM adverse_events ae
            LEFT JOIN patient_ecrfs p ON ae.patient_id = p.id
            LEFT JOIN affiliators aff ON ae.affiliator_id = aff.id
        ' || v_where;
        EXECUTE v_sql INTO v_total_count;

        -- 3. Execute dynamic SQL to aggregate the paginated rows into a JSONB array
        v_sql := '
            SELECT COALESCE(JSONB_AGG(t), ''[]''::JSONB)
            FROM (
                SELECT
                    ae.id,
                    ae.affiliator_id,
                    ae.report_number,
                    ae.patient_id,
                    ae.protocol_id,
                    ae.event_type,
                    ae.severity,
                    ae.is_finished,
                    ae.action_taken,
                    ae.reporter_name,
                    ae.report_date,
                    ae.created_at,
                    ae.created_by,
                    ae.updated_at,
                    ae.updated_by,
                    p.patient_initial,
                    p.registration_number AS patient_registration_number,
                    ap.protocol_name,
                    ap.protocol_version,
                    aff.affiliator_name
                FROM adverse_events ae
                LEFT JOIN patient_ecrfs p ON ae.patient_id = p.id
                LEFT JOIN affiliator_protocols ap ON ae.protocol_id = ap.id
                LEFT JOIN affiliators aff ON ae.affiliator_id = aff.id
                ' || v_where || '
                ORDER BY ae.id DESC
                LIMIT ' || p_limit || ' OFFSET ' || p_offset || '
            ) t
        ';
        EXECUTE v_sql INTO v_records;

        -- 4. Construct the complete response JSON structure
        p_response := JSONB_BUILD_OBJECT(
            'list', v_records,
            'summary', JSONB_BUILD_OBJECT(
                'total', v_total_count,
                'page_no', CASE WHEN p_limit > 0 THEN (p_offset / p_limit) + 1 ELSE 1 END,
                'page_row', p_limit,
                'total_page', CASE WHEN p_limit > 0 THEN CEIL(v_total_count::FLOAT / p_limit::FLOAT)::INT ELSE 1 END
            )
        );
    END IF;
END;
$$ LANGUAGE plpgsql;
