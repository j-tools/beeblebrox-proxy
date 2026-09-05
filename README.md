# beeblebrox-proxy

A Beeblebrox instance pushes work to a worker by posting a signed envelope to it. That only works if
the instance can reach the worker — and a laptop on an office network, or a machine behind a home
router, cannot be reached from outside.

This sits on a machine that **can** be reached, on the same network as the worker, and passes each
envelope on unchanged.

```
  the internet                      │  your own network
                                    │
  zaphod.beeblebrox.cloud           │   the web server you already have    the laptop
  ┌────────────────────┐            │   ┌──────────────────────┐          ┌──────────────────┐
  │ your instance      │ ── POST ──────▶│ this                 │ ── POST ▶│ beeblebrox-local │
  │ (the dispatcher)   │◀── answer ─────│ (a port forward or a │◀─ answer │ (the worker)     │
  └────────────────────┘            │   │  DNS record aims     │          │ not reachable    │
                                    │   │  here)               │          │ from outside     │
                                    │   └──────────────────────┘          └──────────────────┘
```

It is not a hosted thing and there is no `proxy.beeblebrox.cloud`: it goes on whichever box you
already have facing the internet, at whatever that box is called, in its own vhost or a directory
under a site it already serves.

The alternative is polling, which the worker already does and which needs no inbound networking at
all. Use this when you want work pushed — because a poll interval is a delay on every task, and a
push is not.

## What it does, and what it deliberately does not

**It passes the envelope through byte for byte.** The signature covers the exact bytes of the body
together with the timestamp header, so anything that decoded and re-encoded the JSON on the way
would make a valid envelope arrive invalid — and the symptom at the far end reads like a wrong
secret. Nothing here parses the body on the path it travels. Every `X-Beeblebrox-*` header goes out
spelled the way it came in, including ones this version has never heard of.

**It gives back the worker's own answer.** Status and body, relayed. The dispatcher upstream decides
whether to retry from that, so inventing an answer here would break a decision made two machines
away.

**It does not queue and it does not retry.** The dispatcher already retries. A proxy that accepted an
envelope the worker never saw would be telling the instance the work had been handed over when it had
not — which is worse than a 502, because a 502 gets retried and a lie does not. If the worker is off,
the instance is told so and does what it would have done anyway.

**It holds no key.** The envelope names a task and never carries the work; the worker fetches the
briefing itself with its own API key. So nothing that passes through here is worth reading, and this
machine — the one deliberately made reachable — is the one with nothing on it.

**It needs no database server.** The whole store is one SQLite file that makes itself on the first
request: eight settings, a password hash, a session and an append-only log. Nothing relational,
nothing transactional, a handful of rows a day. Standing up a database server for that would be more
work than the thing it is forwarding.

**One proxy, one worker.** An envelope names a task, not a machine, so there is nothing in it for a
proxy to route on. Two workers behind the same router means two of these, on two addresses.

## What it checks before anything reaches your network

| | |
|---|---|
| The instance | Every envelope says which instance sent it. One that names anybody else is refused here, which is what makes this address no use to whoever finds it. |
| The address | An optional allow list. Worth filling in here rather than on the worker, which sees every envelope arriving from this machine and learns nothing from that. |
| The signature | Optional, and off unless you store the signing secret. The worker checks it regardless — it has to, since it is the one that acts on the envelope — so this is a second lock, not the only one. |

Storing the secret here buys one thing: a forged envelope is refused on this machine instead of being
carried onto the network this exists to keep closed. It costs a second copy of the secret and a
second clock that can drift out of tolerance. Both are reasonable answers; the default is the one
that needs no setup.

## Setting it up

Two questions: **which Beeblebrox**, and **where the worker is**. The wizard checks both as they are
entered — the instance by asking it, and the worker by asking its `hook.php` for a page and expecting
to be told off for not posting, which is a Beeblebrox receiver identifying itself rather than merely
something being alive on a port.

Then, on the instance, point the role's webhook dispatcher at this proxy's `/hook.php` instead of at
the worker. Nothing else about the dispatcher changes — same signing secret, same envelope.

`INSTALL.md` has the whole thing.

## Running it

Nothing runs on a schedule. There is no runner, no queue and no cron entry: a request arrives, it is
forwarded, it is over. A web server serving this directory is the entire installation.

## Layout

```
hook.php          the receiver, and the only page that matters
index.php         landing page signed out, dashboard signed in
setup.php         the two questions
settings.php      all of them, plus the optional locks
deliveries.php    every envelope that has arrived
delivery.php      one of them in full — what arrived, what came back
diagnostics.php   every check, plus what to give the dispatcher
lib/              settings, security, delivery, the log, layout, the version check
db/schema.sql     three tables; the application creates them itself
data/             the SQLite file lives here, and must not be served
tools/            migrate, selftest, password
tests/            smoke (no server), hook (against a running one)
```

## Tests

```bash
timeout 60 php tests/smoke.php
```

Parsing and verdicts, no server and no database. The mistakes a proxy makes live here: an address
normalized one way in the wizard and another on the settings page, a header name that goes out
subtly different from the one the signature was computed over.

```bash
timeout 180 php tests/hook.php http://127.0.0.1:8776
```

Real HTTP against a running copy. Every request lands in its delivery log and the last one reaches
the worker, so run it against the copy you are working on. All the envelopes are connection tests —
event `test`, task id 0, which no real task ever is — so a worker answers them and queues nothing.

```bash
timeout 120 php tools/selftest.php
```

The same checks as the diagnostics page, from a terminal. Worth running as well as opening the page:
this reaches the worker from a shell, and the page reaches it as the web server's account through
whatever network the web server happens to be on.
