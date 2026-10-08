---
paths:
  - '{Dockerfile,docker-compose.yml,deploy/docker/**,config/trustedproxy.php}'
---

# Docker

## Docker stack: proxies trusted only there, SSR bundle self-contained
TRUSTED_PROXIES=* is set only in docker-compose.yml, where Traefik is the only thing that reaches nginx. Leave config/trustedproxy.php defaulting to unset: on a host with nothing in front of PHP it would let visitors choose the IP that tracking and throttles see. The ssr service has no node_modules (every package is a devDependency), so vite.config.js keeps `ssr.noExternal: true`; never give it a domain, because it answers /shutdown. The app container also needs bootstrap/ssr/ssr.js, because config/inertia.php `ensure_bundle_exists` skips SSR without it. Only the app container migrates (RUN_MIGRATIONS); the worker and scheduler wait until it is healthy.
