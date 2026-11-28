# ADR-003: Seal Pattern for Post-Load Immutability

## Status

Accepted — V4.2.0 (decided in the 4.0 line)

## Date

2026-11-28 — recorded in this layout; the 4.x tarballs did not date the
decision.

## Context

ARFA 1.3 P1 asks for immutable state. Configuration loading, however, is
a multi-step process: files load one after another, environment overlays
apply, programmatic overrides merge, and validation runs last.

Making Configuration readonly from construction would require all data
at construction time, so a builder would assemble the complete array
outside the class, and the loader registry would be out of reach.

### Prior Art

- The freeze idiom (`Object.freeze`, Ruby's `freeze`): mutable while
  built, immutable once published.
- Symfony's compiled container: configurable until compiled, read-only
  afterwards.

## Decision

A two-phase lifecycle: a mutable loading phase, then an explicit seal()
that runs the value processors and the validator and freezes the state.
After seal(), load() and merge() throw AccessException (CFG_2801,
ADR-011). ConfigurationBuilder calls seal() at the end of build(), so the
usual path never exposes the mutable phase.

## Consequences

### Positive

- After seal(), Configuration is immutable (ARFA 1.3 P1) and safe to
  share between requests of a long-running worker.
- The mutable window is explicit, and validation fails at seal time,
  before the configuration is used.

### Negative

- Code that builds a Configuration by hand must remember seal(); until
  then reads see an unprocessed, unvalidated tree.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Readonly from construction | the whole tree and every loader would have to exist before the object |
| Copy-on-write (`withFile()` returning a new instance) | every file would copy the tree, and processors and validation would have no single point to run |
| No enforcement, by convention | a late `merge()` in a worker would change the configuration under requests already running |

## References

- ARFA 1.3 P1; KaririCode Specification V4.3.3 §5.
- ADR-005, ADR-006, ADR-011.
