# ADR-006: Hot Reload Decorator for Long-Running Processes

## Status

Accepted — V4.2.0 (decided in the 4.1 line; amended by ADR-012)

## Date

2026-11-28 — recorded in this layout; the 4.x tarballs did not date the
decision.

## Context

KaririCode targets long-running PHP processes (Swoole, RoadRunner,
ReactPHP, FrankenPHP workers). A process that serves many requests needs
configuration updates without a restart.

### Prior Art

- Spring Cloud Config's `@RefreshScope`.
- Kubernetes ConfigMaps mounted as volumes, updated in place.

## Decision

`ReloadableConfiguration` decorates the configuration (GoF Decorator)
with file monitoring:

1. filemtime() polling rather than inotify: portable across Linux,
   macOS and containers, where inotify does not see changes on mounted
   volumes.
2. A configurable interval (default 5 s); event loops may call
   checkAndReload() on each tick.
3. An atomic rebuild: the new configuration is built completely before
   the reference is swapped, so readers never see a partial state.
4. A `ConfigReloaded` event for cache invalidation, connection refresh
   and observability.

The decorator implements the Configurator contract, so it replaces
Configuration in a container without changes to its readers.

## Consequences

### Positive

- Configuration changes reach running workers without downtime.
- Listeners react to changes without coupling to the decorator.

### Negative

- Each read costs a clock check, and a file stat once per interval.
- The builder is reused for every rebuild, so it must keep producing a
  sealed configuration.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| inotify or a filesystem watcher extension | not portable; misses changes on container volumes |
| Restart the workers on change | drops in-flight requests and needs a supervisor |
| Reload on a signal | needs process control the library does not own |

## Amendment (ADR-012)

A rebuild that fails keeps the last good configuration, records the file
times and dispatches `ConfigReloadFailed`; reads never fail because of a
reload, while checkAndReload() and forceReload() rethrow.

## References

- ADR-003, ADR-012; PSR-14.
