# AGENTS.md

Realtime chat app. Laravel + Inertia + React + Laravel Reverb (WebSocket).
Target scale: **5 concurrent users**. Deploy: single self-managed VPS.

This file is the canonical instruction set for all coding agents.
`CLAUDE.md` imports it; do not duplicate content there.

---

## Scale constraints (read this first)

This app serves **five users**. Most chat-app "best practices" assume
thousands. Do not introduce, and push back on:

- Redis pub/sub for broadcast fan-out (single Reverb process is sufficient)
- Horizontal scaling, multiple Reverb nodes, sticky sessions
- Message pagination beyond simple cursor/`limit` on initial load
- Read-receipt tables with one row per (message x reader)
- Caching layers, read replicas, search indexes
- Optimistic-UI reconciliation frameworks

If a change is justified only by scale, it does not belong here. Say so
instead of building it.

---

## Toolchain: Bun only

**Bun is the only JavaScript runtime and package manager for this repo.**
Never run `npm`, `npx`, `yarn`, or `pnpm`.

```bash
bun install               # not npm install
bun add <pkg>             # not npm install <pkg>
bun add -d <pkg>          # dev dependency
bun run dev               # Vite dev server
bun run build             # production assets
bunx <tool>               # not npx
```

Only `bun.lock` is committed. If `package-lock.json` or `yarn.lock` ever
appears, delete it — a second lockfile silently splits the dependency tree.

Composer still manages PHP; Bun does not replace it. `composer run dev` needs
no patching: Laravel 13 picks the package manager from the lockfile
(`NodePackageManagers/Bun.php` looks for `bun.lock`, and Bun is tried first),
so `php artisan dev` already spawns `bun run dev`. Keeping `bun.lock` as the
only lockfile is what keeps that true.

---

## Language policy

| Layer                                    | Language   |
| ---------------------------------------- | ---------- |
| UI strings (labels, buttons, errors)     | English    |
| Code identifiers, comments, docs         | English    |
| Commit and tag messages                  | English    |
| Seeder / factory sample data             | English    |
| Agent's replies to the developer in chat | Indonesian |

The conversation with the developer happens in Indonesian, but **that must
never leak into the product**. Do not write Indonesian button labels,
validation messages, placeholders, or empty-state copy just because the chat
is in Indonesian. This is the expected failure mode — guard against it.

There is no i18n layer. English strings are hardcoded; do not add one.

---

## Git conventions

**Never commit unless explicitly asked.** Finishing a task is not a request
to commit. Do not stage, commit, push, tag, or branch on your own initiative.

When asked to commit:

- **One line. No body.** No bullet lists, no "Changes:" section, no trailing
  paragraph.
- **Conventional Commits** prefix: `feat:` `fix:` `docs:` `refactor:`
  `chore:` `test:` `style:` `perf:` `build:` `ci:`
- Optional scope: `feat(chat): ...`, `fix(reverb): ...`
- Imperative mood, lowercase after the colon, no trailing period, aim for
  under 72 characters.
- **No watermark of any kind.** No `Co-Authored-By:` trailer, no "Generated
  with Claude Code", no attribution line, no emoji.

```
feat(chat): add typing indicator via whisper
fix(reverb): correct channel authorization for direct conversations
docs: document bun-only toolchain
```

### Version tags

**Every commit gets an annotated tag**, prefix `v`, starting from `v0.0.0`.
The tag message repeats the commit subject and follows the same one-line,
no-watermark rule.

Positions are defined by the *kind of work*, not by what breaks. This app is
deployed, not installed — nothing depends on its API, so "will this break a
consumer" has no reader, while "what sort of change was this" is exactly what
someone choosing a rollback target needs.

| Position | Bumps when |
| -------- | ---------- |
| `0.0.x`  | a small change: a fix, a polish, a doc |
| `0.x.0`  | a feature, or a phase from `docs/fase.md` |
| `x.0.0`  | rework, or a change to the core or the framework |

**The first position is held at 0 until the app is meant to run in
production.** Six migrations landed in two days; the schema and the broadcast
payloads still move every phase, and a 1.0.0 would claim a stability that does
not exist. `v1.0.0` marks the first release actually intended to be deployed.
After that, it bumps on rework and core or framework changes as above.

Without that gate the rule eats itself: in an app this young almost everything
touches the core, so a literal reading reaches 2.0.0 in a week and the number
stops meaning anything.

```bash
git tag -a v0.3.0 -m "feat: add realtime, receipts, encryption and abuse limits"
```

---

## Commands

```bash
composer install && bun install
php artisan migrate
composer run dev          # serve + vite + queue + reverb concurrently
php artisan test          # Pest
./vendor/bin/pint         # PHP formatter - run before finishing PHP work
bun run build             # production assets
php artisan push:vapid    # Web Push keys - once, ever; see the push section
```

Never run `php artisan reverb:start` or `bun run dev` as a blocking
foreground command in a tool call - they never exit. Use `composer run dev`
locally, or Supervisor in production.

---

## Transport routing - the core architectural rule

Not every interaction goes over the WebSocket. Follow this table exactly:

| Concern          | Transport                    | Persisted |
| ---------------- | ---------------------------- | --------- |
| Send message     | HTTP POST -> broadcast event | yes       |
| Load history     | Inertia page props           | -         |
| Receive message  | private channel subscription | -         |
| Online status    | presence channel             | **no**    |
| Typing indicator | client event (`whisper`)     | **no**    |
| Mark as read     | HTTP PATCH -> broadcast      | yes       |
| File upload      | HTTP POST (multipart)        | yes       |
| Notify when away | Web Push, after the response | yes       |

The WebSocket is for *receiving* only. Writes always go through normal HTTP
so validation, authorization, and DB transactions stay in controllers.

Typing indicators and presence must never write to the database or dispatch
a queued job.

---

## Data model

**Six tables.** Do not add more without asking.

```
users               id (ULID), name, username (unique, lowercase), email (unique),
                    email_verified_at?, password? (always null - sign-in is a code)
conversations       id (ULID), type ('direct'|'group'), name?, direct_key? (unique),
                    owner_id? (null on a direct chat, transferable on a group),
                    admins_can_promote, members_can_add
conversation_user   conversation_id, user_id, role ('admin'|'member'),          # pivot
                    last_read_message_id?, last_delivered_message_id?,
                    cleared_up_to_message_id?, hidden_at?, joined_at
messages            id (ULID), conversation_id, user_id, body?, created_at,
                    edited_at?, deleted_at?
attachments         id (ULID), message_id, path, original_name, mime, size
message_user_deletions  message_id, user_id                                     # the fifth
push_subscriptions  id (ULID), user_id, endpoint (unique), public_key, auth_token,
                    user_agent?, last_used_at?                                  # the sixth
```

### Load-bearing decisions - do not undo

**1. Keys are ULIDs, and the "L" is the point.**
Auto-increment integers let any signed-in user count the rows behind a URL.
ULID - **not** UUIDv4 - because decision 2 below compares ids with `>` and
`<=`, so the keys have to sort by time. UUIDv4 is random and would silently
destroy read state.

Two consequences that bite:

- **Never compare ids with PHP's `<`/`>`.** PHP compares two numeric-looking
  strings numerically, and an all-digit ULID would order wrongly. Use
  `strcmp()`. SQL is unaffected - Postgres compares `char` lexicographically.
- **Never do arithmetic on an id.** A pointer is always some real message's id.
- Ordering is exact within one PHP process (`Ulid::generate()` increments its
  random block), but two messages written by different workers in the same
  millisecond can order arbitrarily. Accepted: at five users it does not
  happen, and the true order of two same-millisecond messages is ambiguous
  anyway.

**2. `direct_key`, not a participant-matching query.**
For `type = 'direct'`, `direct_key` is the two user ULIDs ordered by `strcmp`
and joined with a hyphen. Unique index on it. This makes "find or create the
DM between A and B" a single race-safe upsert and lets the database resolve
the race when both users open the chat simultaneously. Do not replace it with
a "conversation having exactly these two participants" subquery.

**3. Read state is a pointer, not a join table.**
`conversation_user.last_read_message_id` is a high-water mark. A message is
read by a participant when `message.id <= last_read_message_id`. Do not
introduce a `message_reads` table - it grows with messages x participants
and buys nothing at this scale. `cleared_up_to_message_id` is the same shape
for "hidden from my copy of this conversation".

A pointer expresses exactly one boundary, which is why the ticks need two.
`last_delivered_message_id` is "it reached their device", the second grey
tick; `last_read_message_id` is "they opened it", the green one. Both take
the *lowest* value among the other participants, so a group only advances at
the pace of whoever is furthest behind. **Delivered must never lag read** -
you cannot read what never arrived - so everything that advances the read
pointer advances this one with it.

**4. A direct chat is a group with two participants.**
Build every feature group-first. A DM is `type = 'direct'` with two pivot
rows - not a separate model, controller, or channel type. Only *presentation*
differs (title, and "Read" vs "Read 3/5"). Roles are ignored entirely on a
direct chat, and every management ability refuses outright there.

**5. `deleted_at` on a message is a tombstone, not a soft delete.**
The row survives and the thread still shows that something stood there — a
message vanishing mid-conversation reads as a bug. The *text* does not survive:
deleting nulls `body`, so "deleted" is not merely a flag the interface honours.
Never add the `SoftDeletes` trait to `Message`; it would hide the row from every
query and take the tombstone with it.

**6. `hidden_at` is what separates Clear history from Delete chat.**
Both move `cleared_up_to_message_id`, so without a second marker they are one
operation. Hidden takes the row off that participant's list until something
arrives they have not already dismissed — visibility is *derived* from
(`hidden_at` set) and (no visible message), never stored. Clearing history nulls
it, which is what stops the two actions leaving each other in a contradictory
state. Deleting is not leaving: the pivot row stays, which is how the
conversation can come back rather than having to be created again.

**7. `message_user_deletions` is sparse, and must stay that way.**
It exists because "delete for me" is per (message, reader) and cannot live on
the message row. It earns its place only while rows appear solely where
somebody actually hid something - unlike a read-receipt table, which would get
a row per message per reader.

---

## Data protection

- **`messages.body` and `conversations.name` are encrypted at rest.** Nothing
  queries either in SQL — search runs client-side — so they may stay opaque to
  Postgres. Two consequences to keep in mind rather than rediscover:
  - This hides *what* was said, not *who* said it to whom or *when*.
    `direct_key` is a unique index over two user ids and must stay searchable,
    so the social graph survives a leak.
  - **Losing `APP_KEY` destroys every message, permanently.** It needs a
    backup somewhere the database backup is not.
  - Encrypted output is far longer than the plaintext — a ten-character group
    name becomes 228 characters. Any column that will hold ciphertext is
    `text`, never `varchar`.
- **`users.suspended_until` is a timestamp, never a boolean.** There is no
  app-level administrator here (`ConversationRole` is per-group only), so a
  permanent flag would have nobody able to lift it. Expiry is the escape
  hatch. Do not replace it with a flag unless an admin exists first.
- **A suspension is read-only, not a lockout.** Reading, marking read,
  delivery acks, clearing, deleting your own copy and leaving all stay open —
  freezing an ack would stall everyone else's ticks as a side effect of
  punishing one person.
- **A strike counts occasions, not rejected requests.** One long paste must
  never climb the ladder; only hitting the ceiling on separate occasions does.
  `app/Support/SpamGuard.php` holds every threshold, so they are arguable in
  one place.
- **You can only add someone to a group if you already share a conversation
  with them.** Consent, not punishment: a stranger has to reach you somewhere
  you can ignore them before they can put you anywhere. Enforced at the HTTP
  boundary; `Conversation::createGroup()` stays an unguarded primitive so
  seeders and tests are unaffected.

## Replies

- The client sends an **id**; the server copies the words. That makes an
  unchecked id a way to have the server read a message out of a conversation
  the sender cannot see and paste it into one they can, so
  `MessageController::quotable()` requires the target to be in the same
  conversation, above the sender's cleared pointer, and not one they hid —
  the same three conditions that decide whether they could have read it.
- `reply_to_body` is a **snapshot**, and `reply_to_body` is encrypted for the
  same reason `body` is. Editing an original must not rewrite the words inside
  somebody else's message after the fact.
- The author is not snapshotted. A message row is never removed — deleting
  leaves a tombstone — so the original is always there to join for a name.
- Quoting a message someone cleared or hid puts that text back on their
  screen. That is **correct**: the quoter chose to repeat it, and it is their
  new message. It looks like the Phase 2 leak and is not one.

## Push notifications

Web Push (VAPID), self-hosted. No FCM, no OneSignal — the payload would then
pass through a third party in an app that encrypts `messages.body` at rest,
and FCM's web support *is* this same standard with Google holding the keys, so
it would buy nothing.

- **Two halves, deliberately split.** `App\Support\PushNotifier` decides who
  hears about a message and writes the line they read;
  `App\Support\WebPushSender` signs and delivers it. A native app later brings
  a different transport (APNs, FCM) but the same recipients and the same
  sentence — the split is what stops the second one being rewritten with the
  first. There is deliberately **no driver abstraction** until a second
  transport actually exists.
- **New messages only**, and for the same reason `MessageSent` carries the only
  payload on the wire. A push is one payload for one device with no server left
  in the loop, so an edit or a tombstone would walk straight past
  `cleared_up_to_message_id` and `message_user_deletions`. Route everything else
  through `ConversationTouched` as before.
- **`afterResponse()`, never the queue.** `dispatch(fn () => ...)->afterResponse()`
  runs in the same process once the response has left, so nothing has to be
  running for it to happen — a queued push would be swallowed in silence
  exactly as a queued sign-in code would.
- **The server never decides who is online.** Presence is not persisted, so it
  cannot. `public/sw.js` suppresses a notification when a *focused* window is
  already on that conversation, and only then: browsers waive the
  "a push must show something" rule when the app has a visible window, and
  punish a silent push otherwise.
- **`public/sw.js` is a static file, outside Vite.** A worker's scope is the
  directory it is served from, so it needs a stable root URL; a hashed filename
  would break the registration on every deploy.
- **The payload's fields and the worker's reads must match exactly**, both ways.
  `tests/Feature/RealtimeContractTest.php` asserts it — a field nobody reads is
  weight inside a payload capped at a few kilobytes, and a field nobody sends is
  `undefined` on someone's lock screen.
- **The VAPID public key travels as an Inertia shared prop, not `VITE_`.**
  Compiled into the bundle it would need `bun run build` after every rotation.
- **`php artisan push:vapid` is run once, ever.** New keys silently invalidate
  every stored subscription. Back the private key up where `APP_KEY` is backed
  up.
- **iOS only delivers to an installed PWA** (16.4+), hence
  `public/manifest.webmanifest`, the PNG icons, and the Add to Home Screen hint
  — Safari will not push to a page in a tab, and says so nowhere.

## Backend conventions

- Every conversation route authorizes participation via `ConversationPolicy`.
- `routes/channels.php` is the security boundary. Never return `true`
  unconditionally, and **never cast an id**: the scaffolding ships
  `(int) $user->id === (int) $id`, which is right for auto-increment keys and
  catastrophic for ULIDs — `(int) '01m24h96...'` is 1, and so is every other
  ULID, so the check passes for everyone. Compare strings with `===`.

  | Channel | Carries | Authorized by |
  |---|---|---|
  | `private-conversation.{id}` | `message.changed`, typing whispers | pivot membership |
  | `private-user.{id}` | `conversation.touched` | `$user->id === $id` |
  | `presence-online` | the online roster | any signed-in user |

  There is no `presence-conversation.{id}`: whispers ride the private
  conversation channel, which verifies the same membership and halves the
  subscriptions per open chat.

- **Two events, and that is the design.** `MessageSent` carries a full
  `MessageResource` payload — **new messages only**. `ConversationTouched`
  carries a conversation id and nothing else, and the client answers it with
  a partial reload.
- **Never broadcast an edit or a tombstone as a payload.** A channel payload
  is identical for every subscriber, but visibility is not:
  `cleared_up_to_message_id` and `message_user_deletions` are per-viewer, so
  broadcasting an edit pushes the new text straight past the filter that was
  hiding it — someone who chose "delete for me" watches the message reappear
  the moment its author fixes a typo. A *newly created* message cannot be
  caught by either filter, which is the whole reason that one payload is safe.
  Everything else takes the round-trip and lets the server decide.
- `unread_count`, list order, the sidebar preview and `delivery` are all
  per-viewer, so no single payload could be true for everyone on a channel.
  The reload is not a shortcut; it is the only honest answer.
- Both use `ShouldBroadcastNow`. `QUEUE_CONNECTION` is `database` and no worker
  runs in development, so `ShouldBroadcast` would swallow every broadcast in
  silence.
- Give every event a `broadcastAs()`, so the wire format does not depend on a
  PHP namespace.
- Never broadcast a whole Eloquent model - it leaks columns and couples the
  wire format to the schema.
- Use `broadcast(...)->toOthers()` when the sender already rendered locally,
  and add `InteractsWithSockets` to the event — without the trait the call is
  silently inert. The client sends `X-Socket-Id` from Inertia's `before` hook,
  because Inertia's axios instance is not the one Echo patches.
- Form Requests for validation. Controllers stay thin.
- Wrap message-plus-attachment creation in a DB transaction.

## Frontend conventions

- Inertia pages in `resources/js/pages`, components in `components/`.
- **Below `md` there is one pane, and two rules follow from it.** The rail is
  not narrowed on a phone, it is *moved* — `FloatingNav` in `rail.tsx`, shown
  only over the list, so a conversation gets the whole screen. And row
  selection is a two-pane idea: every selected style in the conversation list
  is `md:`-prefixed, because a highlighted row on a phone points at something
  that is not on screen.
- **`/` opens nothing.** `ChatController` used to fall back to the first
  conversation so the third pane was never blank, and the props then claimed a
  room was open when nobody had opened one: mobile could not reach the list,
  Back did nothing, and Close room reopened what it closed. `ThreadEmpty` is
  the answer to an empty third pane. Do not reinstate the fallback.
- Menu items are defined once as data and rendered by both the hover dropdown
  and the right-click `ContextMenu`. Writing the items twice is how the two
  drift apart. Taking over the browser's context menu also means replacing
  what it offered — hence `Copy text`.
- TypeScript. Shared shapes (`Message`, `Conversation`, `Participant`) live in
  `resources/js/types` and must match `broadcastWith()` payloads.
- Subscriptions live in `resources/js/hooks/use-realtime.ts` and go through
  `@laravel/echo-react`'s hooks, which unsubscribe on unmount and on channel
  change. That is deliberate: leaking a subscription on conversation switch is
  the duplicate-message bug this codebase is most prone to, and the hooks make
  it structurally impossible rather than a thing to remember.
- **Do not install `laravel-echo` alongside `@laravel/echo-react`.** The React
  package bundles Echo (`deps: 0`); two copies mean two socket connections and
  every event delivered twice.
- Merge socket arrivals by `id`, and let the server's copy win — only it knows
  this viewer's `delivery`. A sender can receive its own message through both
  the HTTP response and the socket.
- **A pointer that did not move announces nothing.** Delivery acks are what
  make this load-bearing rather than tidy: acking moves a pointer, moving a
  pointer notifies everyone, and everyone acks when notified. The exchange
  terminates only because a settled pointer is silent.
- Whispers are `client-` prefixed. Listen for `.client-typing`, not `.typing`;
  the bare name compiles, connects, and never fires.
- Debounce typing `whisper` calls (~2-3s) and clear the remote indicator on a
  timeout, not only on an explicit "stopped typing" event.

---

## Mail

Sign-in codes go out through **Resend**. The transport ships with Laravel; the
only dependency is `resend/resend-php`, which supplies the `Resend` class that
`MailManager::createResendTransport()` looks for. Do not add
`resend/resend-laravel` — it registers the same transport again and the rest of
it is audiences and contacts this app has no use for.

Two things must be true or nothing arrives, and neither fails loudly on its
own: `RESEND_API_KEY` is set, and the domain in `MAIL_FROM_ADDRESS` is verified
in Resend with its DNS records added. An unverified domain is rejected. The
shared `onboarding@resend.dev` sender needs no DNS but can only send to the
address that owns the Resend account, which makes it a test tool, not a
fallback.

Sending is **synchronous, inside the sign-in request**. That is the choice:
`QUEUE_CONNECTION` is `database` and no worker runs outside `composer run dev`,
so a queued code would silently never leave. `LoginCode::send()` already
catches everything, burns the code, logs the detail server-side, and shows
"We could not send the code right now" under the field — a Resend outage is a
visible refusal rather than a code that never comes.

`phpunit.xml` pins `MAIL_MAILER=array`, so tests never reach the network. Keep
it that way.

## Deployment and environment

**`trustProxies` is load-bearing, not hygiene.** Cloudflare Tunnel terminates
TLS and `cloudflared` reaches nginx over loopback, so without the entry in
`bootstrap/app.php` every request reports `127.0.0.1` as its client address.
Redirects and the session cookie are the visible half; the quiet half is that
every per-IP rate limit — sign-in codes, verification guesses, username
lookups — collapses into one global bucket shared by the entire internet, and
nothing anywhere raises an error about it.

Reverb needs two distinct sets of env values - server-side (`REVERB_*`) and
browser-side (`VITE_REVERB_*`). Mixing them up produces "works locally, dead
in production", the most common failure here. Any `VITE_*` change requires
`bun run build`; it is compiled into the bundle, not read at runtime.

---

## Definition of done

- `php artisan test` passes
- `./vendor/bin/pint` applied to touched PHP
- New broadcast events have a matching channel authorization rule
- New Echo subscriptions have cleanup
- New realtime work keeps `tests/Feature/RealtimeContractTest.php` green — it
  is the tripwire for the failure mode this layer keeps producing: code that
  compiles, connects, and then silently does nothing
- All user-facing strings are in English
- No new dependency added without asking; if added, via `bun add`
- Nothing committed unless explicitly requested