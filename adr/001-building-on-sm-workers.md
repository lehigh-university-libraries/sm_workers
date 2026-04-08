# ADR 001: Design `sm_workers` so other modules can build on top of it

## Status

Accepted

## Context

If `sm_workers` is going to be a standalone codebase, it needs to offer more
than a handful of private helper services. Other modules need a stable way to
contribute workers and runtime behavior without copying the orchestration
pattern into each codebase.

The value of `sm_workers` is that modules can share one runtime model for:

- worker definitions and consume commands
- tagged execution strategies
- circuit-breaker priming and operator control
- worker-linked operational commands
- transport queue-depth reporting

## Decision

`sm_workers` will stay generic and expose stable APIs and extension surfaces
for other modules to build on.

In practice that means:

- worker definitions stay code-defined and module-owned
- runtime orchestration happens through shared registries and services rather
  than ad hoc module-specific wiring
- execution mode dispatch happens through tagged strategy services and the
  shared execution manager
- circuit-breaker state is managed in the shared runtime layer
- consumer modules contribute their own worker operations, breaker primers, and
  strategy implementations through tagged services

## Reasoning

### Reuse only matters if the contracts are explicit

If every consumer module needs its own worker registry, its own strategy loop,
and its own command renderer, `sm_workers` is not actually a reusable module.
The service boundary is part of the product.

### Shared runtime semantics reduce duplicated operational code

Modules that adopt `sm_workers` get a consistent operator model for worker
commands, queue-depth inspection, and breaker-aware runtime behavior instead of
rebuilding those pieces independently.

### Domain logic should stay in consumer modules

`sm_workers` should own the runtime shape, not the business meaning of a given
worker. Consumer modules still decide what work exists, how it is named, and
which execution modes or operations they expose.

## Consequences

- the module should be documented as a reusable worker-runtime foundation, not
  a private Islandora helper
- consumer modules should prefer the shared registries and execution services
  over custom strategy-selection loops
- new worker behavior should be added through tagged providers and strategies
  rather than by bypassing the shared runtime model
