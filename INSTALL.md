# Installing beeblebrox-proxy

This goes on a machine with two properties: your Beeblebrox instance can reach it, and it can reach
the worker. Usually that is a box in the office with a port forward or a DNS record pointing at it,
sitting on the same LAN as the laptop or server running `beeblebrox-local`.

It needs PHP 8.1 or newer with `curl`, `openssl` and `mysqli`, and a MySQL or MariaDB database.
Nothing else — no scheduled task, no queue, no worker process.

If you are wondering whether you need this at all: you do not, if the worker is happy polling. The
worker asks the instance for work on its own and needs nothing inbound. This is for when you want
work **pushed**, because a poll interval is a delay on every single task.

---

## 1. Put the files somewhere

```bash
git clone https://github.com/j-tools/beeblebrox-proxy.git
cd beeblebrox-proxy
```

`main` is the released branch, `beta` is where changes land first. Whichever you take, the whole
installation is this directory plus a database — there is nothing to build and nothing to compile.

## 2. Make a database

```sql
CREATE DATABASE beeblebrox_proxy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'id_beeblebrox_proxy'@'localhost' IDENTIFIED BY 'something long';
GRANT ALL ON beeblebrox_proxy.* TO 'id_beeblebrox_proxy'@'localhost';
```

Then load the schema:

```bash
mysql -u id_beeblebrox_proxy -p beeblebrox_proxy < db/schema.sql
```

Three tables: `settings`, `deliveries`, `sessions`. The middle one is the whole history of what this
machine has done.

## 3. Configure it

```bash
cp config.local.example.php config.local.php
```

Fill in the database, and `site_url` — **the address your instance will call**, written exactly as it
will be called, scheme and all. That value is not decoration:

- the session cookie for these pages takes its `Secure` flag from it, and it is never derived from
  the request, because a forged `Host` header must not be able to turn that off on the one machine
  here that is reachable from outside;
- it is the URL printed on the settings and diagnostics pages as the one to give the dispatcher.

`secret_key` can stay empty. It is only needed if you later decide to have this proxy check
signatures itself, which is optional — see step 7.

## 4. Serve it

The document root is the repository directory. An Apache vhost, roughly:

```apache
<VirtualHost *:443>
  ServerName proxy.example.com
  DocumentRoot /srv/beeblebrox-proxy

  <Directory /srv/beeblebrox-proxy>
    Require all granted
    AllowOverride None
    Options -Indexes
  </Directory>

  # Nothing outside the pages needs to be served, and config.local.php holds the database password.
  <FilesMatch "^(config\.local\.php)$">
    Require all denied
  </FilesMatch>
  <DirectoryMatch "/(lib|db|tools|tests)/">
    Require all denied
  </DirectoryMatch>

  SSLEngine on
  # ... your certificate
</VirtualHost>
```

Serve it over https if the instance reaches it across the internet, which it will. The envelope
itself is signed and carries no work, so plain http is not a disaster — but these pages have a
password on them, and that password should not cross the internet in the clear.

For development on Windows with XAMPP, the shortcut is the built-in server:

```bash
C:/xampp/php/php.exe -S 127.0.0.1:8776 -t .
```

## 5. Set a password

Open the site. The first visit offers to set a password, because nobody has. Do it before the
machine is reachable from outside, not after — this page decides which address on your own network
everything arriving gets handed to.

## 6. Answer the two questions

Setting the password drops you straight into the wizard.

**Which Beeblebrox.** The name on its own is enough: `zaphod` becomes
`https://zaphod.beeblebrox.cloud`. A self-hosted instance takes its full address. This is checked by
asking the instance, and it is not a label — every envelope names the instance it came from, and one
that names anybody else is refused here rather than carried onto your network.

**Where the worker is.** Its address on this network — `192.168.1.20:8080`, or the name it answers
to. `/hook.php` is added for you unless you name a file yourself. Plain `http` unless you type
`https://`, because a certificate for a machine on a LAN is a fight nobody needs.

That check asks the worker's `hook.php` for a page and expects a **405**. That is a Beeblebrox
receiver telling you off for not posting, which is a different and much better answer than "something
is listening" — a router's login screen would pass that one.

## 7. Optionally, lock it down further

Both of these are on the settings page and neither is required.

**The signing secret.** The same string the dispatcher signs with and the worker holds. Store it here
and this proxy checks the signature too, so a forged envelope is refused on this machine rather than
carried to the one it was aimed at. It costs a second copy of the secret and a second clock: keep the
clock tolerance the same at both ends, or this machine's clock becomes a new way for a good envelope
to be refused. Storing it needs `secret_key` in `config.local.php`:

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
signature, because the envelope reaches it exactly as it was signed.

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
— a DHCP lease that moved is the usual one, and the reason a hostname is a better answer in that
field than an IP.

**Worker said 4xx.** The chain works and the worker refused the envelope. Look at the worker's own
webhook log; it records the same envelope with its own reason. A 401 there means the two ends hold
different signing secrets.

## Upgrading

```bash
git pull
timeout 120 php tools/migrate.php
```

`tools/migrate.php` applies anything in `db/migrations/` once each and records it. There is no
service to restart — the next request runs the new code.
