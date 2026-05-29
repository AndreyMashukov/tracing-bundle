# amashukov/tracing-bundle

[![Latest Version](https://img.shields.io/packagist/v/amashukov/tracing-bundle)](https://packagist.org/packages/amashukov/tracing-bundle)
[![PHP](https://img.shields.io/packagist/dependency-v/amashukov/tracing-bundle/php)](https://packagist.org/packages/amashukov/tracing-bundle)
[![License](https://img.shields.io/packagist/l/amashukov/tracing-bundle)](LICENSE)

Symfony 7 bundle. Stamps every inbound HTTP request with a UUIDv7 `X-Request-Id`, mirrors it onto every Monolog log record's `extra.request_id`, echoes it back on the response, and rides through Symfony Messenger so a worker logging a handler ends up with the same id the originating HTTP request held.

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

## Why

When a request fails across the FE/BE seam, you need one id that flows through every layer:

- the browser `fetch` that started it,
- the Symfony controller that handled it,
- every log line the request emitted on any channel (`app`, `doctrine`, `security`, `messenger`, ...),
- the response header so the FE can surface it in error toasts and Sentry breadcrumbs,
- the Messenger handler that picks the work up minutes later in a separate worker process.

Doing this once at the right layer beats bolting it on per-call. The bundle ships the listener, the resolver, the Monolog processor, the Messenger stamp, the consume-side context restorer, the validation at the trust boundary, and the CLI fallback so every console run / cron / worker logs `{"request_id":"..."}` instead of an empty extra block.

## What you get

| Piece | What it does |
|---|---|
| `Http\RequestIdListener` | `kernel.request` (priority 256) reads `X-Request-Id`, validates as 36-char hex UUID, generates **UUIDv7** via `symfony/uid` when missing or malformed. `kernel.response` (priority -256) mirrors the id onto the response header. Main-request only; sub-requests inherit the parent's id naturally. |
| `Http\RequestIdResolverInterface` | Narrow contract `current(): string`. Services depend on the interface; the bundle wires the alias. |
| `Http\RequestIdResolver` | `final readonly` implementation. Reads `request_id` off the main request first, falls back to `WorkerRequestIdContext` (when running inside Messenger), then to the `cli` constant. |
| `Monolog\RequestIdProcessor` | Tagged `monolog.processor`. Attaches `extra.request_id` to every `LogRecord` on every channel. Pre-existing `extra` keys are preserved. |
| `Messenger\RequestIdStamp` | Immutable `StampInterface` value object carrying one string (the originating request's id). |
| `Messenger\RequestIdMessengerMiddleware` | Dual-path Messenger middleware. **On dispatch** (no `ReceivedStamp` on the envelope): if no stamp is attached yet, attaches `new RequestIdStamp($resolver->current())`. **On consume** (`ReceivedStamp` present): reads the stamp, writes the id to `WorkerRequestIdContext` before calling the next middleware, clears in `finally` so the next message starts clean. |
| `Messenger\WorkerRequestIdContext` | Single-cell mutable state holder for the worker's current message id. Read by `RequestIdResolver::current()` when there is no HTTP request. |

## Install

```sh
composer require amashukov/tracing-bundle
```

Symfony Flex registers the bundle. If you don't run Flex, add it manually:

```php
// config/bundles.php
use Amashukov\TracingBundle\TracingBundle;

return [
    TracingBundle::class => ['all' => true],
];
```

## Requirements

- PHP **8.3+**
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

## Messenger integration (sync -> queue -> worker)

When a Messenger message crosses the sync -> queue boundary, the worker process has no `RequestStack`. The bundle's middleware closes that gap.

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

## Validation

Incoming `X-Request-Id` is rejected when:

- length != 36 chars
- contains anything outside `[a-f0-9-]`

Rejected -> bundle generates a fresh UUIDv7. Prevents log injection (SQL fragments, control characters, oversized payloads) from contaminating log files and aggregator search.

## CLI fallback

In CLI context (no `Request` on `RequestStack`, no `WorkerRequestIdContext` value set), the resolver returns the literal `cli`. Every console command / one-shot cron task logs `{"request_id":"cli"}` instead of an empty extra block — log-aggregator queries stay consistent regardless of execution mode.

## Trace Context (W3C `traceparent`)

This bundle deliberately implements the `X-Request-Id` header pattern only (Heroku / Cloudflare CF-Ray style). For W3C Trace Context (`traceparent` and OpenTelemetry alignment) pair this bundle with the official `open-telemetry/opentelemetry-php-instrumentation-symfony` — the two are complementary, not alternatives.

## Testing

```sh
composer install
composer test
composer cs
composer stan
composer rector
```

Suite covers:

- valid UUIDv7 accept,
- mixed-case header normalised to lowercase,
- invalid header regenerated (5 cases: missing, too short, too long, wrong charset, SQL injection),
- response header mirror,
- sub-request skip,
- subscribed-events shape,
- CLI fallback (no request, no attribute, non-string attribute),
- `extra.request_id` attach with pre-existing extras preserved,
- Messenger `RequestIdStamp` value semantics,
- `WorkerRequestIdContext` set/get/clear lifecycle,
- middleware dispatch path attaches the stamp,
- middleware consume path restores into the worker context,
- middleware `finally` clears between messages.

## License

MIT. See [LICENSE](LICENSE).

## Author

[Andrei Mashukov](https://github.com/AndreyMashukov) — `a.mashukoff@gmail.com`
