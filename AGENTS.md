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

Composer still manages PHP; Bun does not replace it. The Laravel starter
kit's `composer run dev` script invokes `npm run dev` by default — patch that
script to `bun run dev` rather than working around it.

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

Semantic versioning, annotated tags, prefix `v`. Stay on `0.x.y` until the
schema and broadcast payloads are stable; while pre-1.0 a breaking change
bumps **minor**, not major. Tag only when asked; the tag message follows the
same one-line, no-watermark rule as commits.

```bash
git tag -a v0.3.0 -m "feat: read receipts and presence"
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

The WebSocket is for *receiving* only. Writes always go through normal HTTP
so validation, authorization, and DB transactions stay in controllers.

Typing indicators and presence must never write to the database or dispatch
a queued job.

---

## Data model

Four tables. Do not add more without asking.

```
conversations       id, type ('direct'|'group'), name?, direct_key? (unique), created_by
conversation_user   conversation_id, user_id, last_read_message_id?, joined_at   # pivot
messages            id, conversation_id, user_id, body?, created_at
attachments         id, message_id, path, original_name, mime, size
```

### Two load-bearing decisions - do not undo

**1. `direct_key`, not a participant-matching query.**
For `type = 'direct'`, `direct_key` is the two user IDs sorted ascending and
joined: `min-max` (e.g. `3-17`). Unique index on it. This makes "find or
create the DM between A and B" a single upsert and lets the database resolve
the race when both users open the chat simultaneously. Do not replace it with
a "conversation having exactly these two participants" subquery.

**2. Read state is a pointer, not a join table.**
`conversation_user.last_read_message_id` is a high-water mark. A message is
read by a participant when `message.id <= last_read_message_id`. Do not
introduce a `message_reads` table - it grows with messages x participants
and buys nothing at this scale.

**3. A direct chat is a group with two participants.**
Build every feature group-first. A DM is `type = 'direct'` with two pivot
rows - not a separate model, controller, or channel type. Only *presentation*
differs (title, and "Read" vs "Read 3/5").

---

## Backend conventions

- Every conversation route authorizes participation via `ConversationPolicy`.
- `routes/channels.php` must verify pivot membership for `conversation.{id}`
  and `presence-conversation.{id}`. This is the only thing stopping users
  from reading other people's chats - never return `true` unconditionally.
- Broadcast events implement `ShouldBroadcast` with an explicit
  `broadcastWith()` payload. Never broadcast a whole Eloquent model - it
  leaks columns and couples the wire format to the schema.
- Use `broadcast(...)->toOthers()` when the sender already rendered locally.
- Form Requests for validation. Controllers stay thin.
- Wrap message-plus-attachment creation in a DB transaction.

## Frontend conventions

- Inertia pages in `resources/js/pages`, components in `components/`.
- TypeScript. Shared shapes (`Message`, `Conversation`, `Participant`) live in
  `resources/js/types` and must match `broadcastWith()` payloads.
- Echo subscription lifecycle belongs in a `useEffect` with a cleanup that
  calls `leaveChannel`. Leaking subscriptions on conversation switch causes
  duplicate messages - the most common bug in this codebase.
- De-duplicate by message `id` when appending from a broadcast; a sender may
  receive its own message through both the HTTP response and the socket.
- Debounce typing `whisper` calls (~2-3s) and clear the remote indicator on a
  timeout, not only on an explicit "stopped typing" event.

---

## Deployment and environment

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
- All user-facing strings are in English
- No new dependency added without asking; if added, via `bun add`
- Nothing committed unless explicitly requested