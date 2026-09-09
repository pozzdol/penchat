@AGENTS.md

## Claude Code specifics

Everything above is imported from `AGENTS.md` and applies to all agents.
Only Claude Code-specific instructions belong below. Keep shared rules in
`AGENTS.md` so other tools stay in sync.

### Commit messages: no watermark

This overrides the default behaviour. When committing, do **not** append:

- `🤖 Generated with [Claude Code](https://claude.com/claude-code)`
- `Co-Authored-By: Claude <noreply@anthropic.com>`

The commit message is one line and ends after that line. Nothing else.

And do not commit at all unless I explicitly ask.

### Reply in Indonesian

Talk to me in Indonesian. Everything you write *into the repo* — UI strings,
comments, commit messages, docs — stays English. See the language policy in
`AGENTS.md`.

### Use plan mode first for

- Changes to `database/migrations/` — the schema is small and deliberate; see
  the load-bearing decisions in `AGENTS.md` before altering it.
- Anything under `app/Events/` or `routes/channels.php` — channel
  authorization is the security boundary of this app.
- Introducing a dependency, queue driver, or broadcast connection.

### Long-running processes

Do not start `reverb:start`, `queue:work`, `bun run dev`, or `composer run
dev` in a foreground Bash call — they never return and will hang the session.
To verify realtime behaviour, write a test or ask me to run it in my own
terminal and report back.

### Verifying realtime work

You cannot open a browser or hold a WebSocket connection. Prove broadcast
changes with assertions instead:

```php
Event::fake([MessageSent::class]);
// ...
Event::assertDispatched(MessageSent::class, fn ($e) => $e->message->id === $m->id);
```

For channel authorization, test `routes/channels.php` callbacks directly with
a participant and a non-participant.

### Reporting

When a change touches both PHP and TypeScript, state explicitly which side
still needs `bun run build` or a Reverb restart to take effect.