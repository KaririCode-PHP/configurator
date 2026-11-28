# ADR-012: Hot Reload Keeps the Last Good Configuration

## Status

Accepted — V4.2.0

## Date

2026-11-28

## Context

ReloadableConfiguration (ADR-006) checks the watched files on every read,
at most once per interval, and rebuilds the configuration when one
changed. In the 4.2 tarball a rebuild that failed — a typo in a YAML file
during a deploy, a PHP file that does not compile — threw out of the
read that triggered it, kept the old configuration, and did not record
the new file times. The next check, one interval later, rebuilt the same
broken files and failed again: every worker failed one read per interval
until someone fixed the file, and nothing reported why except the reads
that failed. The class had no tests.

### Prior Art

- Kubernetes ConfigMap reload, Envoy and nginx: a new configuration that
  does not validate is rejected and the running one stays.
- Spring Cloud Config's refresh scope publishes an event when a refresh
  happens.
- ADR-006 (the decorator) and ADR-003 (sealed configurations).

## Decision

1. A rebuild runs completely before the swap, as before; when it fails,
   the configuration that serves reads stays the last one that built.
2. The file times are recorded whether the rebuild succeeds or fails, so
   the same broken files are not rebuilt again until they change.
3. A failed rebuild dispatches the new `Event\ConfigReloadFailed`, with
   the changed files and the exception, next to `ConfigReloaded` for a
   successful one.
4. A read never fails because of a reload: it serves the last good
   configuration. `checkAndReload()` and `forceReload()`, which the
   application calls on purpose, rethrow what the builder threw.

## Consequences

### Positive

- A broken file costs no failed request; the workers keep the
  configuration they validated.
- The failure is observable once, through the event or an explicit check,
  instead of once per interval through unrelated reads.

### Negative

- Without a dispatcher and without explicit checks, a failed reload is
  silent: the application keeps the old configuration and nothing says
  so. The class docs tell users to configure one of the two.
- A worker may run with an older configuration than its files describe
  until the files are fixed.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep throwing from the read | a request fails for a file it may not even read, once per interval per worker |
| Switch to an empty or partial configuration | it would serve values that never passed validation; failing closed means keeping the validated one |
| Retry the broken files on every check | the result cannot change until the files do; a retry only repeats the cost and the report |
| Report through ConfigLoadError | that event names one file the bridge failed to load; a rebuild reads every file and the changed ones may not be the broken one |

## References

- ADR-003, ADR-006.
- KaririCode Specification V4.3.3 §8.3 (fail closed).
- PSR-14.
