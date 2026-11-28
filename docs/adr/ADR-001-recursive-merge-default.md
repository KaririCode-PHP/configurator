# ADR-001: Recursive Merge as Default Strategy

## Status
Accepted

## Context
Configuration systems need a deterministic merge policy when loading
multiple files. Two dominant strategies exist: recursive deep-merge
(descend into nested arrays) and flat replace (top-level keys overwrite).

Environment-based overlays are the primary use case:
  base/database.php       → {driver: pgsql, host: localhost, port: 5432}
  prod/database.php       → {host: db.prod.example.com}
  expected result         → {driver: pgsql, host: db.prod.example.com, port: 5432}

Flat replace would lose `driver` and `port` from the base.

## Decision
RecursiveMerge is the default strategy. Sequential (integer-keyed)
arrays are replaced entirely to prevent duplication of list items
across overlays. Associative arrays are merged key-by-key recursively.

ReplaceMerge is available for cases requiring full-key replacement.

## Consequences
- Environment overlays require only the changed keys, reducing duplication.
- Sequential arrays like `middleware` lists are fully replaced — consumers
  must include the complete list in the overlay if they need to modify it.
- The merge strategy is injectable via constructor, so custom strategies
  can be implemented without modifying the core.
