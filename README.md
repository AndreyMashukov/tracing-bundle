# amashukov/tracing-bundle

A Symfony 7 bundle that stamps every inbound HTTP request with a UUIDv7 `X-Request-Id`, mirrors it onto every Monolog log record's `extra.request_id`, echoes it back on the response, and rides through Symfony Messenger so a worker logging a handler ends up with the same id the originating HTTP request held.

[![CI](https://img.shields.io/github/actions/workflow/status/AndreyMashukov/tracing-bundle/ci.yml?branch=main&label=CI)](https://github.com/AndreyMashukov/tracing-bundle/actions)
[![PHPStan L8](https://img.shields.io/github/actions/workflow/status/AndreyMashukov/tracing-bundle/stan.yml?branch=main&label=PHPStan%20L8)](https://github.com/AndreyMashukov/tracing-bundle/actions)
[![Latest Version](https://img.shields.io/packagist/v/amashukov/tracing-bundle)](https://packagist.org/packages/amashukov/tracing-bundle)
[![Downloads](https://img.shields.io/packagist/dt/amashukov/tracing-bundle)](https://packagist.org/packages/amashukov/tracing-bundle)
[![PHP](https://img.shields.io/packagist/dependency-v/amashukov/tracing-bundle/php)](https://packagist.org/packages/amashukov/tracing-bundle)
[![License](https://img.shields.io/packagist/l/amashukov/tracing-bundle)](LICENSE)
[![Stars](https://img.shields.io/github/stars/AndreyMashukov/tracing-bundle?style=social)](https://github.com/AndreyMashukov/tracing-bundle)

`amashukov/tracing-bundle` is a **vendor-extractable Symfony 7 bundle for end-to-end `request_id` propagation**. Doing this once at the right layer beats bolting it on per-call: the bundle ships the kernel listener, the resolver, the Monolog processor, the Messenger stamp, the consume-side context restorer, validation at the trust boundary, and the CLI fallback so every console run / cron / worker logs `{"request_id":"..."}` instead of an empty extra block. Drop it into a Symfony 7 project, get the same id flowing through the FE fetch, the controller, every log line on every channel, the response header, and the queue-bound worker that picks the message up minutes later — with zero `App\*` namespace coupling.

```
FE fetch ─┐                                       ┌─► response with X-Request-Id
          │                                       │
          ▼                                       │
   ┌──────────────────────────────────────────────┴───┐
   │  Symfony kernel                                  │
   │  ├─ RequestIdListener   (kernel.request  +256)   │
   │  ├─ RequestIdResolver   (DI service)             │
   │  ├─ RequestIdProcessor  (monolog.processor)      │
   │  └─ ...handlers...                               │
   └──────────────┬───────────────────────────────────┘
                  │ $bus->dispatch($message)
                  ▼
            envelope + RequestIdStamp ─► queue ─► worker
                                                  │
                                                  ▼
                                  RequestIdMessengerMiddleware
                                  └─► WorkerRequestIdContext
                                       └─► RequestIdResolver
                                            └─► same UUIDv7 in handler logs
```

## Features

- **Per-request UUIDv7** — generated via `symfony/uid` when the incoming `X-Request-Id` is missing or malformed; valid inbound ids are lowercased and forwarded unchanged.
- **Listener at the kernel boundary** — `kernel.request` (priority `256`) writes the id onto the `Request` attribute; `kernel.response` (priority `-256`) mirrors it onto the response header. Main-request only; sub-requests inherit the parent id naturally.
- **Validation at the trust boundary** — incoming header rejected when length ≠ 36 or it contains anything outside `[a-f0-9-]`. Prevents log-injection (SQL fragments, control characters, oversized payloads) from contaminating log files and aggregator search.
- **Monolog processor on every channel** — `extra.request_id` attached to every `LogRecord` (`app`, `doctrine`, `security`, `messenger`, ...) with pre-existing extras preserved.
- **Messenger sync → queue → worker bridge** — dispatch-side middleware attaches a `RequestIdStamp` to outbound envelopes; consume-side restores the id into `WorkerRequestIdContext` before the handler runs, clears in `finally` so message N+1 starts clean.
- **CLI fallback** — every console / cron / non-Messenger worker run logs `{"request_id":"cli"}` instead of an empty extra block — log-aggregator queries stay consistent regardless of execution mode.
- **`final readonly` services** — narrow contracts, immutable wiring, autowired by default.
- **Zero `App\*` coupling** — bundle depends only on `monolog/monolog` + `symfony/*`. Drop into any project without renaming.

## Installation

```bash
composer require amashukov/tracing-bundle
```

Symfony Flex registers the bundle automatically. If you don't run Flex, add it manually:

```php
// config/bundles.php
use Amashukov\TracingBundle\TracingBundle;

return [
    TracingBundle::class => ['all' => true],
];
```

## Requirements

- PHP **8.3+** (UUIDv7 needs `symfony/uid` ≥ 7.0)
- `monolog/monolog` ^3.0
- `symfony/*` ^7.0 (`config`, `dependency-injection`, `event-dispatcher`, `http-foundation`, `http-kernel`, `uid`, `yaml`)
- `symfony/messenger` ^7.0 — soft `suggest`. The middleware class only loads when Messenger is installed; non-Messenger projects pay zero overhead.

## Usage

After install — no further config. Every request automatically:

1. Receives a `request_id` attribute on the `Request`.
2. Logs `extra.request_id` in every Monolog record.
3. Mirrors `X-Request-Id` on the response.

### Reading the id inside a service

```php
use Amashukov\TracingBundle\Http\RequestIdResolverInterface;

final readonly class MyService
{
    public function __construct(private RequestIdResolverInterface $requestId) {}

    public function someMethod(): void
    {
        $current = $this->requestId->current();   // UUIDv7 string or 'cli'
    }
}
```

### Browser side (Nuxt 3 / 4 plugin)

```ts
const requestId = crypto.randomUUID();

const api = $fetch.create({
  onRequest: ({ options }) => {
    options.headers = { ...options.headers, 'X-Request-Id': requestId };
  },
});
```

### Playwright per-test echo

```ts
export const test = base.extend<{ testRequestId: string }>({
  testRequestId: [async ({ browser: _ }, use, testInfo) => {
    const requestId = crypto.randomUUID();
    process.stdout.write(`[TEST-REQ-ID] ${testInfo.title} -> ${requestId}\n`);
    await use(requestId);
  }, { auto: true }],

  page: async ({ context, page, testRequestId }, use) => {
    await context.setExtraHTTPHeaders({ 'X-Request-Id': testRequestId });
    await use(page);
  },
});
```

Then debug any failing run:

```sh
grep "<test-uuid>" backend/var/log/app.log
```

Every BE event scoped to that one test, no cross-spec noise.

### CORS allow header

If the FE talks to a different origin, allow the header on both directions:

```yaml
# RoadRunner .rr.yaml — or your CORS layer of choice
http:
  middleware:
    - headers
  headers:
    cors:
      allowed_headers: "...,X-Request-Id"
      exposed_headers: "...,X-Request-Id"
```

## Messenger integration (sync → queue → worker)

When a Messenger message crosses the sync → queue boundary, the worker process has no `RequestStack`. The bundle's middleware closes that gap.

Dispatch side (HTTP request handler):

```php
$bus->dispatch(new MyMessage(...));
// Envelope leaves the dispatcher with [RequestIdStamp('01923e1c-...')] attached.
```

Consume side (worker process):

```text
- Worker pulls the message; ReceivedStamp lands on the envelope.
- Middleware sees ReceivedStamp + RequestIdStamp -> WorkerContext::setRequestId(...).
- Handler runs; any Monolog log inside it gets extra.request_id == '01923e1c-...'.
- Middleware's finally clause clears the context so message N+1 starts fresh.
```

Per W3C Trace Context spec _Non-HTTP Protocol Support_ and the Symfony Messenger official middleware pattern (`$envelope->last(ReceivedStamp::class)` discriminates dispatch vs consume).

## Class catalogue

| Class | What it does |
|---|---|
| `Http\RequestIdListener` | `kernel.request` (priority 256) reads `X-Request-Id`, validates as 36-char hex UUID, generates **UUIDv7** via `symfony/uid` when missing or malformed. `kernel.response` (priority -256) mirrors the id onto the response header. Main-request only. |
| `Http\RequestIdResolverInterface` | Narrow contract `current(): string`. Services depend on the interface; the bundle wires the alias. |
| `Http\RequestIdResolver` | `final readonly` implementation. Reads `request_id` off the main request first, falls back to `WorkerRequestIdContext` (when running inside Messenger), then to the `cli` constant. |
| `Monolog\RequestIdProcessor` | Tagged `monolog.processor`. Attaches `extra.request_id` to every `LogRecord` on every channel. Pre-existing `extra` keys preserved. |
| `Messenger\RequestIdStamp` | Immutable `StampInterface` value object carrying one string (the originating request's id). |
| `Messenger\RequestIdMessengerMiddleware` | Dual-path Messenger middleware. **Dispatch**: attaches `new RequestIdStamp($resolver->current())` to the envelope if not already present. **Consume** (`ReceivedStamp` present): reads the stamp, writes the id to `WorkerRequestIdContext` before calling the next middleware, clears in `finally`. |
| `Messenger\WorkerRequestIdContext` | Single-cell mutable state holder for the worker's current message id. Read by `RequestIdResolver::current()` when there is no HTTP request. |

## Validation

Incoming `X-Request-Id` is rejected when:

- length ≠ 36 chars
- contains anything outside `[a-f0-9-]`

Rejected → bundle generates a fresh UUIDv7.

## CLI fallback

In CLI context (no `Request` on `RequestStack`, no `WorkerRequestIdContext` value set), the resolver returns the literal `cli`. Every console command / one-shot cron task logs `{"request_id":"cli"}` instead of an empty extra block — log-aggregator queries stay consistent regardless of execution mode.

## Trace Context (W3C `traceparent`)

This bundle deliberately implements the `X-Request-Id` header pattern only (Heroku / Cloudflare CF-Ray style). For W3C Trace Context (`traceparent` / OpenTelemetry alignment) pair this bundle with the official `open-telemetry/opentelemetry-php-instrumentation-symfony` — the two are complementary, not alternatives.

## Testing

```sh
composer install
composer test
composer cs
composer stan
composer rector
```

Suite covers: valid UUIDv7 accept, mixed-case header lowercased, five invalid-header regen cases (missing, too short, too long, wrong charset, SQL-injection-looking string), response header mirror, sub-request skip, subscribed-events shape, CLI fallback (no request / no attribute / non-string attribute), `extra.request_id` attach with pre-existing extras preserved, Messenger stamp value semantics, worker-context set/get/clear, middleware dispatch path, middleware consume path, middleware `finally` clears between messages.

## License

MIT — see [LICENSE](LICENSE).

## Author

[Andrei Mashukov](https://github.com/AndreyMashukov) — `a.mashukoff@gmail.com`
