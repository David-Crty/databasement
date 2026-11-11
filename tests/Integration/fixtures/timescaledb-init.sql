-- TimescaleDB test data for integration tests.
-- The extension has to exist before a hypertable can be created.
CREATE EXTENSION IF NOT EXISTS timescaledb;

-- A hypertable, the whole point of TimescaleDB: it must survive a backup and
-- restore as a hypertable, not just as a plain table with the same rows.
CREATE TABLE IF NOT EXISTS sensor_data (
    time        TIMESTAMPTZ NOT NULL,
    sensor_id   INTEGER NOT NULL,
    temperature DOUBLE PRECISION NOT NULL
);

SELECT create_hypertable('sensor_data', 'time', if_not_exists => TRUE);

INSERT INTO sensor_data (time, sensor_id, temperature) VALUES
    (NOW() - INTERVAL '2 hours', 1, 20.5),
    (NOW() - INTERVAL '1 hour', 1, 21.0),
    (NOW(), 2, 19.8);

-- A plain table alongside it, to prove ordinary relations restore too.
CREATE TABLE IF NOT EXISTS sensors (
    id   SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

INSERT INTO sensors (name) VALUES ('Sensor A'), ('Sensor B');
