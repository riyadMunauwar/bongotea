# Deploying

## Supported layouts

**The document root is `public/`, or a symlink to it.** That is the whole list.

On shared hosting the document root is usually a fixed directory —
`public_html`, `httpdocs`, `www` — that you cannot repoint. Replace it with a
symlink:

```bash
# The application lives outside the document root; only public/ is exposed.
cd ~
git clone <repo> bongotea
rm -rf public_html
ln -s ~/bongotea/public public_html
```

Copying `public/`'s contents into `public_html` and rewriting the front
controller's paths is **not supported**: Laravel's public path helper resolves
relative to the application directory, so the storage link and asset URLs split
between two directories that never meet.

## Docker, on Dokploy

`docker-compose.yml` at the repository root is the whole stack — app, queue
worker, scheduler, SSR and PostgreSQL — and its header comments are the deploy
notes. The storage link, first Owner and everything below still apply, run
from Dokploy's terminal on the `app` container; the link is already made in
the image.

## Apache, and LiteSpeed Enterprise

Nothing to do. `public/.htaccess` ships configured, and LiteSpeed Enterprise
reads that same file. The rules need `AllowOverride` to include `FileInfo`,
which every host that supports Laravel already grants.

## nginx

`deploy/nginx/giopages.conf` is a complete server block: copy it, set
`server_name` and `root`, point `fastcgi_pass` at your PHP-FPM socket.

## OpenLiteSpeed

`deploy/openlitespeed/giopages.conf`, and it is **explicitly untested** — see
the warning at the top of that file. OpenLiteSpeed does not read `.htaccess` by
default; the shortest route is to turn that on, and the file gives the explicit
rules for operators who keep it off.

## Product images

An image a merchant uploads is served by the webserver reading a file. That
needs the storage link, which is not created by a checkout:

```bash
php artisan storage:link
```

It points `public/storage` at `storage/app/private`, which is where Product
images are (ADR-0011 explains why a directory called `private` is publicly
visible). Apache is told to follow that link by `public/.htaccess`; nginx
follows symlinks by default and needs nothing. Without the link every image
404s, silently — nothing logs it, because no request reaches PHP.

## The first Owner

Access to the admin is decided by permissions, which are carried by roles that
are given to users. The **Owner** stands outside them and may do everything,
including deciding what anyone else may do, and an installation needs one before
anybody can get in — a user holding no role is refused at the door.

Make one by email address:

```bash
php artisan bongotea:owner someone@your-company.example
```

The user must already exist. It is safe to run twice, it reports clearly when
the address matches nobody, and it is the only route into the panel that does
not go through the panel — which is also the whole of the answer when the only
Owner has left the company.

Optionally, pre-register the permission list:

```bash
php artisan permission:sync
```

Nothing is incorrect without it. The application knows every permission it has
whether or not there is a row for it, and a permission is written into the
table when something first grants it — so this is for tidiness, and for
repairing an installation by hand. It is safe in any release and safe to run
twice.

## A note on tests

No automated test covers these webserver configurations: three webservers
cannot be stood up in CI, and a file labelled as tested when it is not is worse
than one honestly labelled.
