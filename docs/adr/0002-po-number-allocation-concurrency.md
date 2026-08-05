# ADR-0002 — PO number allocation under concurrency

## Status

Accepted (documentation-only; M2-C checkpoint). No implementation change.

## Context

M2 allocates purchase-order numbers via `WC_Inventory_Overview_PO_Numbering`
using a WordPress option map (`wc_io_po_number_sequence`) and inserts them into
`wc_io_purchase_orders.po_number`, which has a `UNIQUE` index.

The M2-C checkpoint confirmed:

- Uniqueness is guaranteed by the database `UNIQUE` constraint.
- Duplicate PO numbers cannot be committed.
- The option-based sequence allocator is **not** cross-request atomic
  (read–modify–write on `update_option`).
- Under a true concurrent race, one request may receive a duplicate-key
  failure instead of automatically obtaining the next sequence value.
- Correctness is preserved: at most one row wins; the other fails cleanly.

## Decision

Document the following as an **explicit M2 architectural limitation** — an
**optimization gap, not a correctness defect**. Do not change M2 runtime
behavior solely to address it.

### Current guarantee

- Format `PO-{YYYY}-{NNNN}` (pad ≥4; grows past 9999).
- Numbers are never reused; gaps after failed creates, draft deletes, or
  exhausted retries are expected.
- The `UNIQUE KEY po_number` constraint is the hard commit boundary: two
  rows with the same number cannot both commit on MariaDB/InnoDB.

### Current limitation

- Sequence allocation uses a WordPress option, not a transactional row lock
  or dedicated sequence table.
- Concurrent draft creates may race on the option value, so one request can
  fail at insert with a duplicate-key error rather than transparently
  receiving the next free number.
- `allocate()` retries (up to three) when `exists()` already sees a number;
  it does not currently wrap “insert failed → allocate again” as a single
  automatic recovery path for every race.

### Why this is acceptable for M2

- **Correctness is intact:** uniqueness cannot be violated at commit.
- Operator volume for concurrent PO creation is low for the intended
  purchasing UI; occasional create failure with retry is acceptable.
- Gaps and never-reuse already match the approved M2 numbering invariant.
- Fixing allocator atomicity is optional hardening, not required to ship
  M2-C/M2-D.

### Possible future evolution (optimization only)

Any of the following may be introduced later via a dedicated change (not M2
scope unless product prioritizes it):

- Transaction-backed allocator (lock a sequence row inside the create txn).
- Dedicated sequence table with `SELECT … FOR UPDATE` or atomic `UPDATE`.
- Retry-on-duplicate-key inside `create_draft` after a raced insert failure.

These improve operator experience under concurrency; they do **not** change
the uniqueness correctness model already enforced by the unique index.

## Consequences

- Implementers and reviewers treat duplicate-key create failures under race
  as a known limitation, not a regression.
- M2-D+ admin UX may surface a retry-friendly error; that is presentation,
  not a numbering redesign.
- Changing the allocator requires an explicit follow-up decision; this ADR
  does not authorize schema or runtime work by itself.

## Related

M2-C `WC_Inventory_Overview_PO_Numbering`, schema `UNIQUE KEY po_number`,
tests `test_duplicate_key_retry_and_exhaustion` and
`test_unique_index_rejects_duplicate_po_number_insert`.
