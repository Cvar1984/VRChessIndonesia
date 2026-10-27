# ADR-0001: Resolving duplicate players created by VRChat-name-as-identity

**Status:** Proposed — implemented on `ccr-baa66310-huwu2h` (`6b77446`), pending CI and review
**Date:** 2026-10-27
**Deciders:** Cvar1984 (repo owner)

## Context

A linked player's username *is* their VRChat display name. `AvatarRefresher::syncUsername()`
renames them on link and on every daily refresh, and `username_history` keeps the old names
resolving for `OLD_NAME_GRACE_DAYS` (90).

Two properties of the system collide:

1. **Names are mutable and externally owned.** VRChat users rename freely, and VRChat lets a
   released display name be claimed by someone else.
2. **Player rows are created implicitly.** `MatchManager::getOrCreatePlayer()` creates a player
   for any unknown name a match submission carries. A bot reporting `Alice` before that human
   was ever linked creates a second row for a person who already exists as `Alice VR`.

So two rows can represent one human. When the linked row tries to take its VRChat display name
and an unlinked duplicate already holds it, the unique index on `username` would reject the
write, so `syncUsername()` returns `'conflict'` and skips the rename. It then does exactly the
same thing on the next refresh. **The conflict is permanent and nothing in the app could
resolve it** — the state this ADR addresses.

The one property that makes any fix possible: **identity is not the name.** `ChessMatch` stores
`white_id`/`black_id` as the immutable integer `Player::$id`, so match history already survives
renames and can be repointed without touching the historical record.

Constraints shaping the decision:

- Community scale — tens of players, low thousands of matches. Replay cost is not a concern.
- Ratings are **already** computed by full chronological replay (`recalculateRatings()`), not
  incrementally. Any correct answer has to go through that mechanism.
- Conflicts are rare and need judgement (is this really the same human?), so some human in the
  loop is acceptable.

## Decision

Add an **admin-triggered hard merge** of one player row into another:
`MatchManager::mergePlayers($from, $into)`, exposed as `POST /api/admin/players/merge`, surfaced
in the admin panel as a *Duplikat Pemain* card listing every currently-blocked rename.

The merge repoints `from`'s matches at `into`, absorbs `from`'s current and previous names into
`into`'s `username_history` as `source: 'merge'`, moves `from`'s VRChat link across only if
`into` has none, deletes `from`, then **replays every rating from scratch**. It then retries the
rename the duplicate was blocking.

## Options Considered

The template's *Cost* and *Scalability* dimensions don't discriminate at this scale — every
option is free and fast on tens of players — so they're replaced with the dimensions that
actually separate these options: **reversibility** and **rating correctness**.

### Option A: Admin-triggered hard merge with full rating replay — *chosen*

| Dimension          | Assessment                                                              |
|--------------------|-------------------------------------------------------------------------|
| Complexity         | Medium — one service method, reuses the existing replay                 |
| Reversibility      | **None** — the duplicate's document is deleted                          |
| Rating correctness | **Exact** — equals the counterfactual where the duplicate never existed |
| Operator burden    | One click per conflict, once                                            |

**Pros:** One row per human afterwards, so every downstream consumer (leaderboard, profile
accuracy, Brilliant counts, PGN name matching) is correct with no special-casing. Reuses the
replay that already exists, so correctness follows from a mechanism already trusted and tested.
Names keep resolving, so bots posting the old name don't recreate the duplicate.

**Cons:** Irreversible. The operator must judge whether two rows really are one person.

### Option B: Automatic merge when a conflict is detected during refresh

| Dimension          | Assessment                                                    |
|--------------------|---------------------------------------------------------------|
| Complexity         | Low — reuses Option A's merge, dropping the endpoint and UI    |
| Reversibility      | None, and performed unattended                                |
| Rating correctness | Exact, when the guess is right                                |
| Operator burden    | Zero                                                          |

**Pros:** Self-healing; no human ever sees a conflict.

**Cons:** The premise is a *guess*. VRChat permits a released display name to be claimed by a
different person — the exact case `OLD_NAME_GRACE_DAYS` already exists to protect against. An
unattended merge would silently fold two genuinely different players together, destroying one
irreversibly, and the daily scheduler would do it at 03:00 with nobody watching. Rejected: an
irreversible destructive operation on a guess.

### Option C: Soft merge — keep both documents, mark one an alias

| Dimension          | Assessment                                                           |
|--------------------|----------------------------------------------------------------------|
| Complexity         | **High** — every read path must learn about aliases                  |
| Reversibility      | Full — unmerge is just clearing a field                              |
| Rating correctness | Exact, but only if every consumer resolves aliases correctly         |
| Operator burden    | One click, reversible                                                |

**Pros:** Reversible, which Option A is not. Nothing is destroyed.

**Cons:** Pushes the complexity into every reader forever — the leaderboard, rankings, stats,
replay, PGN matching and the avatar proxy would each have to resolve aliases, and any one that
forgot would show a ghost player or double-count. It also permanently keeps a row whose
`username` still occupies the unique index, so it doesn't even solve the original problem
without additionally freeing the name. Rejected: the cost is paid by all future code, and the
reversibility it buys can be had more cheaply by backing up the two documents before a hard
merge.

### Option D: Rename around the conflict with a suffix (`Alice (2)`)

| Dimension          | Assessment                                        |
|--------------------|---------------------------------------------------|
| Complexity         | **Low** — a few lines in `syncUsername()`          |
| Reversibility      | Full — nothing is destroyed                       |
| Rating correctness | **Wrong** — stats stay split across two rows      |
| Operator burden    | Zero                                              |

**Pros:** Trivial, destroys nothing, unblocks the rename immediately.

**Cons:** Treats the symptom. The human still has two rows, two ratings and two half-populated
stat lines, and now one of them is named something they never chose and will never recognise.
Rejected: it makes the leaderboard actively misleading in order to silence a log line.

## Trade-off Analysis

The decision turns on a single axis: **who judges whether two rows are one person.**

Option B says the system judges. That's unacceptable not because the heuristic is bad but
because the action is irreversible and the system's own code already documents that the premise
can be false — `OLD_NAME_GRACE_DAYS` exists precisely because a VRChat name can change hands.
An automatic merge is a one-way destructive operation taken on an assumption the codebase
elsewhere refuses to make.

Option D avoids judgement entirely, at the price of a permanently wrong leaderboard. Since the
whole reason linked players follow their VRChat name is so the leaderboard reflects reality,
this defeats the feature it's meant to support.

That leaves A versus C — hard versus soft merge — which is a straight trade of **reversibility
for simplicity.** C's reversibility is real but is paid for by every future read path, an
open-ended cost on code not yet written, and the failure mode of a reader that forgets is a
silently wrong leaderboard. A's irreversibility is a bounded, one-time risk at the moment of the
click, mitigable by confirmation (done), by refusing the provably-wrong cases (done), and by a
pre-merge backup (not done — see Action Items).

Two secondary trade-offs fell out of choosing A:

- **Replay vs. summing stats.** Summing is tempting and wrong: each match's rating delta depends
  on both players' ratings *at that moment*, so two independently-accumulated rating histories
  cannot be added. Replay is the only correct answer, and it's already the mechanism used for
  invalidate/revalidate/edit, so this adds no new concept.
- **Head-to-head games.** Games the two rows played against each other become one player against
  themselves, which no replay can score. Deleting them would destroy real history; leaving them
  valid would corrupt the replay. They're **invalidated** — already a first-class state that the
  replay skips and the UI can show.

## Consequences

**What becomes easier**

- Blocked VRChat renames become resolvable, and stay resolved — absorbed names keep pointing at
  the survivor, so a bot posting the old name doesn't recreate the duplicate.
- One row per human means profile accuracy, Brilliant counts and PGN rating lookup see a
  complete history instead of two partial ones.
- No new concept enters the read paths; everything downstream keeps assuming one row per player.

**What becomes harder**

- **There is no unmerge.** A mistaken merge can only be repaired by re-entering the matches by
  hand.
- Every merge replays all valid matches, so each one rewrites the stored `old_*_rating` and
  `rating_change_*` on every valid match. Fine here, but it makes a merge a global write, not a
  local one.
- Head-to-head games disappear from both players' ratings and counts. Correct, but it means a
  merge can *lower* the survivor's game count relative to the sum of the two rows — surprising
  unless you know why.
- Resolving by current username only (no history fallback) is deliberate — a stale name must not
  silently merge the wrong record — but it means an operator holding an old name gets a 409 and
  has to look up the current one.

**What we'll need to revisit**

- **Auto-merge for the provably-safe case.** When both rows carry the *same* `vrchat_user_id`,
  they are the same VRChat account by construction, not by inference. Option B's objection
  doesn't apply, so that narrow case could be merged unattended later.
- **No audit trail.** `username_history` records absorbed names with `source: 'merge'`, but
  nothing records *which* matches moved or who clicked. The only account of a merge is in
  server-side nothing and the admin's memory.
- **Grace-period interaction.** Absorbed names are stamped at the merge, so they resolve for 90
  days from then. If a duplicate's name is later claimed by a different human within that
  window, their matches would be credited to the survivor. Same pre-existing risk the rename
  path carries, now reachable one more way.

## Action Items

1. [x] `MatchManager::mergePlayers()` with match reassignment, name absorption, link transfer and
       full replay
2. [x] `POST /api/admin/players/merge`, admin-only (not API token — irreversible and global)
3. [x] Guards: unknown player, same record, and two players linked to *different* VRChat accounts
4. [x] Admin panel *Duplikat Pemain* card, derived from the player list so it's accurate on load;
       rows that the server would reject show why instead of a button
5. [x] Document the endpoint and semantics in README
6. [ ] **Run the 18 PHPUnit tests** — they could not be executed in the authoring environment
       (no `ext-mongodb`, and the network policy blocks installing it), so CI is the first real
       execution. Treat them as unverified until then.
7. [ ] Back up both documents (and the affected match ids) before a merge, writing them somewhere
       recoverable — the cheapest available mitigation for "no unmerge"
8. [ ] Record a merge audit entry: operator, timestamp, both player ids, match ids moved
9. [ ] Consider auto-merging only when both rows share the same `vrchat_user_id`
