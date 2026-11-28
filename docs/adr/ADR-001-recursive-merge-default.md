# ADR-001: Recursive Merge as Default Strategy

## Status

Accepted — V4.2.0 (decided in the 4.0 line)

## Date

2026-11-28 — recorded in this layout; the 4.x tarballs did not date the
decision.

## Context

Configuration systems need a deterministic merge policy when loading
multiple files. Two strategies dominate: recursive deep merge (descend
into nested arrays) and flat replace (top-level keys overwrite).

Environment overlays are the primary use case:

```
base/database.php   → {driver: pgsql, host: localhost, port: 5432}
prod/database.php   → {host: db.prod.example.com}
expected result     → {driver: pgsql, host: db.prod.example.com, port: 5432}
```

Flat replace would lose `driver` and `port` from the base.

### Prior Art

- Symfony Config and Laravel merge configuration trees recursively;
  `array_replace_recursive()` is PHP's own form of the policy.
- Spring Boot profiles overlay property sources key by key.

## Decision

RecursiveMerge is the default strategy. Associative arrays merge key by
key, recursively; sequential (integer-keyed) arrays are replaced whole,
so list items are not duplicated across overlays. ReplaceMerge is
available for sources that must replace whole top-level keys, and the
strategy is injectable (`MergeStrategy`).

## Consequences

### Positive

- Environment overlays hold only the changed keys.
- Custom strategies plug in without changes to the core.

### Negative

- A list such as `middleware` is replaced whole: an overlay that changes
  one entry repeats the complete list.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Flat replace as the default | every overlay would repeat its whole section |
| Append lists (`array_merge_recursive`) | overlays would duplicate list items and turn scalars into lists |
| Merge lists by index | an overlay with a shorter list would keep stale entries from the base |

## References

- ADR-003, ADR-011 (`MergeException`, CFG_2860, for custom strategies).
- SPEC-CONFIGURATOR §5.
