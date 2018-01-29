/*
 * Script run when a file is uploaded
 * Table name is automattically filled
 */

CREATE TABLE "%s" (
  "id" varchar NOT NULL,
  "streetname" varchar NOT NULL,
  "housenumber" varchar NOT NULL,
  "postalcode" int NOT NULL,
  "locality" varchar NOT NULL,
  "valid" boolean NOT NULL DEFAULT true,
  "process_datetime" timestamp with time zone,
  "process_count" int,
  "process_provider" varchar,
  "process_address" varchar,
  "the_geog" geography
);
