# Build phases

Status as of 2026-09-10. This file is the plan of record; when a decision here
disagrees with an older note, this one wins.

The ordering principle has not changed: each phase ends on something runnable,
dependencies come before dependents (nothing can be authorized before login,
nothing can broadcast before it persists), and the riskiest layer — Reverb —
waits until the logic underneath it is proven.

---

## Phase 0 — Foundation: schema + auth ✅ done

- The core tables from AGENTS.md: `conversations` (unique `direct_key`),
  `conversation_user` pivot, `messages`, `attachments`.
- `Conversation::findOrCreateDirect()` — race resolved by the unique index.
- `ConversationPolicy` on pivot membership.
- **Passwordless auth**, not the seeded-users login this file originally
  planned: email → 6-digit code → in. New address is asked for a name and a
  username. Registration is open to anyone; the rate limits are what stop the
  app being used to flood inboxes.
- **Usernames**, added after the fact: exact-match lookup opens a DM. There is
  no friend request — knowing the handle is the introduction.
- `ChatController` reads from PostgreSQL; the payload shape in
  `resources/js/types/index.ts` never changed, so no component was touched.

## Phase 1 — Messaging over HTTP (expanded)

Scope grew well past what this file first described. The owner chose the
maximal option in every dimension, so Phase 1 now carries four steps. Reverb
moves out accordingly.

### 1a ✅ done

- `POST /conversations/{conversation}/messages` — Form Request, policy, transaction.
- `POST /conversations/direct` — find-or-create a DM by username (pulled forward).
- `PATCH /conversations/{conversation}/read` — moves the high-water pointer;
  `unread_count` is derived from it.
- Client: the server owns the thread. Local state holds only unacknowledged
  messages. Sends are **queued and `async: true`** — Inertia's default stream is
  `{maxConcurrent: 1, interruptible: true}`, so two quick sends cancel the first
  and strand its bubble on "pending" forever.
- `ChatController` props are **closures**, so a partial reload genuinely skips
  the work it did not ask for.

### 1b — groups, roles, members panel ✅ done

- 7 routes, the policy matrix below, owner succession, and 22 tests. The
  members panel is wired: `details-panel.tsx` behind the `Info` button, group
  creation behind the compose menu. `DELETE …/membership` was pulled forward
  from 1c because the panel needs a Leave button to be honest.
- `POST /conversations` — name + usernames. Creator becomes `owner_id` and admin.
- Members panel behind the `Info` button: roles, add, remove, promote, demote,
  transfer ownership, the two setting switches, leave.
- Authorization, group-only (a direct chat has two equals and refuses all of it):

  | Ability | Owner | Admin | Member |
  |---|:--:|:--:|:--:|
  | view · send · markRead · clearHistory | ✓ | ✓ | ✓ |
  | addMember | ✓ | ✓ | only if `members_can_add` |
  | removeMember (not self, not owner) | ✓ | ✓ | ✗ |
  | promote / demote (never the owner) | ✓ | only if `admins_can_promote` | ✗ |
  | updateSettings (name, `members_can_add`) | ✓ | ✓ | ✗ |
  | updateSettings (`admins_can_promote`) | ✓ | ✗ | ✗ |
  | transferOwnership | ✓ | ✗ | ✗ |
  | leave | ✓ | ✓ | ✓ |

- **Owner leaves** → ownership passes to the longest-standing admin, tiebroken by
  lowest `user_id`. `joined_at` ties are the common case, not an exotic one, so
  the tiebreak is load-bearing. No admin left → longest-standing member is
  promoted. Last participant leaves → the conversation is deleted.
- **On every attach** (create, add, re-add) set both pointers to the current max
  message id, so a new member sees the group from the moment they joined and
  a returning one does not inherit the entire backlog as unread.

### 1c — clearing and deleting ✅ done

Built together with 1d, because both make message visibility per-viewer and
would otherwise rewrite the same three queries twice.

- `DELETE …/history` — sets `cleared_up_to_message_id` **and**
  `last_read_message_id`. Setting both is what stops a badge you cannot clear.
  The row stays on the list; you are still in the conversation, you have just
  stopped carrying its past around.
- `DELETE …/{conversation}` — **direct only**; a group is left, not deleted.
  Revised from "wipes it for both sides": the default is one-sided, and an
  explicit "Also delete for X" checkbox is what reaches the other person. That
  made a plain confirm enough — type-to-confirm was protecting against a
  consequence this no longer has.
- Both entry points: a menu on the list row (reachable without opening the
  chat) and rows in the details panel.

### 1d — editing and deleting messages ✅ done

- Edit: author only, **2-hour window**, anchored on `created_at` so edits cannot
  be chained to extend it. Sets `edited_at`. Editing happens in the composer,
  not in the bubble — one control in this app accepts text.
- Delete for everyone: author any time, or a group admin. Sets `deleted_at`,
  **nulls `body`**, and leaves a tombstone — deliberately unlike Telegram, which
  removes the message entirely.
- Delete for me: a row in `message_user_deletions`.
- Consequence, as predicted: "the last message" stopped being a property of the
  conversation and became one of *(conversation, viewer)*. `latestMessage()` is
  gone; `ChatController::lastMessages()` resolves every row's preview in two
  queries. `unreadCounts()` and the thread query took the same treatment.
- 27 new tests, 116 green in total.

## Phase 2 — Realtime: Reverb + Echo ✅ done

Built after the logic was proven, which is what let the payload design be
decided by facts rather than guesses.

- Dependencies: `laravel/reverb` ^1.11, plus `@laravel/echo-react` and
  `pusher-js` via `bun add -d`. **Not** `laravel-echo` separately — the React
  package bundles Echo, and two copies mean two connections.
  - Reverb ≤1.11 pins `guzzlehttp/psr7 ^2.6`, so installing it downgraded
    guzzle 8.2 → 7.15. Laravel 13 supports `^7.8.2` and nothing here calls
    Guzzle directly, so this is a supported configuration, not a compromise.
- **Three channels**, not the two originally sketched. `presence-conversation`
  was dropped (whispers ride the private channel) and `user.{id}` was added,
  because a participant is only subscribed to the conversation they have
  *open* — without their own line, a badge for any other chat never moves.
- **Two events.** `MessageChanged` (full payload; sent, edited and deleted all
  answered by one idempotent upsert) and `ConversationTouched` (an id, and the
  client reloads). Read receipts ride the thin signal: a tick turning blue
  200 ms later than it could is imperceptible and saves an event class.
- `ShouldBroadcastNow`, so a missing queue worker cannot swallow broadcasts.
- Presence drives the green dot, which until now was hardcoded and told
  everyone that everyone else was offline.
- Typing whispers, throttled 2 s, expiring after 4 s — cleared on a timer
  because the explicit "stopped typing" is exactly what a closed laptop never
  sends.

### 2b — delivery receipts ✅ done

The ticks skipped a step: one grey (we have it) jumped straight to two green
(everyone read it), with no "it reached their device" in between.

- `conversation_user.last_delivered_message_id` — a second high-water pointer,
  because one pointer holds one boundary and this is a second one. Still one
  row per participant, so it is not the read-receipt table AGENTS.md rules out.
- `POST /delivered` — bodyless and bulk, acking every conversation at once.
  The sidebar payload already carries the newest message of each, so by the
  time a page has rendered the device genuinely holds all of them.
- **The loop that had to be designed out.** Acking moves a pointer, moving a
  pointer notifies everyone, everyone acks when notified. It terminates only
  because a pointer that cannot move announces nothing. Its own test.
- Delivered and read share a glyph and measured **1.003:1** against each other
  — indistinguishable once hue is removed. So read is drawn heavier as well as
  greener: one thin tick, two thin ticks, two heavy green ticks, all three
  legible in greyscale.

### Two traps this phase walked into, recorded so they are not walked into again

1. **The scaffolding's channel callback was an authorization bypass.**
   `install:broadcasting` writes `(int) $user->id === (int) $id`. On ULIDs both
   sides cast to `1`, so it authorized every user for every other user's
   channel. Correct for auto-increment keys; catastrophic here.
2. **A channel payload is the same for everyone; visibility is not.**
   Broadcasting an edit pushed the new text of a message past the per-viewer
   filter that was hiding it, so "delete for me" came undone the moment the
   author fixed a typo. Only brand-new messages may travel as payloads.
3. **Channel-auth tests pass vacuously by default.** `phpunit.xml` sets
   `BROADCAST_CONNECTION=null`, and `NullBroadcaster::auth()` is an empty
   method that answers 200 to everyone without ever opening
   `routes/channels.php`. `ChannelAuthTest` switches the driver *and*
   re-requires the channels file, because `Broadcast::channel()` registers
   against whichever driver is default at the moment it runs.

All three share one shape — code that compiles, connects and then quietly does
nothing. `tests/Feature/RealtimeContractTest.php` is the standing tripwire for
that class, including a cross-language check that the event names the client
listens for are names the server actually emits.

## Phase 2c — Data protection and abuse ✅ done

Prompted by a security review: authorization was solid, storage was bare.

- **Encrypted at rest**: `messages.body` and `conversations.name`. The trap was
  `conversations.name` being `varchar(255)` — a ten-character name encrypts to
  228, so a sixty-character one would have overflowed. Widened to `text` before
  a byte was written. The data migration probes each value first, so it is safe
  to run twice.
- **Throttle → suspend**: over 30 messages/minute earns a field error; three
  such occasions inside ten minutes earns 15 minutes, then 1 h, 6 h, 24 h.
  `suspended_until` is a timestamp because this app has no administrator to
  lift a flag. Read-only, never a lockout.
  - A strike counts *occasions*, not rejected requests. The first version
    scored one per message past the ceiling, so a single long paste collected
    ten strikes and was suspended for exactly the thing the ladder existed to
    forgive. Caught by the "single burst" test.
- **Consent to be added**: you can only add someone to a group if you already
  share a conversation with them. This replaced the reporting system as the
  answer to "people adding others to spam groups" — reports arrive after the
  harm, consent prevents it.

**Still open, by decision:** user reports and an admin panel to review them.
The owner wants both; deferred to their own discussion. Note that they need an
app-level admin role, which does not exist yet — and once it does, the
`suspended_until` design above could gain a manual lever.

## Phase 3 — Attachments

Multipart POST, mime/size validation, disk storage, `attachments` rows in the
same transaction as the message. The attach button is a stub until then.

**Design the security in, do not bolt it on.** This is the largest new attack
surface left: path traversal, mime validation, and access control on serving
the file. `storage/{path}` is already a registered route and carries no policy.
Message bodies are encrypted at rest; attachment contents would not be, which
is a gap worth deciding on deliberately rather than by omission.

## Phase 4 — Hardening and deploy

Supervisor for Reverb; `REVERB_*` vs `VITE_REVERB_*` (the documented failure #1);
`bun run build`; WSS behind nginx. Rate limit on message POST. A reconnecting
indicator — `--warn` amber already exists for it.

---

## Schema, and how it drifted from the original four tables

Everything below was approved explicitly:

- `message_user_deletions` (message_id, user_id) — the fifth table. Sparse: rows
  exist only where somebody actually hid a message, unlike the read-receipt
  table AGENTS.md warns against.
- **Every primary key is a ULID**, not an auto-increment integer — sequential
  keys let any signed-in user count the rows behind a URL. ULID rather than
  UUIDv4 because read state compares ids with `>` and `<=`; random keys would
  destroy it. Two rules follow: compare ids with `strcmp`, never `<`/`>`, and
  never do arithmetic on an id.
- `users.username` — unique, lowercase.
- `conversations.owner_id` — renamed from `created_by` and made nullable, because
  ownership is transferable and a direct chat has no owner.
- `conversations.admins_can_promote`, `conversations.members_can_add`.
- `conversation_user.role`, `conversation_user.cleared_up_to_message_id`.
- `conversation_user.hidden_at` — the only thing separating "empty this
  conversation" from "take it off my list", since both move the same pointer.

AGENTS.md has been brought in line: five tables, ULID keys, the role
columns, and the same-millisecond ordering caveat.
