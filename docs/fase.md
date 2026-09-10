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

### 1b — groups, roles, members panel (next)

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

### 1c — leaving and clearing

- `DELETE …/history` — sets `cleared_up_to_message_id` **and**
  `last_read_message_id`. Setting both is what stops a badge you cannot clear.
  The conversation leaves the list and returns, showing only new messages, when
  someone next writes. Telegram's behaviour.
- `DELETE …/membership` — detaches the pivot row; history survives for everyone
  else; runs the succession rule above.
- `DELETE …/{conversation}` — **direct only**, wipes it for both sides.
  Irreversible and triggerable by one party, so it needs type-to-confirm.

### 1d — editing and deleting messages

- Edit: author only, **2-hour window**, anchored on `created_at` so edits cannot
  be chained to extend it. Sets `edited_at`.
- Delete for everyone: author any time, or a group admin. Sets `deleted_at` and
  leaves a **tombstone** — deliberately unlike Telegram, which removes the
  message entirely. A message vanishing mid-conversation reads as a bug.
- Delete for me: a row in `message_user_deletions`.
- Consequence: "the last message" stops being a property of the conversation and
  becomes one of *(conversation, viewer)*. `latestMessage()` cannot survive this;
  the sidebar needs a per-viewer query instead.

## Phase 2 — Realtime: Reverb + Echo

Deliberately after the logic is proven. **Nothing here has started**:
`BROADCAST_CONNECTION` is still `log`, there is no `routes/channels.php`, and
none of the three packages is installed.

- New dependencies, **owner's permission required**: `laravel/reverb`, then
  `laravel-echo` + `pusher-js` via `bun add`.
- `routes/channels.php` verifying pivot membership for `conversation.{id}` and
  `presence-conversation.{id}`. **Plan mode** — this is the security boundary.
- `MessageSent`, `MessageRead` — `ShouldBroadcast`, explicit `broadcastWith()`,
  never a whole model, `toOthers()`.
- Client: subscribe per conversation in a `useEffect` whose cleanup calls
  `leaveChannel`. De-duplicate by message `id`.
- Presence channel → the green dot. Whisper → typing, debounced 2–3s, cleared on
  a timeout rather than only on an explicit stop.
- Socket behaviour has to be verified in the owner's own terminal; an agent
  cannot hold a WebSocket open.

## Phase 3 — Attachments

Multipart POST, mime/size validation, disk storage, `attachments` rows in the
same transaction as the message. The attach button is a stub until then.

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

AGENTS.md has been brought in line: five tables, ULID keys, the role
columns, and the same-millisecond ordering caveat.
