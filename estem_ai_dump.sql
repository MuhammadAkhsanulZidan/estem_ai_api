--
-- PostgreSQL database dump
--

\restrict UVO8Nrl8EipSrw390hNSR9xkHQAHsuGn1U6Zyijui2gPuW2DDaNWDdUA4pIRRih

-- Dumped from database version 14.23 (Ubuntu 14.23-0ubuntu0.22.04.1)
-- Dumped by pg_dump version 14.23 (Ubuntu 14.23-0ubuntu0.22.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: generate_affiliator_code(); Type: FUNCTION; Schema: public; Owner: rspad
--

CREATE FUNCTION public.generate_affiliator_code() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.generate_affiliator_code() OWNER TO rspad;

--
-- Name: hash_user_password(); Type: FUNCTION; Schema: public; Owner: rspad
--

CREATE FUNCTION public.hash_user_password() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Hash the password using bcrypt (gen_salt('bf'))
    NEW.password_hash = crypt(NEW.password_hash, gen_salt('bf'));
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.hash_user_password() OWNER TO rspad;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admin_protocol_documents; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.admin_protocol_documents (
    id bigint NOT NULL,
    protocol_id bigint NOT NULL,
    document_path character varying(100)
);


ALTER TABLE public.admin_protocol_documents OWNER TO rspad;

--
-- Name: admin_protocol_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.admin_protocol_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.admin_protocol_documents_id_seq OWNER TO rspad;

--
-- Name: admin_protocol_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.admin_protocol_documents_id_seq OWNED BY public.admin_protocol_documents.id;


--
-- Name: admin_protocol_ecrfs; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.admin_protocol_ecrfs (
    id integer NOT NULL,
    protocol_id integer NOT NULL,
    section_id integer NOT NULL,
    questions_schema jsonb DEFAULT '[]'::jsonb NOT NULL,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.admin_protocol_ecrfs OWNER TO rspad;

--
-- Name: admin_protocol_ecrfs_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.admin_protocol_ecrfs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.admin_protocol_ecrfs_id_seq OWNER TO rspad;

--
-- Name: admin_protocol_ecrfs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.admin_protocol_ecrfs_id_seq OWNED BY public.admin_protocol_ecrfs.id;


--
-- Name: admin_protocols; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.admin_protocols (
    id bigint NOT NULL,
    protocol_name character varying(255) NOT NULL,
    indication character varying(100),
    protocol_version character varying(20),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    create_by bigint,
    updated_at timestamp without time zone DEFAULT now(),
    updated_by bigint
);


ALTER TABLE public.admin_protocols OWNER TO rspad;

--
-- Name: admin_protocols_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.admin_protocols_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.admin_protocols_id_seq OWNER TO rspad;

--
-- Name: admin_protocols_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.admin_protocols_id_seq OWNED BY public.admin_protocols.id;


--
-- Name: adverse_events; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.adverse_events (
    id integer NOT NULL,
    affiliator_id integer NOT NULL,
    report_number character varying(100) NOT NULL,
    patient_id integer NOT NULL,
    protocol_id integer NOT NULL,
    event_type character varying(255) NOT NULL,
    severity integer NOT NULL,
    is_finished boolean DEFAULT false NOT NULL,
    action_taken text,
    reporter_name character varying(150),
    report_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    created_by integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer
);


ALTER TABLE public.adverse_events OWNER TO rspad;

--
-- Name: adverse_events_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.adverse_events_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.adverse_events_id_seq OWNER TO rspad;

--
-- Name: adverse_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.adverse_events_id_seq OWNED BY public.adverse_events.id;


--
-- Name: affiliator_profile_documents; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.affiliator_profile_documents (
    id bigint NOT NULL,
    affiliator_id bigint NOT NULL,
    document_name character varying(250) NOT NULL,
    document_path text NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.affiliator_profile_documents OWNER TO rspad;

--
-- Name: affiliator_profile_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.affiliator_profile_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.affiliator_profile_documents_id_seq OWNER TO rspad;

--
-- Name: affiliator_profile_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.affiliator_profile_documents_id_seq OWNED BY public.affiliator_profile_documents.id;


--
-- Name: affiliator_protocol_documents; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.affiliator_protocol_documents (
    id bigint NOT NULL,
    protocol_id bigint NOT NULL,
    document_path character varying(100)
);


ALTER TABLE public.affiliator_protocol_documents OWNER TO rspad;

--
-- Name: affiliator_protocol_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.affiliator_protocol_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.affiliator_protocol_documents_id_seq OWNER TO rspad;

--
-- Name: affiliator_protocol_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.affiliator_protocol_documents_id_seq OWNED BY public.affiliator_protocol_documents.id;


--
-- Name: affiliator_protocols; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.affiliator_protocols (
    id bigint NOT NULL,
    affiliator_id bigint NOT NULL,
    protocol_reference_id bigint,
    protocol_name character varying(255) NOT NULL,
    indication character varying(100),
    protocol_version character varying(20),
    is_posted boolean DEFAULT false NOT NULL,
    is_revised boolean DEFAULT false NOT NULL,
    is_reviewed boolean DEFAULT false NOT NULL,
    is_approved boolean DEFAULT false NOT NULL,
    creator_note character varying(100),
    reviewer_note character varying(100),
    posted_date timestamp without time zone,
    created_at timestamp without time zone DEFAULT now(),
    create_by bigint,
    updated_at timestamp without time zone DEFAULT now(),
    updated_by bigint
);


ALTER TABLE public.affiliator_protocols OWNER TO rspad;

--
-- Name: affiliator_protocols_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.affiliator_protocols_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.affiliator_protocols_id_seq OWNER TO rspad;

--
-- Name: affiliator_protocols_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.affiliator_protocols_id_seq OWNED BY public.affiliator_protocols.id;


--
-- Name: affiliator_supervision_documents; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.affiliator_supervision_documents (
    id integer NOT NULL,
    supervision_id integer NOT NULL,
    document_path character varying(250) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.affiliator_supervision_documents OWNER TO rspad;

--
-- Name: affiliator_supervision_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.affiliator_supervision_documents_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.affiliator_supervision_documents_id_seq OWNER TO rspad;

--
-- Name: affiliator_supervision_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.affiliator_supervision_documents_id_seq OWNED BY public.affiliator_supervision_documents.id;


--
-- Name: affiliator_supervisions; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.affiliator_supervisions (
    id integer NOT NULL,
    reference_id character varying(150),
    affiliator_id integer NOT NULL,
    pic_name character varying(150),
    is_posted boolean DEFAULT false NOT NULL,
    is_revised boolean DEFAULT false NOT NULL,
    is_reviewed boolean DEFAULT false NOT NULL,
    is_approved boolean DEFAULT false NOT NULL,
    review_notes text,
    approved_by integer,
    approved_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    created_by integer,
    updated_by integer,
    CONSTRAINT check_approved_requires_posted CHECK (((NOT is_approved) OR is_posted)),
    CONSTRAINT check_approved_when_not_review CHECK (((NOT is_approved) OR is_reviewed)),
    CONSTRAINT check_unposted_requires_unapproved CHECK ((is_posted OR (NOT is_approved)))
);


ALTER TABLE public.affiliator_supervisions OWNER TO rspad;

--
-- Name: affiliator_supervisions_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.affiliator_supervisions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.affiliator_supervisions_id_seq OWNER TO rspad;

--
-- Name: affiliator_supervisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.affiliator_supervisions_id_seq OWNED BY public.affiliator_supervisions.id;


--
-- Name: affiliators; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.affiliators (
    id bigint NOT NULL,
    affiliator_name character varying(250) NOT NULL,
    affiliator_type character varying(100) NOT NULL,
    address character varying(100) NOT NULL,
    contact_phone character varying(50) NOT NULL,
    contact_email character varying(50) NOT NULL,
    operational_number character varying(50),
    director_name character varying(100),
    bed_number integer,
    icu_bed integer,
    isolation_bed integer,
    policlinic integer,
    supporting_facility character varying(250),
    app_verification_id bigint,
    specialist_number integer,
    generalist_number integer,
    nurse_number integer,
    other_labor_number integer,
    research_head character varying(100),
    reasearch_head_contact character varying(100),
    created_at timestamp without time zone DEFAULT now(),
    create_by bigint,
    updated_at timestamp without time zone DEFAULT now(),
    updated_by bigint,
    is_approved boolean DEFAULT false NOT NULL,
    is_reviewed boolean DEFAULT false NOT NULL,
    affiliator_code character varying(50)
);


ALTER TABLE public.affiliators OWNER TO rspad;

--
-- Name: affiliators_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.affiliators_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.affiliators_id_seq OWNER TO rspad;

--
-- Name: affiliators_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.affiliators_id_seq OWNED BY public.affiliators.id;


--
-- Name: chatbot_document_chunks; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.chatbot_document_chunks (
    id integer NOT NULL,
    document_id integer,
    page_number integer NOT NULL,
    chunk_index integer NOT NULL,
    content text NOT NULL,
    search_vector tsvector,
    embedding real[]
);


ALTER TABLE public.chatbot_document_chunks OWNER TO rspad;

--
-- Name: chatbot_document_chunks_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.chatbot_document_chunks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.chatbot_document_chunks_id_seq OWNER TO rspad;

--
-- Name: chatbot_document_chunks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.chatbot_document_chunks_id_seq OWNED BY public.chatbot_document_chunks.id;


--
-- Name: chatbot_documents; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.chatbot_documents (
    id integer NOT NULL,
    file_path character varying(512) NOT NULL,
    file_name character varying(256) NOT NULL,
    last_modified timestamp without time zone NOT NULL,
    last_parsed timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.chatbot_documents OWNER TO rspad;

--
-- Name: chatbot_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.chatbot_documents_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.chatbot_documents_id_seq OWNER TO rspad;

--
-- Name: chatbot_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.chatbot_documents_id_seq OWNED BY public.chatbot_documents.id;


--
-- Name: chatbot_training_data; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.chatbot_training_data (
    id integer NOT NULL,
    phrase text NOT NULL,
    intent character varying(100) NOT NULL
);


ALTER TABLE public.chatbot_training_data OWNER TO rspad;

--
-- Name: chatbot_training_data_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.chatbot_training_data_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.chatbot_training_data_id_seq OWNER TO rspad;

--
-- Name: chatbot_training_data_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.chatbot_training_data_id_seq OWNED BY public.chatbot_training_data.id;


--
-- Name: ecrf_sections; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.ecrf_sections (
    id integer NOT NULL,
    section_name character varying(100) NOT NULL
);


ALTER TABLE public.ecrf_sections OWNER TO rspad;

--
-- Name: patient_ecrf_responses; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.patient_ecrf_responses (
    id integer NOT NULL,
    patient_id integer NOT NULL,
    protocol_id integer NOT NULL,
    section_id integer NOT NULL,
    answers_data jsonb DEFAULT '{}'::jsonb,
    is_posted boolean DEFAULT false NOT NULL,
    is_revised boolean DEFAULT false NOT NULL,
    is_reviewed boolean DEFAULT false NOT NULL,
    is_approved boolean DEFAULT false NOT NULL,
    reviewer_note text,
    approved_by integer,
    approved_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    created_by integer,
    updated_by integer
);


ALTER TABLE public.patient_ecrf_responses OWNER TO rspad;

--
-- Name: patient_ecrf_responses_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.patient_ecrf_responses_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.patient_ecrf_responses_id_seq OWNER TO rspad;

--
-- Name: patient_ecrf_responses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.patient_ecrf_responses_id_seq OWNED BY public.patient_ecrf_responses.id;


--
-- Name: patient_ecrfs; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.patient_ecrfs (
    id integer NOT NULL,
    affiliator_id integer NOT NULL,
    protocol_id integer NOT NULL,
    registration_number character varying(100) NOT NULL,
    patient_initial character varying(50) NOT NULL,
    gender character varying(20),
    pic_doctor character varying(150),
    birth_date date,
    registration_date date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    created_by integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer
);


ALTER TABLE public.patient_ecrfs OWNER TO rspad;

--
-- Name: patient_ecrfs_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.patient_ecrfs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.patient_ecrfs_id_seq OWNER TO rspad;

--
-- Name: patient_ecrfs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.patient_ecrfs_id_seq OWNED BY public.patient_ecrfs.id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(25) NOT NULL
);


ALTER TABLE public.roles OWNER TO rspad;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.roles_id_seq OWNER TO rspad;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: rspad
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    username character varying(200) NOT NULL,
    role_id bigint NOT NULL,
    level_id integer NOT NULL,
    email character varying(255) NOT NULL,
    password_hash text NOT NULL,
    affiliator_id bigint,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    is_approved boolean DEFAULT false,
    is_reviewed boolean DEFAULT false
);


ALTER TABLE public.users OWNER TO rspad;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: rspad
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.users_id_seq OWNER TO rspad;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: rspad
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: admin_protocol_documents id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_documents ALTER COLUMN id SET DEFAULT nextval('public.admin_protocol_documents_id_seq'::regclass);


--
-- Name: admin_protocol_ecrfs id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_ecrfs ALTER COLUMN id SET DEFAULT nextval('public.admin_protocol_ecrfs_id_seq'::regclass);


--
-- Name: admin_protocols id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocols ALTER COLUMN id SET DEFAULT nextval('public.admin_protocols_id_seq'::regclass);


--
-- Name: adverse_events id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.adverse_events ALTER COLUMN id SET DEFAULT nextval('public.adverse_events_id_seq'::regclass);


--
-- Name: affiliator_profile_documents id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_profile_documents ALTER COLUMN id SET DEFAULT nextval('public.affiliator_profile_documents_id_seq'::regclass);


--
-- Name: affiliator_protocol_documents id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_protocol_documents ALTER COLUMN id SET DEFAULT nextval('public.affiliator_protocol_documents_id_seq'::regclass);


--
-- Name: affiliator_protocols id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_protocols ALTER COLUMN id SET DEFAULT nextval('public.affiliator_protocols_id_seq'::regclass);


--
-- Name: affiliator_supervision_documents id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervision_documents ALTER COLUMN id SET DEFAULT nextval('public.affiliator_supervision_documents_id_seq'::regclass);


--
-- Name: affiliator_supervisions id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervisions ALTER COLUMN id SET DEFAULT nextval('public.affiliator_supervisions_id_seq'::regclass);


--
-- Name: affiliators id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliators ALTER COLUMN id SET DEFAULT nextval('public.affiliators_id_seq'::regclass);


--
-- Name: chatbot_document_chunks id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_document_chunks ALTER COLUMN id SET DEFAULT nextval('public.chatbot_document_chunks_id_seq'::regclass);


--
-- Name: chatbot_documents id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_documents ALTER COLUMN id SET DEFAULT nextval('public.chatbot_documents_id_seq'::regclass);


--
-- Name: chatbot_training_data id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_training_data ALTER COLUMN id SET DEFAULT nextval('public.chatbot_training_data_id_seq'::regclass);


--
-- Name: patient_ecrf_responses id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses ALTER COLUMN id SET DEFAULT nextval('public.patient_ecrf_responses_id_seq'::regclass);


--
-- Name: patient_ecrfs id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrfs ALTER COLUMN id SET DEFAULT nextval('public.patient_ecrfs_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: admin_protocol_documents admin_protocol_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_documents
    ADD CONSTRAINT admin_protocol_documents_pkey PRIMARY KEY (id);


--
-- Name: admin_protocol_ecrfs admin_protocol_ecrfs_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_ecrfs
    ADD CONSTRAINT admin_protocol_ecrfs_pkey PRIMARY KEY (id);


--
-- Name: admin_protocols admin_protocols_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocols
    ADD CONSTRAINT admin_protocols_pkey PRIMARY KEY (id);


--
-- Name: adverse_events adverse_events_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.adverse_events
    ADD CONSTRAINT adverse_events_pkey PRIMARY KEY (id);


--
-- Name: adverse_events adverse_events_report_number_key; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.adverse_events
    ADD CONSTRAINT adverse_events_report_number_key UNIQUE (report_number);


--
-- Name: affiliator_profile_documents affiliator_profile_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_profile_documents
    ADD CONSTRAINT affiliator_profile_documents_pkey PRIMARY KEY (id);


--
-- Name: affiliator_protocol_documents affiliator_protocol_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_protocol_documents
    ADD CONSTRAINT affiliator_protocol_documents_pkey PRIMARY KEY (id);


--
-- Name: affiliator_protocols affiliator_protocols_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_protocols
    ADD CONSTRAINT affiliator_protocols_pkey PRIMARY KEY (id);


--
-- Name: affiliator_supervision_documents affiliator_supervision_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervision_documents
    ADD CONSTRAINT affiliator_supervision_documents_pkey PRIMARY KEY (id);


--
-- Name: affiliator_supervisions affiliator_supervisions_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervisions
    ADD CONSTRAINT affiliator_supervisions_pkey PRIMARY KEY (id);


--
-- Name: affiliators affiliators_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliators
    ADD CONSTRAINT affiliators_pkey PRIMARY KEY (id);


--
-- Name: chatbot_document_chunks chatbot_document_chunks_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_document_chunks
    ADD CONSTRAINT chatbot_document_chunks_pkey PRIMARY KEY (id);


--
-- Name: chatbot_documents chatbot_documents_file_path_key; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_documents
    ADD CONSTRAINT chatbot_documents_file_path_key UNIQUE (file_path);


--
-- Name: chatbot_documents chatbot_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_documents
    ADD CONSTRAINT chatbot_documents_pkey PRIMARY KEY (id);


--
-- Name: chatbot_training_data chatbot_training_data_phrase_key; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_training_data
    ADD CONSTRAINT chatbot_training_data_phrase_key UNIQUE (phrase);


--
-- Name: chatbot_training_data chatbot_training_data_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_training_data
    ADD CONSTRAINT chatbot_training_data_pkey PRIMARY KEY (id);


--
-- Name: ecrf_sections ecrf_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.ecrf_sections
    ADD CONSTRAINT ecrf_sections_pkey PRIMARY KEY (id);


--
-- Name: ecrf_sections ecrf_sections_section_name_key; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.ecrf_sections
    ADD CONSTRAINT ecrf_sections_section_name_key UNIQUE (section_name);


--
-- Name: patient_ecrf_responses patient_ecrf_responses_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses
    ADD CONSTRAINT patient_ecrf_responses_pkey PRIMARY KEY (id);


--
-- Name: patient_ecrfs patient_ecrfs_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrfs
    ADD CONSTRAINT patient_ecrfs_pkey PRIMARY KEY (id);


--
-- Name: patient_ecrfs patient_ecrfs_registration_number_key; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrfs
    ADD CONSTRAINT patient_ecrfs_registration_number_key UNIQUE (registration_number);


--
-- Name: roles roles_name_key; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_key UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: affiliator_supervisions unique_affiliator_supervision; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervisions
    ADD CONSTRAINT unique_affiliator_supervision UNIQUE (affiliator_id);


--
-- Name: patient_ecrf_responses unique_patient_section; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses
    ADD CONSTRAINT unique_patient_section UNIQUE (patient_id, protocol_id, section_id);


--
-- Name: admin_protocol_ecrfs unique_protocol_section; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_ecrfs
    ADD CONSTRAINT unique_protocol_section UNIQUE (protocol_id, section_id);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: chatbot_document_chunks_search_vector_idx; Type: INDEX; Schema: public; Owner: rspad
--

CREATE INDEX chatbot_document_chunks_search_vector_idx ON public.chatbot_document_chunks USING gin (search_vector);


--
-- Name: affiliators trg_generate_affiliator_code; Type: TRIGGER; Schema: public; Owner: rspad
--

CREATE TRIGGER trg_generate_affiliator_code BEFORE INSERT ON public.affiliators FOR EACH ROW EXECUTE FUNCTION public.generate_affiliator_code();


--
-- Name: admin_protocol_ecrfs admin_protocol_ecrfs_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_ecrfs
    ADD CONSTRAINT admin_protocol_ecrfs_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: admin_protocol_ecrfs admin_protocol_ecrfs_protocol_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_ecrfs
    ADD CONSTRAINT admin_protocol_ecrfs_protocol_id_fkey FOREIGN KEY (protocol_id) REFERENCES public.admin_protocols(id) ON DELETE CASCADE;


--
-- Name: admin_protocol_ecrfs admin_protocol_ecrfs_section_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_ecrfs
    ADD CONSTRAINT admin_protocol_ecrfs_section_id_fkey FOREIGN KEY (section_id) REFERENCES public.ecrf_sections(id);


--
-- Name: admin_protocol_ecrfs admin_protocol_ecrfs_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.admin_protocol_ecrfs
    ADD CONSTRAINT admin_protocol_ecrfs_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: adverse_events adverse_events_affiliator_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.adverse_events
    ADD CONSTRAINT adverse_events_affiliator_id_fkey FOREIGN KEY (affiliator_id) REFERENCES public.affiliators(id) ON DELETE CASCADE;


--
-- Name: adverse_events adverse_events_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.adverse_events
    ADD CONSTRAINT adverse_events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: adverse_events adverse_events_patient_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.adverse_events
    ADD CONSTRAINT adverse_events_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES public.patient_ecrfs(id) ON DELETE CASCADE;


--
-- Name: adverse_events adverse_events_protocol_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.adverse_events
    ADD CONSTRAINT adverse_events_protocol_id_fkey FOREIGN KEY (protocol_id) REFERENCES public.affiliator_protocols(id) ON DELETE CASCADE;


--
-- Name: adverse_events adverse_events_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.adverse_events
    ADD CONSTRAINT adverse_events_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: affiliator_profile_documents affiliator_profile_documents_affiliator_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_profile_documents
    ADD CONSTRAINT affiliator_profile_documents_affiliator_id_fkey FOREIGN KEY (affiliator_id) REFERENCES public.affiliators(id) ON DELETE CASCADE;


--
-- Name: affiliator_protocols affiliator_protocols_protocol_reference_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_protocols
    ADD CONSTRAINT affiliator_protocols_protocol_reference_id_fkey FOREIGN KEY (protocol_reference_id) REFERENCES public.admin_protocols(id) ON DELETE SET NULL;


--
-- Name: affiliator_supervision_documents affiliator_supervision_documents_supervision_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervision_documents
    ADD CONSTRAINT affiliator_supervision_documents_supervision_id_fkey FOREIGN KEY (supervision_id) REFERENCES public.affiliator_supervisions(id) ON DELETE CASCADE;


--
-- Name: affiliator_supervisions affiliator_supervisions_affiliator_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervisions
    ADD CONSTRAINT affiliator_supervisions_affiliator_id_fkey FOREIGN KEY (affiliator_id) REFERENCES public.affiliators(id) ON DELETE CASCADE;


--
-- Name: affiliator_supervisions affiliator_supervisions_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervisions
    ADD CONSTRAINT affiliator_supervisions_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: affiliator_supervisions affiliator_supervisions_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervisions
    ADD CONSTRAINT affiliator_supervisions_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: affiliator_supervisions affiliator_supervisions_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.affiliator_supervisions
    ADD CONSTRAINT affiliator_supervisions_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: chatbot_document_chunks chatbot_document_chunks_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.chatbot_document_chunks
    ADD CONSTRAINT chatbot_document_chunks_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.chatbot_documents(id) ON DELETE CASCADE;


--
-- Name: patient_ecrf_responses patient_ecrf_responses_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses
    ADD CONSTRAINT patient_ecrf_responses_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: patient_ecrf_responses patient_ecrf_responses_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses
    ADD CONSTRAINT patient_ecrf_responses_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: patient_ecrf_responses patient_ecrf_responses_patient_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses
    ADD CONSTRAINT patient_ecrf_responses_patient_id_fkey FOREIGN KEY (patient_id) REFERENCES public.patient_ecrfs(id) ON DELETE CASCADE;


--
-- Name: patient_ecrf_responses patient_ecrf_responses_protocol_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses
    ADD CONSTRAINT patient_ecrf_responses_protocol_id_fkey FOREIGN KEY (protocol_id) REFERENCES public.affiliator_protocols(id) ON DELETE CASCADE;


--
-- Name: patient_ecrf_responses patient_ecrf_responses_section_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses
    ADD CONSTRAINT patient_ecrf_responses_section_id_fkey FOREIGN KEY (section_id) REFERENCES public.ecrf_sections(id) ON DELETE CASCADE;


--
-- Name: patient_ecrf_responses patient_ecrf_responses_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrf_responses
    ADD CONSTRAINT patient_ecrf_responses_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: patient_ecrfs patient_ecrfs_affiliator_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrfs
    ADD CONSTRAINT patient_ecrfs_affiliator_id_fkey FOREIGN KEY (affiliator_id) REFERENCES public.affiliators(id) ON DELETE CASCADE;


--
-- Name: patient_ecrfs patient_ecrfs_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrfs
    ADD CONSTRAINT patient_ecrfs_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: patient_ecrfs patient_ecrfs_protocol_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrfs
    ADD CONSTRAINT patient_ecrfs_protocol_id_fkey FOREIGN KEY (protocol_id) REFERENCES public.affiliator_protocols(id) ON DELETE CASCADE;


--
-- Name: patient_ecrfs patient_ecrfs_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.patient_ecrfs
    ADD CONSTRAINT patient_ecrfs_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: users users_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: rspad
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict UVO8Nrl8EipSrw390hNSR9xkHQAHsuGn1U6Zyijui2gPuW2DDaNWDdUA4pIRRih

