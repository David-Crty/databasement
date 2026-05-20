CREATE TABLE test_table (
    id INTEGER NOT NULL PRIMARY KEY,
    name VARCHAR(50),
    amount INTEGER
);

INSERT INTO test_table (id, name, amount) VALUES (1, 'item1', 100);
INSERT INTO test_table (id, name, amount) VALUES (2, 'item2', 200);
INSERT INTO test_table (id, name, amount) VALUES (3, 'item3', 300);
