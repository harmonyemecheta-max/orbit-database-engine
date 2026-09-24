# ORBIT Database & Migration Engine

The decoupled, high-performance database abstraction and fluid migration engine utilized by the proprietary **ORBIT Control Plane** (managed by Hypervirtue Co.).

## 🚀 System Architecture Overview

This component was engineered from scratch in native PHP to provide low-overhead database management across polyglot cloud environments without relying on third-party frameworks. 

### Key Subsystems:
* **Polyglot Database Drivers (`db\drivers`):** Implements a strict, extensible interface mapping both Relational engines (PostgreSQL, MySQL, Oracle, MSSQL, SQLite) and NoSQL/Cloud backends (DynamoDB, MongoDB, CouchDB, Supabase, Firebase, Planetscale, Redis) under a unified data access layer.
* **Schema Orchestration & Topological Sorter (`db\schema\orchestrator`):** Features a custom Dependency Graph and a Topological Sorter algorithm that automatically calculates database schema execution order based on foreign-key dependencies.
* **Schema Planner & Transactional Compiler (`db\schema\compiler`):** Compiles schema blueprints safely and manages isolation using a custom SchemaTransaction wrapper to prevent partial or corrupted database rollouts.
* **Custom ORM Query Builder & Dialect Grammars (`db\orm`):** A fluent query-chaining interface backed by vendor-specific Grammar modules to automatically handle token quoting, subqueries, and syntax compilation across varying SQL dialects.
* **Migration Builder (`db\migrations`):** A completely engine-agnostic blueprint builder supporting safe table manipulation (`safeAddColumn`, `safeDropColumn`) and automated schema introspection.

## 🛠️ Framework Agnostic Integration

While built as a core system component, this engine is designed to be injected into modern frameworks. For instance, it maps directly to Laravel's Service Container via a standard Service Provider:

```php
// Example registration within a Laravel Service Provider
this->app->singleton(PostgreSQLDriver::class, function (app) {
    return new PostgreSQLDriver(config('database.custom_connections.pg'));
});
```

## 📄 Licensing & Corporate Context
This sub-module is open-sourced under the MIT License for portfolio demonstration. The core routing orchestrator, zero-trust control mechanisms, and multi-tenant security guards remain confidential and proprietary to Hypervirtue Co.
