# Design: durable Game Review queue

**Status:** Proposed — no code written
**Date:** 2026-10-29
**Author's note:** written against the code as of `a15ef33`.

## 1. The problem

Batch Game Review runs entirely in the admin's browser. `reviewSavedAnalyses()`
loops over analysis ids, and for each one `startPgnAnalysis()` drives a pool of
WASM Stockfish workers, saving each finished game back through
`PATCH /api/analyses/{id}`.

That design has had three rounds of patching this month — per-game progress bars,
pause/resume, and removing the follow-the-board option — and all three were
smoothing over the same underlying constraint: **the run exists only in one tab.**
Specifically:

- Closing the tab, navigating away, or a laptop sleeping loses the whole run.
  Finished games are saved, but the position-level work of the current game is
  gone and the remaining queue is forgotten.
- Only the admin who started it can see it. Nobody else knows a run is happening,
  and two admins can start overlapping runs on the same games.
- Pause state lives in a JS variable. A reload resets it.
- There is no record that a review ran, who ran it, or at what depth.

### Requirements

**Functional**

1. A queue of games to review survives tab close, reload, container restart and deploy.
2. Progress is visible to any admin, not just the one who started it.
3. Pause, resume and cancel are durable, not in-memory.
4. Position-level work already done is never redone.
5. Two admins cannot double-review the same game.
6. Each finished game is saved exactly as today, through the existing analysis document.

**Non-functional**

7. **No new paid infrastructure.** This runs on one Railway service and an Atlas M0.
8. The site must stay responsive. The scheduler worker runs *inside the web
   container* (`docker/start.sh` backgrounds `messenger:consume` next to
   FrankenPHP), so any CPU a review consumes is CPU the site isn't serving with.
9. Scale is tens to low hundreds of games, 40–90 positions each.

**Constraints that rule options out**

10. **Messenger has no usable transport.** `config/packages/messenger.yaml`
    configures only `sync://`. The standard async transports need Postgres/MySQL
    (`doctrine://`), AMQP or Redis — and this app has none of them. Symfony ships
    no MongoDB transport. So "just use Messenger async" is not available without
    adding a service.
11. The server engine is deliberately small: `MAX_CONCURRENT_ENGINES = 2`,
    `BATCH_BUDGET_SECONDS = 45`, `SLOT_WAIT_SECONDS = 20` before a 503, four
    threads and 16 MB hash per engine.
12. The scheduler is `flock`-locked and cache-stateful, i.e. single-instance.

## 2. The number that decides this

How much CPU is a review, if the server does it? 80 positions per game, plus
~15% for the second-line pass:

| Batch | @0.5s/pos | @1s/pos | @3s/pos |
| :--- | :--- | :--- | :--- |
| 10 games | 0.1h cpu / 0.1h wall | 0.3h / 0.1h | 0.8h / 0.4h |
| 50 games | 0.6h cpu / 0.3h wall | 1.3h / 0.6h | 3.8h / 1.9h |
| 200 games | 2.6h cpu / 1.3h wall | 5.1h / 2.6h | 15.3h / 7.7h |

(wall-clock assumes both engine slots busy; per-position cost is unmeasured — a
shared Railway vCPU at depth 22 plausibly lands in the 1–3s column.)

**A 50-game review is one to four hours of continuous engine time on the box that
serves the website.** That is the finding that shapes everything below: the
browser pool is not a workaround to be removed, it is *where the CPU actually is*.
An admin's desktop has 8 idle cores; the container has a fraction of one to
spare. Moving execution server-side would make a routine batch an outage risk.

So the goal is not "move the review to the server". It is **move the *job* to the
server and leave the *work* where the CPU is.**

## 3. Recommended design — server-owned queue, browser-driven execution

Lichess's fishnet model: the server owns a queue of positions and hands them out;
clients claim, compute and submit. `startPgnAnalysis()`'s own comments already
cite fishnet for its node-budget choice, so this is a continuation, not a new idea.

```
  admin browser A            admin browser B            scheduler worker
  ┌──────────────┐           ┌──────────────┐           ┌───────────────┐
  │ WASM pool ×8 │           │ WASM pool ×4 │           │ sweep expired │
  └──────┬───────┘           └──────┬───────┘           │ leases, retry │
         │ claim/submit             │                   │ server engine │
         │                          │                   │ for leftovers │
         ▼                          ▼                   └───────┬───────┘
  ┌──────────────────────────────────────────────────────────────▼──────┐
  │  POST /api/admin/review-jobs        create a run (ids, depth)       │
  │  GET  /api/admin/review-jobs        progress for every admin        │
  │  POST /api/admin/review-jobs/{id}/claim    lease N positions        │
  │  POST /api/admin/review-jobs/{id}/submit   return evaluations       │
  │  PATCH/DELETE .../{id}              pause · resume · cancel         │
  └──────────────────────────────────┬──────────────────────────────────┘
                                     ▼
                        ┌────────────────────────┐
                        │ review_jobs (Mongo)    │
                        │ review_tasks (Mongo)   │──▶ analyses (existing)
                        └────────────────────────┘
```

### Data model

`review_jobs` — one per run:

| field | purpose |
| :--- | :--- |
| `_id` | job id |
| `created_at`, `created_by` | who started it (pairs with the audit log) |
| `state` | `running` · `paused` · `done` · `cancelled` |
| `depth` | null for the node budget, or 8–26 |
| `analysis_ids` | the queue, in order |
| `totals` | `{games, positions, done, failed}` for cheap progress reads |

`review_tasks` — one per position, the unit of claiming:

| field | purpose |
| :--- | :--- |
| `job_id`, `analysis_id`, `move_index` | what to analyse |
| `fen`, `lines` | inputs (1 line, or 2 for the Great-label pass) |
| `state` | `pending` · `leased` · `done` · `failed` |
| `lease_until`, `leased_by` | crash recovery (§5) |
| `result` | the position entry, shape unchanged from today |
| `attempts` | for give-up-after-N |

Indexes: `{job_id, state}` for claiming, `{state, lease_until}` for the sweeper,
TTL on `review_jobs.created_at` to retire old runs — the same pattern the audit
log already uses.

Writing per-position results to `review_tasks` and only assembling them into the
`analyses` document when a game completes keeps the existing analysis schema
untouched, which matters: that schema is read by every viewer's Game Review and
by profile accuracy.

### Claiming

`claim` is the only interesting operation. It must be atomic, so one position is
never handed to two browsers:

```
findOneAndUpdate(
  { job_id: J, state: 'pending' },
  { $set: { state: 'leased', leased_by: W, lease_until: now + 120s },
    $inc: { attempts: 1 } },
  { sort: { move_index: 1 } }
)
```

`findOneAndUpdate` is atomic per document in Mongo, so N parallel claims from two
browsers simply return N different tasks. Called in a small loop to lease a
handful at a time (one round trip per position would be chatty at 8 workers).

A paused job claims nothing — the gate moves from a JS variable to
`state != 'running'`, which is exactly what makes pause survive a reload.

### Submit

`submit` posts `{task_id, result}`, sets `state: 'done'`, and when a job's tasks
for one `analysis_id` are all done, assembles them and writes the analysis
through the existing path. Idempotent: submitting an already-`done` task is
accepted and ignored, so a retry after a flaky response can't corrupt a game.

## 4. Options considered

| | Complexity | New infra | Site risk | Unattended? | Tab-close safe |
| :--- | :-- | :-- | :-- | :-- | :-- |
| **A** Messenger async, server executes | High | **Redis/Postgres** | **High** (§2) | Yes | Yes |
| **B** Mongo job collection, server executes in the scheduler worker | Medium | None | **High** (§2) | Yes | Yes |
| **C** Mongo job + task queue, browsers claim *(recommended)* | Medium | None | Low | Partly | Yes |
| **D** Status quo | None | None | None | No | **No** |

**A** is the textbook answer and the worst fit: it needs a service this project
doesn't have purely to hold a queue, and then spends §2's hours of CPU in the
web container anyway.

**B** avoids the new service — the worker already exists and is restarted hourly
— but keeps the CPU problem, and the hourly `--time-limit=3600` restart would
sever a long review mid-game unless tasks are resumable, at which point the task
collection from **C** is needed regardless.

**C** gets durability, shared visibility and multi-admin throughput without new
infrastructure or site risk. Its honest weakness: it is only *partly* unattended
— a queue with no browser attached makes no progress. Mitigated by having the
scheduler worker drain leftovers through the existing server engine at a trickle
(one or two positions per tick), so an unattended queue still finishes, slowly,
within the limits of constraint 11.

**D** is viable for a handful of games and should remain the default path for one
game. The queue is for the bulk case.

## 5. Reliability

- **Browser disappears mid-claim.** Leases expire. The scheduler sweeper resets
  `leased` tasks whose `lease_until` has passed to `pending`. Worst case, a
  position is recomputed — idempotent, so harmless.
- **Deploy or restart mid-run.** Job and task state are in Mongo; a reconnecting
  browser resumes claiming. This is the main thing status quo cannot do.
- **Poison task.** `attempts` caps retries; after 3 the task is `failed` and the
  game is reported as partially reviewed rather than blocking the queue.
- **Two admins, one game.** Impossible by construction — claiming is atomic.
- **Trust.** Submitted evaluations are *client-computed*. The `submit` endpoint
  must be admin-only, and even then the server is trusting an admin's browser not
  to send nonsense. That is acceptable here (admins are trusted operators, and
  this is already true of today's `PATCH /api/analyses/{id}`) but it is the reason
  this model should never be opened to anonymous volunteers without validation.
- **Observability.** Job creation, pause and cancel go through admin endpoints,
  so the audit log records them for free. Per-position submits would flood it —
  exclude that one route.

## 6. What I would revisit

- **Rating replay.** Unrelated to reviews but the next thing to bite: every
  invalidate, edit, delete and merge replays *all* valid matches and rewrites
  their stored rating fields. Fine at thousands of matches, quadratic-feeling
  past that.
- **Single instance.** `LOCK_DSN=flock` and the cache-backed schedule assume one
  container. A second replica would double-fire the schedule. A Mongo-backed lock
  would fix it, and is a precondition for scaling the web service at all.
- **Server-engine budget.** `MAX_CONCURRENT_ENGINES = 2` and the 45s batch cap
  were sized for interactive requests. A trickle-drain worker wants its own,
  lower budget so it can never contend with a live analysis.
- **Whether any of this is worth it.** If batches stay under ~10 games, the status
  quo plus the progress UI already shipped is adequate, and this design is
  premature. The trigger to build it is a real need to review the back catalogue
  unattended, or more than one active admin.
