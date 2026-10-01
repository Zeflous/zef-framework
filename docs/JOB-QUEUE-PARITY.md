# Job Queue Adapter Parity Matrix — the signed `available_at` domain

> **Status**: pinned BEFORE the implementation (the v2.33 OpenAPI and v2.34
> stub-pre-scan discipline: the contract is committed first, the code follows).
> This document is the normative parity contract for the three
> `JobQueueInterface` adapters — `InMemoryJobQueue`, `PdoJobQueue`,
> `RedisStreamJobQueue` — with the signed-timestamp ordering rows added after
> the v2.34.0 deep audit surfaced a real divergence (reproduced below).

---

## 1. The domain contract being decided

`JobEnvelope::$availableAtUnixNano` is a **full signed 64-bit integer**.
That is the de-facto contract the two reference adapters have always
implemented — `InMemoryJobQueue` orders numerically in PHP and `PdoJobQueue`
stores `available_at BIGINT NOT NULL` with `ORDER BY available_at ASC` — so
the decision recorded here is *domain-level, not adapter-level*:

> **Every adapter must preserve numeric ordering and numeric availability
> verdicts over the entire signed 64-bit domain of
> `availableAtUnixNano` (and of `dequeue($nowUnixNano)`).**

Pre-epoch deadlines are pathological in practice (a job due before
1970-01-01 can only come from a caller bug), but pathological inputs are
exactly where parity contracts are allowed to say "identical behaviour"
or "rejected loudly by all three" — never "silently different per adapter".
v2.32.0 chose neither: it documented the Redis side as *"negative timestamps
… still comparable among themselves for the common same-magnitude case"*,
which is **factually wrong** (the same-magnitude case is precisely where
string order inverts — see the reproduction below).

## 2. The divergence, reproduced (pre-fix evidence)

Live reproduction against Redis 8.0.2 (`127.0.0.1:6399`) with two jobs at
equal priority — `A` due at `-20`, `B` due at `-10`:

| Check | InMemoryJobQueue | PdoJobQueue (SQLite) | RedisStreamJobQueue v2.32–v2.34 |
|---|---|---|---|
| `dequeue(now: 0)` order | A, then B ✓ | A, then B ✓ | **B, then A ✗** |
| `dequeue(now: -15)` due | A due, B not yet ✓ | A due, B not yet ✓ | **B due, A not ✗** |
| Round-trip value | −20 / −10 ✓ | −20 / −10 ✓ | −20 / −10 ✓ (value intact; only ordering/verdicts wrong) |

Both Redis verdicts are wrong in **both directions**: the earlier job is
reported as not-yet-due and the later job as due. Root cause: unpadded
`"-20"` / `"-10"` strings compare lexicographically in Lua, and
`"-10" < "-20"` while `-10 > -20`.

## 3. The encoding that restores parity (v2.34.1)

The Redis adapter keeps the 20-character sortable-string representation but
makes it order-preserving over the **full signed domain**:

| `availableAtUnixNano` | Stored as (20 chars) | Ordering property |
|---|---|---|
| `v >= 0` | `sprintf('%020d', v)` — **byte-identical to v2.32–v2.34** | lex = numeric (unchanged) |
| `v < 0` | `'-'` + 9's-complement of the 19-digit zero-padded magnitude | lex = numeric over the whole signed range (incl. `PHP_INT_MIN`) |

Why the complement: for negatives, larger magnitude must sort *earlier*;
per-digit `9 − d` inverts the magnitude order while the leading `'-'`
(0x2D) keeps every negative before every positive (0x30). Verified by
fuzz: 200 000 random `(a, b)` pairs across the full signed domain —
`strcmp(enc(a), enc(b))` sign-matches `a <=> b` in every case.

Decode mirrors the transform (`strtr` back) and produces a plain numeric
string, so `JobRowCodec::intVal`'s `(int)` cast round-trips even
`'-9223372036854775808'` → `PHP_INT_MIN` exactly.

## 4. The full parity matrix (12 boundaries)

| # | Boundary | InMemoryJobQueue | PdoJobQueue | RedisStreamJobQueue |
|---|---|---|---|---|
| 1 | Acceptance domain of `availableAtUnixNano` | full signed 64-bit | full signed 64-bit (BIGINT) | full signed 64-bit (complement string) |
| 2 | Ordering `priority DESC, available_at ASC, seq ASC` | numeric PHP sort | numeric SQL `ORDER BY` | lex on order-preserving encoding — **numeric-equivalent over the full domain (v2.34.1)** |
| 3 | Availability verdict `available_at <= now` | numeric compare | numeric SQL `WHERE` | lex on order-preserving encoding — **numeric-equivalent (v2.34.1)** |
| 4 | Round-trip fidelity (all nine envelope fields) | exact | exact | exact (decode restores the signed int) |
| 5 | Duplicate job id | in-memory key collision | `UNIQUE(job_id)` constraint | `SADD` live-id set backstop |
| 6 | Capacity guard | enqueue-time count | enqueue-time count | atomic Lua `XLEN` check |
| 7 | Claim semantics | destructive on claim | `SELECT → DELETE` in one transaction | atomic Lua `XRANGE → XDEL + SREM` |
| 8 | Crash safety of a claim | n/a (in-process) | transaction rollback | single-script atomicity (no pending-entry state) |
| 9 | Retry semantics | redelivery, attempt preserved | redelivery, attempt preserved | redelivery, attempt preserved (`queue:retry` does not reset attempts) |
| 10 | Corrupt entry doctrine | — | row survives failed hydrate (documented wedge) | entry destroyed by the claim, failure surfaces once (liveness-over-preservation) |
| 11 | Optional metadata (`correlation_id`, `trace_parent`, `headers`) | exact | exact (NULL ↔ '' sentinel) | exact (NULL ↔ '' sentinel) |
| 12 | Storage representation | PHP int | SQLite BIGINT | 20-char sortable string; negatives as `'-'+complement` (v2.34.1) |

Row 10 note: a negative `available_at` written by a **pre-v2.34.1 release**
(unpadded, width ≠ 20) is treated by the decoder as a corrupt field — the
claim has already destroyed the entry, the hydrate throws
`RedisJobQueueException` once, and the queue keeps flowing: exactly the
documented corrupt-entry doctrine. Realistic queues (non-negative
deadlines) are unaffected because their stored bytes are unchanged.

## 5. Migration notes

- Entries written by v2.32.0–v2.34.0 with **non-negative** deadlines
  compare identically under the new encoding (byte-compatible) — no
  migration, no drain required.
- Entries with negative deadlines (the pathological case) misordered
  before the fix and hydrate loudly after it; draining them is a
  `queue:flush` away. The fix does not silently reinterpret them.
- `queue:retry` / `queue:failed` flow through `peek()` + `enqueue()`, so a
  failed job re-enqueued after the upgrade is stored in the new encoding
  automatically.
