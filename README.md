

# wallee PlentyONE
This repository contains the PlentyONE extension that enables to process payments with [wallee](https://www.wallee.com/).

###### To use this extension, a [wallee](https://www.wallee.com/) account is required.

## Requirements

* [PlentyONE 7](https://www.plentyone.com/)
* [IO](https://marketplace.plentyone.com/plugins/channels/online-shops/io_4696)
* [Ceres](https://marketplace.plentyone.com/plugins/channels/online-shops/ceres_4697)

## Documentation

* [Documentation](@WalleeDocPath(/docs/en/documentation.html))

## PWA Installation
For using the [PlentyONE PWA](https://github.com/plentymarkets/plentyshop-pwa), you'll need to add these files in the PWA project:

*   **Vue Page**: `resources/js/pages/payment-selection/` → `apps/web/app/pages/payment-selection/`
*   **Client Plugin**: `resources/js/wallee.client.ts` → `apps/web/app/plugins/wallee.client.ts`
*   **Middleware**: Update `apps/server/middleware.config.ts` using snippets from `resources/js/middleware.config.ts`.

### Local development (running the PWA against a WAF-protected shop)

When the PlentyONE backend (the REST API the middleware calls, e.g. `*.plentymarkets.com` — not the
PWA app) sits behind a WAF (e.g. CloudFront), its SSRF rules reject any `register-return` request
whose body contains a loopback origin (`http://localhost`, `127.0.0.1`, `0.0.0.0`). That makes
`register-return` return `403` locally, so `isPwaContext` stays `false` and the hosted payment page
never opens. The middleware snippet already rewrites a loopback origin to the IPv6 loopback `[::1]`,
which the WAF accepts and the browser still resolves to localhost. For the whole
flow (payment page **and** the post-payment return landing back on the PWA in the same session, so
the shopper is not asked to re-authenticate) run everything on the `[::1]` origin:

1. In `apps/web/.env` set `USE_IPV6=true` and `MIDDLEWARE_CLIENT_URL=http://[::1]:8181`.
2. In `apps/server/src/index.ts` add `http://[::1]:3000` to the `cors.origin` list.
3. In `apps/web/nuxt.config.ts` let `shopCore.apiUrl` read `MIDDLEWARE_CLIENT_URL` (falling back to
   the existing value), so the browser talks to the middleware on the same `[::1]` host.
4. Browse the shop on **`http://[::1]:3000`** for the entire flow (not `localhost`).

None of this affects real deployments: their origin is a public domain, never loopback, so the
rewrite is inert and these local-only settings are unset.

## License

Please see the [license file](@WalleeRepoPath(LICENSE)) for more information.