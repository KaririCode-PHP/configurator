# ADR-006: Hot Reload Decorator for Long-Running Processes

## Status
Accepted

## Context
KaririCode targets long-running PHP processes via Swoole, RoadRunner,
and ReactPHP. Traditional PHP applications restart per-request, but
long-running processes require configuration updates without restart.

Spring Cloud Config provides `@RefreshScope` for bean-level reload.
Kubernetes ConfigMap supports volume-mounted config with auto-reload.
Both require process-specific integration that this library provides.

## Decision
Implement `ReloadableConfiguration` as a decorator (GoF Decorator
pattern) that wraps a sealed Configuration with file monitoring.

Design choices:
1. **filemtime() polling** rather than inotify — portable across Linux,
   macOS, and containers. inotify doesn't work in Docker volumes
2. **Configurable interval** (default 5s) — balances responsiveness
   with filesystem overhead. Event loops can call checkAndReload() on tick
3. **Atomic rebuild** — a new Configuration is built completely before
   swapping the reference, ensuring readers never see partial state
4. **ConfigReloaded event** — enables cache invalidation, connection
   pool refresh, and observability without coupling

The decorator implements the Configurator contract, making it a
transparent drop-in replacement for Configuration in DI containers.

## Consequences
- Zero-downtime config updates for long-running processes
- Event listeners can react to changes (invalidate caches, reconnect)
- Small overhead per access (microtime check + interval comparison)
- File monitoring is opt-in — standard Configuration remains unchanged
- Builder is reused for rebuilds, ensuring consistent processing pipeline
