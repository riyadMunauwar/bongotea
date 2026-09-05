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
