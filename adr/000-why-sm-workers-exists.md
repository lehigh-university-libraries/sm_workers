# ADR 000: Create `sm_workers` as a shared worker-runtime layer for Drupal Messenger applications

## Status

Accepted

## Context

Once Drupal owns Messenger delivery, consumer modules still need a consistent
way to describe workers and operate them.

Without a shared runtime layer, each module ends up reinventing the same
infrastructure:

- worker-definition metadata
- consume command rendering
- strategy selection for downstream execution
- circuit-breaker state and operator controls
- worker-linked operational commands

That duplication makes worker operations inconsistent across modules and pushes
runtime concerns back into domain code.

## Decision

`sm_workers` exists to provide those shared worker-runtime building blocks as a
reusable Drupal module.

It is not a process supervisor, not a transport implementation, and not an
Islandora-specific worker bundle. It provides the shared contracts and services
consumer modules use to define workers, resolve operations, coordinate explicit
execution strategies, and expose operator-facing runtime tooling.

## Consequences

- consumer modules define domain workers through `sm_workers` extension points
- operator-facing consume commands and worker operations follow one shared model
- execution strategy selection lives in shared runtime services instead of
  being reimplemented per module
- circuit-breaker behavior and queue-depth reporting remain reusable runtime
  concerns rather than private application code
