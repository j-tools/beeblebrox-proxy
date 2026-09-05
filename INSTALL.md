# Installing beeblebrox-proxy

This goes on a machine with two properties: your Beeblebrox instance can reach it, and it can reach
the worker.

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

It needs **PHP 7.3 or newer** with `curl`, `openssl`, `mbstring` and `pdo_sqlite` — all four are in a
default PHP build. There is no database server, no scheduled task, no queue and no worker process.

7.3 rather than something newer on purpose: this goes on whichever box you already have facing the
internet, and those are frequently not new. A Debian 10 or 11 web server is PHP 7.3 or 7.4, and
there is nothing in a webhook relay that needs anything younger. The two PHP 8 functions this used
to depend on are polyfilled in `lib/preflight.php`.

7.3 is where it stops, and the reason is one line of `lib/session.php`: `setcookie()` takes an
options array from 7.3, which is how the `SameSite` attribute gets set. Going lower would mean
smuggling that through the path argument as a string hack, and a cookie attribute that keeps this
application's session out of other sites' requests is not worth dropping for a PHP that has been
end-of-life since 2019.

If something is missing it says so in plain words on the first page you open, rather than failing at
the first call to a function it does not have. Most hosting panels let you pick the PHP version per
site, and that is usually the whole fix.

If you are wondering whether you need this at all: you do not, if the worker is happy polling. The
worker asks the instance for work on its own and needs nothing inbound. This is for when you want
work **pushed**, because a poll interval is a delay on every single task.

---

## 1. Put the files somewhere

```bash
git clone https://github.com/j-tools/beeblebrox-proxy.git
cd beeblebrox-proxy
```

`main` is the released branch, `beta` is where changes land first.

This can be its own vhost or a directory under a site you already serve — it is deliberately
undemanding about that, because it has to go on whichever box you already have facing the internet,
and that box usually has a job already.

## 2. Storage: nothing to do

The whole store is one SQLite file, and it creates itself on the first request. There is no database
server to install, no user to create, no grant to get right and no schema to load.

The only thing to get right is that the web server can **write** to `data/`, and that nobody can
**read** it over the web. This matters more than it sounds: the file holds session ids, so serving it
is handing whoever downloads it a signed-in session on this machine.

`data/.htaccess` denies it **on Apache, and only if `AllowOverride` permits it**. Nothing else honors
that file — nginx does not, and neither does `php -S`, which will happily serve the database to
anyone who asks. So:

- **Apache**: the shipped `.htaccess`, or the `DirectoryMatch` in the vhost below. Either is enough;
  having both is fine.
- **nginx**:
  ```nginx
  location ~ ^/(data|lib|db|tools|tests)/ { deny all; }
  ```
- **Anything else, or if you would simply rather not depend on it** — put the file outside the
  directory being served, which is the one answer that cannot be got wrong by a config change later:
  ```php
  'db_file' => '/var/lib/beeblebrox-proxy/proxy.sqlite',
  ```

Do not take any of this on trust. The diagnostics page asks for the file over the web from the
outside and **fails** if it comes back, naming the URL it got it from.

Backing it up is copying that one file. Losing it costs you the delivery history and the settings —
two questions' worth of setup, and nothing that cannot be typed again.

## 3. Configure it

```bash
cp config.local.example.php config.local.php
```

One value has to be right: `site_url`, **the address your instance will call**, written exactly as it
will be called — scheme, host, and subdirectory if there is one. That value is not decoration:

- it is the URL printed on the settings and diagnostics pages as the one to give the dispatcher;
- the session cookie for these pages takes its `Secure` flag and its path from it, and never from the
  request, because a forged `Host` header must not be able to turn that off on the one machine here
  that is reachable from outside;
- the cookie path is what keeps this from colliding with whatever else you serve from that host.

`secret_key` can stay empty. It is only needed if you later decide to have this proxy check
signatures itself, which is optional — see step 7.

## 4. Serve it

An Apache vhost, if it gets its own:

```apache
<VirtualHost *:443>
  ServerName proxy.example.com
  DocumentRoot /srv/beeblebrox-proxy

  <Directory /srv/beeblebrox-proxy>
    Require all granted
    AllowOverride None
    Options -Indexes
  </Directory>

  # config.local.php holds nothing but a URL and maybe a key, but the data directory holds sessions.
  <FilesMatch "^(config\.local\.php)$">
    Require all denied
  </FilesMatch>
  <DirectoryMatch "/(lib|db|data|tools|tests)/">
    Require all denied
  </DirectoryMatch>

  SSLEngine on
  # ... your certificate
</VirtualHost>
```

Note `AllowOverride None` above means the shipped `data/.htaccess` is **ignored** — the
`DirectoryMatch` is what is doing the work. Keep one of the two.

Serve it over https if the instance reaches it across the internet, which it will. The envelope
itself is signed and carries no work, so plain http is not a disaster — but the password for these
pages should not cross the internet in the clear.

For development, the built-in server is enough:

```bash
php -S 127.0.0.1:8776 -t .
```

## 5. Set a password

Open the site. The first visit offers to set a password, because nobody has. Do it before the machine
is reachable from outside, not after — this page decides which address on your own network everything
arriving gets handed to.

Forgotten it later? `php tools/password.php --forget` puts the site back to that first visit. Nothing
else is lost.

## 6. Answer the two questions

Setting the password drops you straight into the wizard.

**Which Beeblebrox.** The name on its own is enough: `zaphod` becomes
`https://zaphod.beeblebrox.cloud`. A self-hosted instance takes its full address. This is checked by
asking the instance, and it is not a label — every envelope names the instance it came from, and one
that names anybody else is refused here rather than carried onto your network.

**Where the worker is.** Its address on your network — `192.168.1.20:8080`, or the name it answers
to. A name beats an IP here, because a DHCP lease that moves is the commonest way this stops working.
`/hook.php` is added for you unless you name a file yourself. Plain `http` unless you type `https://`,
because a certificate for a machine on a LAN is a fight nobody needs.

That check asks the worker's `hook.php` for a page and expects a **405**. That is a Beeblebrox
receiver telling you off for not posting, which is a different and much better answer than "something
is listening" — a router's login screen would pass that one.

## 7. Optionally, lock it down further

Both of these are on the settings page and neither is required.

**The signing secret.** The same string the dispatcher signs with and the worker holds. Store it here
and this proxy checks the signature too, so a forged envelope is refused on this machine rather than
carried to the one it was aimed at. It costs a second copy of the secret and a second clock: keep the
tolerance the same at both ends, or this machine's clock becomes a new way for a good envelope to be
refused. Storing it needs `secret_key` in `config.local.php`:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

**The allow list.** Your instance calls from one address. Naming it here is worth more than naming it
on the worker, which only ever sees this machine. Leave it empty if the instance sits behind a load
balancer or a CDN and does not have one address.

## 8. Point the dispatcher here

On the instance, the role's webhook dispatcher gets this proxy's URL instead of the worker's:

```
https://proxy.example.com/hook.php
```

Nothing else about it changes. Its signing secret stays as it was, and the worker still verifies the
signature, because the envelope reaches it byte for byte as it was signed.

Give the dispatcher a timeout **longer** than this proxy's "wait for the worker" (15 seconds by
default). If the dispatcher gives up first, every delivery looks like a failure it should retry while
the worker is quietly accepting each one.

Then use the dispatcher's own test button. It sends a real signed envelope naming task 0, which no
task ever is; the worker answers it and queues nothing. It shows on the deliveries page whichever way
it goes.

## 9. Check it

```bash
timeout 120 php tools/selftest.php
```

Same checks as the diagnostics page. Worth running from a shell as well as opening the page: this
reaches the worker as you, and the page reaches it as the web server's account through whatever
network the web server happens to be on. On a box with two interfaces, or with the worker on the far
side of a container's network, those are not the same question.

---

## When nothing arrives

Everything is on the **deliveries** page, and the four outcomes there each point somewhere different.

**Nothing at all in the list.** Nothing is reaching this machine. That is the port forward, the DNS
record, the firewall or the dispatcher's URL — not anything in this application. The dispatcher's log
on the instance will say what it got.

**Refused here.** This machine turned it away, and the row says why in a sentence. Almost always the
instance name: the envelope says one thing and the settings page says another.

**Never arrived.** It left here and nothing answered. The worker is off, asleep, or has a new address
— a DHCP lease that moved is the usual one.

**Worker said 4xx.** The chain works and the worker refused the envelope. Look at the worker's own
webhook log; it records the same envelope with its own reason. A 401 there means the two ends hold
different signing secrets — or that the worker has none stored at all, which it refuses rather than
accepting unsigned.

The delivery log keeps every envelope, including refused ones, and is not pruned. On a machine that is
public by design that grows, so it is worth an eye.

## Upgrading

Two ways in, matching the two ways this is installed.

**If you cloned it**, that is the whole thing:

```bash
git pull
timeout 120 php tools/migrate.php
```

**If you unpacked a zip**, there is no `git pull` to run. Download the current one from
<https://github.com/j-tools/beeblebrox-proxy/releases/latest> and swap it in:

```bash
# beside the install, not on top of it
unzip beeblebrox-proxy.zip -d /tmp/upgrade

# the two things that are yours and are not in the archive
cp /path/to/beeblebrox-proxy/config.local.php /tmp/upgrade/beeblebrox-proxy/
cp /path/to/beeblebrox-proxy/data/*.sqlite    /tmp/upgrade/beeblebrox-proxy/data/

# swap, keeping the old one until the new one has answered a page
mv /path/to/beeblebrox-proxy /path/to/beeblebrox-proxy.old
mv /tmp/upgrade/beeblebrox-proxy /path/to/beeblebrox-proxy
timeout 120 php /path/to/beeblebrox-proxy/tools/migrate.php
```

Then open Diagnostics. The **Build** line names the commit this copy came from, so comparing it with
the releases page is how you know the swap took.

**Unpacking over the top works and is quicker, with one catch worth knowing.** Your configuration and
your database survive it — `config.local.php` and `data/proxy.sqlite` are not in the archive, and the release
workflow refuses to publish one that contains either. What an overwrite cannot do is *remove* a file
that no longer exists in the new version, so a page or a library dropped from the release stays on
disk and stays reachable. Swapping directories is the version that cannot leave anything behind.

Either way the files are the deployment: there is no service to restart, and the next request runs
the new code. Whoever the web server runs as still needs to be able to write to `data/`, so check that
if the new copy was unpacked by a different account than the old one.
`tools/migrate.php` applies anything in `db/migrations/` once each and records it.
