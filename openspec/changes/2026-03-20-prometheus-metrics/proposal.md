# Proposal: Prometheus Metrics Enhancements

## Problem
The Pipelinq metrics and health endpoints are partially implemented. Missing features include: conversion rate metric, OpenRegister dependency health check, token-based authentication for external scrapers, and the `pipelinq_dependency_up` metric.

## Solution
Extend the existing MetricsController, HealthController, MetricsRepository, and MetricsFormatter to add:
1. Conversion rate gauge metric per pipeline
2. OpenRegister dependency check in health endpoint
3. Bearer token authentication for metrics endpoint
4. Dependency up/down metric for OpenRegister

## Scope
- `lib/Controller/MetricsController.php` — add token auth, conversion rate
- `lib/Controller/HealthController.php` — add OpenRegister check
- `lib/Service/MetricsRepository.php` — add conversion rate query
- `lib/Service/MetricsFormatter.php` — add conversion rate + dependency formatting
- `tests/Unit/Service/MetricsFormatterTest.php` — unit tests
- `tests/Unit/Service/MetricsRepositoryTest.php` — unit tests

## Risks
- Database queries for conversion rate need to handle division by zero
- Token auth must not break existing admin-authenticated access
