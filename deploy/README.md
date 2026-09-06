# Deploying giopages

The static page cache is the feature the product rests on: a page is rendered
once, written to `public/page-cache/<slug>.html`, and every ad click after that
is answered by the webserver reading that file while PHP never boots. Deployment
is mostly about making sure the webserver actually does that.

There are three serving paths, and one response header names which one you are
on — `X-GioPages-Cache`, with **absence carrying the good news**:

| Header | What served the page |
| --- | --- |
| *(no header)* | The webserver read the file itself. The fast path. |
| `X-GioPages-Cache: fallback` | PHP served it above the autoloader, because the webserver did not. Works, but pays a PHP startup per click. |
| `X-GioPages-Cache: miss` | A full framework render. Expected once per page, after a save; on every request it means nothing is being cached. |

Absence is the marker for the fast path because Apache cannot reliably stamp a
header onto a static file, so there is no marker the fast path could set on
every supported server. The panel's cache indicator reads it the same way round,
from the merchant's own browser.

## Supported layouts

**The document root is `public/`, or a symlink to it.** That is the whole list.

On shared hosting the document root is usually a fixed directory —
`public_html`, `httpdocs`, `www` — that you cannot repoint. Replace it with a
symlink:

```bash
# The application lives outside the document root; only public/ is exposed.
cd ~
git clone <repo> giopages
rm -rf public_html
ln -s ~/giopages/public public_html
```

This is the recipe, not a workaround: `public/.htaccess` ships configured, so
once the document root is `public/` the cache serves itself with no further
step.

### The unsupported layout

Copying `public/`'s contents into `public_html` and rewriting the front
controller's paths — the recipe half the shared-hosting tutorials give — is
**not supported**. Laravel's public path helper resolves relative to the
application directory, so the cache writer would write into `giopages/public/`
while the webserver reads `public_html/`: the two never meet, every request
misses, and nothing anywhere reports it. The failure is silent, which is exactly
the failure this cache exists to remove.

That layout is already partially broken for Laravel generally — the storage
symlink and asset URLs have the same split — so the fix is the symlink above,
not a configuration knob. The panel's indicator diagnoses it: you will sit
permanently on `miss`.

## Apache, and LiteSpeed Enterprise

Nothing to do. `public/.htaccess` ships with the cache rules configured, and
LiteSpeed Enterprise reads that same file — between them that is the
overwhelming majority of shared hosting.

The rules need `AllowOverride` to include `FileInfo`, which every host that
supports Laravel at all already grants (the front controller rewrite needs the
same). If the front controller runs but the cache rules never took — an
OpenLiteSpeed vhost with the stock Laravel rewrite and nobody having pasted
`deploy/openlitespeed/giopages.conf`, a host that rewrites to the front
controller from its own configuration under `AllowOverride None` so this file is
never read, a `public/.htaccess` an upgrade replaced with the framework's stock
copy — the fallback reader still serves the cache from PHP and you land on
`X-GioPages-Cache: fallback`.

If rewriting is disabled entirely there is nothing to fall back to. The front
controller's own rewrite lives in the same `<IfModule mod_rewrite.c>` block as
the cache rules, so `/your-page-slug` matches no file, Apache answers 404, and
`public/index.php` never runs — the fallback reader is never reached. Get
`mod_rewrite` enabled; there is no configuration here that substitutes for it.

Rule order, which every configuration here shares: trailing-slash
canonicalisation, the direct-access lock, the real-file check, the cache lookup,
then the front controller.

### Opt-in: serving the pre-compressed sibling

The cache writes `<slug>.html.gz` alongside every entry, with a matching mtime.
nginx serves it via `gzip_static`, and the fallback reader negotiates it itself.
**Apache does not**, by default: it compresses on the fly instead, which costs
1–3% of payload against a trick that depends on optional modules, on directory
override permissions not every shared host grants, and that collides with
LiteSpeed's own compression layer. On a default path that cannot be verified in
CI, that is not a trade worth shipping.

If you control your host and want it, add this to `public/.htaccess` — and test
it, because you are now on a path the project does not:

```apache
# Inside the <IfModule mod_rewrite.c> block, immediately before the plain cache
# lookup — so a client that cannot decode gzip still falls through to it:
RewriteCond %{HTTP:Accept-Encoding} gzip
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{DOCUMENT_ROOT}/page-cache/$1.html.gz -f
RewriteRule ^(.+)$ page-cache/$1.html.gz [L]

# And at the end of the file. The bytes on disk are gzip, so the client has to
# be told that, and mod_deflate has to be told not to compress them twice:
<FilesMatch "\.html\.gz$">
    ForceType text/html
    SetEnv no-gzip 1

    <IfModule mod_headers.c>
        Header set Content-Encoding gzip
        Header append Vary Accept-Encoding
    </IfModule>
</FilesMatch>
```

## nginx

`deploy/nginx/giopages.conf` is a complete server block: copy it, set
`server_name` and `root`, point `fastcgi_pass` at your PHP-FPM socket. It has
been the configuration since the cache existed and is unchanged by the move to
shared hosting.

## OpenLiteSpeed

`deploy/openlitespeed/giopages.conf`, and it is **explicitly untested** — see
the warning at the top of that file. OpenLiteSpeed does not read `.htaccess` by
default; the shortest route is to turn that on, and the file gives the explicit
rules for operators who keep it off.

## Product images

An image a merchant uploads is served the same way a cached page is: the
webserver reads a file and PHP never boots. That needs the storage link, which
is not created by a checkout:

```bash
php artisan storage:link
```

It points `public/storage` at `storage/app/private`, which is where Product
images are (ADR-0011 explains why a directory called `private` is publicly
visible). Apache is told to follow that link by `public/.htaccess`; nginx
follows symlinks by default and needs nothing. Without the link every image on
every page 404s, silently — nothing logs it, because no request reaches PHP.

### Upgrading an installation that predates this

Three steps, and the first one is not optional — run it **before** the link
exists, because it is the only moment at which those files are still private.

```bash
# 1. Delete the staged uploads stranded on what is now a public directory.
#    Livewire used to write every file dragged into the panel here, including
#    the ones nobody saved. It now writes to storage/app/staging instead — so
#    nothing sweeps this directory any more, and every file in it would be
#    fetchable at /storage/livewire-tmp/... the moment the link is made.
rm -rf storage/app/private/livewire-tmp

# 2. Repoint the link. It used to point at storage/app/public, and the command
#    refuses a link that is already there unless told to replace it. Use
#    --force rather than removing it by hand: `rm -rf public/storage/`, with
#    the trailing slash, empties the directory it points at instead — which is
#    every product image on the installation.
php artisan storage:link --force

# 3. Make the images already on disk readable. They were written with private
#    permissions, which is a 403 rather than a 404 if your webserver runs as a
#    different user from PHP-FPM. New uploads are written readable.
chmod -R u=rwX,go=rX storage/app/private
```

Then flush the cache below — every page cached before the link was fixed has
the broken URL frozen into it.

## Releasing

Empty the cache as the last step of any release that changes how a Page
renders:

```bash
php artisan page-cache:flush
```

It removes every cached Page — the HTML and its gzip sibling — and reports how
many it removed. Running it against an empty cache is harmless and reports
zero, so there is no need to work out whether it is needed: put it in the
deploy script unconditionally.

It re-reads the directory before reporting, and exits non-zero if anything
survived — the case where the release runs as a different user from the one
PHP-FPM wrote the cache as, and the unlinks silently fail. A deploy script
running under `set -e` will stop there, which is the right outcome: a flush
that was believed and did not happen is worse than a failed deploy.

**A release that changes checkout rendering must run it.** The cache is
invalidated per URL by saves — a Page edit, a Product price change — and a
release is not a save. Nothing in the database moved, so nothing tells the
cache that the files on disk were written by templates that no longer exist,
and the webserver keeps answering ad clicks from the previous version's HTML
without PHP ever booting to notice. A Checkout rendering the old markup against
the new Capture endpoint is the failure that costs orders.

The same applies to any template, shortcode, or asset-URL change. If you are
unsure whether a release touches rendered output, flush: the cost is one full
render per Page on the next click.

A Checkout form carries a fingerprint of what it quoted, and a Capture refuses
any submission whose fingerprint is not the Page's current one (ADR-0007). HTML
written by a release that predates the fingerprint carries none, so every order
placed from an unflushed page is turned away with "please reload" — the flush is
what stops a working release from reading, to a merchant, as a checkout that
stopped taking orders.

## Verifying

Request a published page and look at the response headers:

```bash
curl -sSI https://your-site.example/your-page-slug | grep -i x-giopages-cache
```

No output is the answer you want: the webserver served the file and PHP was
never contacted. `fallback` means the rewrite rules are not reaching the cache;
`miss` on every request means nothing is being cached at all. The panel shows
the same three states, from the browser, for a merchant with no shell access.

Then confirm the lock — the cache must not be reachable at a second address, or
a search engine will index it and split the ranking of the URL your ad creatives
point at:

```bash
# The plain address.
curl -sS -o /dev/null -w '%{http_code}\n' https://your-site.example/page-cache/your-page-slug.html

# And two respellings of the same address, which are the checks that matter.
# The hyphen percent-encoded — curl sends the escape through untouched:
curl -sS -o /dev/null -w '%{http_code}\n' https://your-site.example/page%2Dcache/your-page-slug.html

# And a dot segment, where --path-as-is stops curl tidying it away before it
# sends, which would leave you testing the plain address a second time:
curl -sS -o /dev/null --path-as-is -w '%{http_code}\n' https://your-site.example/./page-cache/your-page-slug.html

# 403 on Apache and LiteSpeed for all three; 404 on nginx, which is how
# `internal` refuses.
```

All three have to be refused, not just the first. Apache decodes and normalises
the path before it maps it to a file, so a lock that reads the raw request line
would let the second and third through and serve the page at a second address —
the exact outcome the lock exists to prevent. The rules here test the resolved
path instead, and refuse it unless the request came from our own rewrite.

`public/robots.txt` disallows that directory as well, and the two are not
redundant. The lock is a refusal to serve, and it only exists where the rewrite
rules reached — an OpenLiteSpeed install without the snippet has none. The
disallow is a request not to index, and it reaches everywhere.

## A note on tests

No automated test covers any of these configurations. That is deliberate: three
webservers cannot be stood up in CI, and a file labelled as tested when it is
not is worse than one honestly labelled. The two checks above run on the host
that actually matters, and the panel's indicator runs the first of them
continuously, for a merchant who would never open a terminal.
