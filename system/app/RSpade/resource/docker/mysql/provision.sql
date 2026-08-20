-- Database provisioning - DEVELOPMENT CONTAINER, first boot only.
--
-- Runs once, against a freshly initialised data directory, before the
-- application has ever connected. Production does not use this file: you
-- provide the server, and you create the database and user yourself (the
-- statements below are a reasonable template for doing so).
--
-- ABOUT THESE CREDENTIALS. rspade/rspadepass is a container-local default, and
-- it is fine as one: MySQL here binds to 127.0.0.1 inside the container and its
-- port is not published, so nothing outside can reach it. It must match
-- DB_USERNAME / DB_PASSWORD in .env.dist, which is why it is a fixed value
-- rather than a generated one.
--
-- This is NOT the application login. That account is created by a framework
-- migration from RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD, which ship
-- blank and which the migration refuses to invent. See .env.README.

CREATE DATABASE IF NOT EXISTS rspade
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS rspade_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'rspade'@'localhost' IDENTIFIED BY 'rspadepass';
ALTER USER 'rspade'@'localhost'
    IDENTIFIED WITH mysql_native_password BY 'rspadepass';

GRANT ALL PRIVILEGES ON rspade.*      TO 'rspade'@'localhost';
GRANT ALL PRIVILEGES ON rspade_test.* TO 'rspade'@'localhost';

-- Server-wide rights, deliberately, and only in the development container:
-- `rsx:test --fresh` DROPs and re-CREATEs the test database, which is a
-- server-level operation that a database-scoped grant cannot authorise.
-- A production user should NOT have this - grant it only on its own database.
GRANT ALL PRIVILEGES ON *.* TO 'rspade'@'localhost' WITH GRANT OPTION;

FLUSH PRIVILEGES;
