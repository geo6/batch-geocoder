/*
 * Script run when a file is uploaded
 * Table name is automattically filled
 */

CREATE TABLE "%s" (
  "id" varchar NOT NULL,
  "streetname" varchar NOT NULL,
  "housenumber" varchar NOT NULL,
  "postalcode" varchar NOT NULL,
  "locality" varchar NOT NULL,
  "valid" boolean NOT NULL DEFAULT true,
  "validation" hstore,
  "process_datetime" timestamp with time zone,
  "process_status" int,
  "process_provider" varchar,
  "process_address" varchar,
  "process_score" int,
  "the_geog" geography
);
