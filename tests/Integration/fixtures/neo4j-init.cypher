CREATE CONSTRAINT person_name_unique IF NOT EXISTS FOR (p:Person) REQUIRE p.name IS UNIQUE;
CREATE (:Person {name: 'Alice', age: 30});
CREATE (:Person {name: 'Bob', age: 25});
CREATE (:Movie {title: 'The Matrix', year: 1999});
MATCH (a:Person {name: 'Alice'}), (m:Movie {title: 'The Matrix'})
CREATE (a)-[:WATCHED]->(m);
