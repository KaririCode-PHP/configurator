# ADR-003: Seal Pattern for Post-Load Immutability

## Status
Accepted

## Context
ARFA 1.3 P1 requires Immutable State Transformation. However,
configuration loading is inherently a multi-step mutable process:
files are loaded sequentially, environment overlays are applied,
programmatic overrides are merged, and validation runs last.

Making Configuration readonly from construction would require all
data to be available at construction time, forcing a builder to
assemble the complete data array externally — violating encapsulation
and making the loader registry inaccessible.

## Decision
Adopt a two-phase lifecycle: mutable loading phase followed by
an explicit seal() call that freezes the state. After seal(),
any call to load() or merge() throws ConfigurationException.

The ConfigurationBuilder calls seal() automatically at the end
of build(), so the typical usage never exposes the mutable phase.

## Consequences
- ARFA 1.3 P1 compliance: after seal(), Configuration is immutable.
- The mutable window is explicit and auditable.
- Validators run at seal time, catching errors before the
  configuration is used.
- The builder provides the recommended construction path;
  manual construction is available but requires explicit seal().
