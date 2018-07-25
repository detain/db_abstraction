--
-- PostgreSQL database dump
--

-- Dumped from database version 10.4 (Ubuntu 10.4-0ubuntu0.18.04)
-- Dumped by pg_dump version 10.4 (Ubuntu 10.4-0ubuntu0.18.04)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: plpgsql; Type: EXTENSION; Schema: -; Owner: 
--

CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;


--
-- Name: EXTENSION plpgsql; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION plpgsql IS 'PL/pgSQL procedural language';


SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: service_types; Type: TABLE; Schema: public; Owner: my
--

CREATE TABLE public.service_types (
    st_id bigint NOT NULL,
    st_name character varying(50) NOT NULL,
    st_category bigint NOT NULL,
    st_module character varying(30) NOT NULL
);


ALTER TABLE public.service_types OWNER TO my;

--
-- Name: COLUMN service_types.st_id; Type: COMMENT; Schema: public; Owner: my
--

COMMENT ON COLUMN public.service_types.st_id IS 'The Service Type ID';


--
-- Name: COLUMN service_types.st_name; Type: COMMENT; Schema: public; Owner: my
--

COMMENT ON COLUMN public.service_types.st_name IS 'Service Type Name';


--
-- Name: COLUMN service_types.st_module; Type: COMMENT; Schema: public; Owner: my
--

COMMENT ON COLUMN public.service_types.st_module IS 'The Module this service type is for';


--
-- Name: service_types_st_id_seq; Type: SEQUENCE; Schema: public; Owner: my
--

CREATE SEQUENCE public.service_types_st_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.service_types_st_id_seq OWNER TO my;

--
-- Name: service_types_st_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: my
--

ALTER SEQUENCE public.service_types_st_id_seq OWNED BY public.service_types.st_id;


--
-- Name: service_types st_id; Type: DEFAULT; Schema: public; Owner: my
--

ALTER TABLE ONLY public.service_types ALTER COLUMN st_id SET DEFAULT nextval('public.service_types_st_id_seq'::regclass);


--
-- Data for Name: service_types; Type: TABLE DATA; Schema: public; Owner: my
--

COPY public.service_types (st_id, st_name, st_category, st_module) FROM stdin;
1	KVM Windows	2	vps
2	KVM Linux	2	vps
3	Cloud KVM Windows	3	vps
4	Cloud KVM Linux	3	vps
5	SSD OpenVZ	1	vps
6	OpenVZ	1	vps
7	Xen Windows	3	vps
8	Xen Linux	3	vps
9	LXC	4	vps
10	VMware	5	vps
11	Hyper-V	6	vps
12	Virtuozzo 7	7	vps
13	SSD Virtuozzo 7	7	vps
100	OpenSRS	100	domains
200	cPanel/WHM	200	webhosting
201	VestaCP	201	webhosting
202	Parallels Plesk	202	webhosting
203	Parallels Plesk Automation	203	webhosting
204	WordPress Managed cPanel	200	webhosting
205	7-Day cPanel Demo Server	200	webhosting
300	GlobalSign SSL	300	ssl
400	Raid Backups	400	backups
401	SWIFT Storage Backup	401	backups
402	Gluster Storage Backup	402	backups
403	DRBL Storage Backup	403	backups
404	Raid Storage Backup	404	backups
500	CPanel	500	licenses
501	Fantastico	501	licenses
502	LiteSpeed	502	licenses
503	Softaculous	503	licenses
504	WHMSonic	504	licenses
505	KSplice	505	licenses
506	DirectAdmin	506	licenses
507	Parallells	507	licenses
508	CloudLinux	508	licenses
509	Webuzo	509	licenses
600	Dedicated Server	600	servers
\.


--
-- Name: service_types_st_id_seq; Type: SEQUENCE SET; Schema: public; Owner: my
--

SELECT pg_catalog.setval('public.service_types_st_id_seq', 600, true);


--
-- Name: service_types service_types_pkey; Type: CONSTRAINT; Schema: public; Owner: my
--

ALTER TABLE ONLY public.service_types
    ADD CONSTRAINT service_types_pkey PRIMARY KEY (st_id);


--
-- Name: public_service_types_st_category1_idx; Type: INDEX; Schema: public; Owner: my
--

CREATE INDEX public_service_types_st_category1_idx ON public.service_types USING btree (st_category);


--
-- PostgreSQL database dump complete
--

