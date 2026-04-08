# SM Workers

`sm_workers` provides shared worker-runtime building blocks for Drupal modules
using Symfony Messenger without assuming a specific process manager.

It does not start workers itself. Instead, it gives modules a shared place to
describe workers, contribute execution strategies and circuit-breaker primers,
contribute worker-linked operational commands, centralize strategy dispatch,
and gives operators canonical `drush sm:consume ...` commands plus shared
runtime tooling that can be used under `systemd`, `supervisor`, `s6`,
Kubernetes, or by hand.

## What it owns

- worker definitions
- worker-linked operational command metadata
- generic execution strategy contracts and strategy dispatch
- shared circuit breaker service and admin UI
- command rendering for `sm:consume`
- shared SQL-transport queue-depth reporting
- Drush commands for listing worker definitions, consume commands, and
  worker-linked operational commands
- memoized tagged-provider resolution so lazy iterators are safe to reuse

## What it does not own

- process supervision
- container runtime integration
- domain-specific transport choices, worker-linked operations, and breaker priming data
- shell execution of rendered commands

Worker definitions are intentionally code-defined, not config-defined. They
reference Drush/Messenger runtime behavior and are meant to stay versioned with
the module code that owns the transports and handlers.

Use `sm_workers` when a site manages multiple workers or when one worker needs
additional operator commands beyond plain `sm:consume`, such as one-shot drain
commands, replay commands, or transport-specific maintenance flows.

Shared operational defaults live in `sm_workers.settings`. Today that includes:

- circuit-breaker failure threshold, cooldown, and open-breaker intake pause
- default execution timeout
- default auth-forwarding behavior for strategy-managed worker execution

Operators can edit those values from:

- `/admin/config/services/sm-workers/settings`
- `/admin/config/services/sm-workers/circuit-breakers`

Useful commands:

```bash
drush sm-workers:list
drush sm-workers:command islandora_events.derivatives
drush sm-workers:operations islandora_events.derivatives
```

Use `sm-workers:list` as the operator entry point when you need the canonical
consume command for a worker contributed by another module.

Consumer modules contribute worker definitions, worker operations, circuit
breaker primers, and execution strategies through tagged services.

When a consumer wants strategy-managed execution, it should inject
`sm_workers.execution_manager` rather than implementing its own strategy
selection loop. When a consumer wants generic queue-depth reporting for
`drupal-sql` Messenger transports, it should inject `sm_workers.queue_depths`
rather than querying queues itself.

## Architecture Decisions

See [`adr/`](./adr/) for the module-owned decisions behind `sm_workers`:
