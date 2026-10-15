---
paths:
  - 'app/**'
---

# App

## PostgreSQL is a supported production engine: a caught unique violation needs a savepoint
Tests run on SQLite; production runs on PostgreSQL or MySQL (see "Raw SQL must run on PostgreSQL and MySQL alike"). PostgreSQL aborts the whole open transaction when an insert is refused, so "insert and catch UniqueConstraintViolationException" silently breaks every later statement in that transaction. Use `createOrFirst`, or wrap the insert in `Builder::withSavepointIfNeeded()` before catching (see Shipment::recordEvent, StockLedger::writeOnce, VisitCounter::add). Likewise never let a non-numeric value reach an integer key comparison: PostgreSQL raises where SQLite returns no rows (hence the global `Route::pattern('record', '[0-9]+')` in AppServiceProvider::register). To check, run the suite against a PostgreSQL database by setting the DB_* env vars, which override phpunit.xml.

## Command names: bongotea: for install-time, area:verb for the rest
A command a person runs once to stand up or recover an installation carries the product prefix: `bongotea:install`, `bongotea:owner`. Everything else is `area:verb` — `permission:sync`, `tracking:prune`, `couriers:sweep`. No command takes a `giopages:` prefix; the `giopages` config namespace and icon set are a separate matter and stay.

## Raw SQL must run on PostgreSQL and MySQL alike
Production may be PostgreSQL or MySQL/MariaDB, and both must keep working; tests run on SQLite, so a green suite proves neither. Every selectRaw/orderByRaw/DB::raw must be portable or branch on `$connection->getDriverName()`. Known traps: MySQL has no `cast(… as bigint)` (use `signed`; PostgreSQL has no `signed`), so integer casts go through `Grams::summedCost()`/`lineCost()`; MySQL has no `nulls last` (write `col is null, col desc`, as CustomersTable does). To check, run the suite against each engine by setting the DB_* env vars, never against a live database.
