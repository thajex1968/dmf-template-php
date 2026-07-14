# Request Lifecycle

The complete journey of an HTTP request through a DMF application built on the
Core Framework — from the web server to shutdown.

> **Applies to template version:** `1.1.0` · **Namespace:** `DMF\Core`
> **Requires:** PHP 8.2+ · **Last updated:** 2026-07-14

Stages: **Request → Bootstrap → Router → Middleware → Controller → Response →
Shutdown.**

---

## Table of Contents

1. [The Big Picture](#the-big-picture)
2. [1. Request](#1-request)
3. [2. Bootstrap](#2-bootstrap)
4. [3. Router](#3-router)
5. [4. Middleware Pipeline](#4-middleware-pipeline)
6. [5. Controller](#5-controller)
7. [6. Response](#6-response)
8. [7. Shutdown](#7-shutdown)
9. [Error Path](#error-path)
10. [Sequence Diagram](#sequence-diagram)
11. [Cross References](#cross-references)

---

## The Big Picture

```mermaid
flowchart LR
    W[Web server<br/>Apache] --> FC[Front controller<br/>public_html/index.php]
    FC --> B[Bootstrap.boot]
    B --> CAP[Request::capture]
    CAP --> H[Bootstrap.handle]
    H --> GMW[Global middleware]
    GMW --> RT[Router.dispatch]
    RT --> RMW[Route middleware]
    RMW --> CTL[Controller / handler]
    CTL --> RES[Response]
    RES --> SEND[Response.send]
    SEND --> TERM[Bootstrap.terminate]
    TERM --> SD[Shutdown handlers]
```

Every stage is a plain, testable call — there is no hidden magic between the
web server and your controller.

---

## 1. Request

Apache routes the URL to the single front controller (`public_html/index.php`);
`config/` and `includes/` sit above the web root and are never served directly.

`Request::capture()` snapshots the HTTP request into an immutable value object
built from the superglobals + `php://input`:

```php
$request = DMF\Core\Request::capture();
$request->method();      // "POST"
$request->input('name'); // merged query ∪ body ∪ JSON
$request->json('title'); // decoded JSON body member
$request->ip();          // client IP (REMOTE_ADDR)
$request->isAjax();      // X-Requested-With
```

```mermaid
flowchart TD
    S["$_GET / $_POST / $_SERVER / $_FILES + php://input"] --> C[Request::capture]
    C --> R[Request value object]
    R --> Q[query / post / input / json]
    R --> F[file / hasFile]
    R --> H[header / bearerToken / csrfToken]
    R --> M[method / ip / userAgent / isAjax]
```

---

## 2. Bootstrap

The kernel prepares the application, then turns the request into a response.
Full detail in [BOOTSTRAP.md](./BOOTSTRAP.md).

```mermaid
flowchart LR
    subgraph boot
        A[Config .env] --> B[Timezone]
        B --> C[Error/shutdown handlers]
        C --> D[Register services - lazy]
        D --> E[Session.start]
        E --> F[event app.booted]
    end
    F --> G[handle Request]
```

`handle(Request)` dispatches `request.received`, then runs the request through
the global middleware pipeline into the router, wrapped in a `try/catch` that
converts any `Throwable` into a logged, safe `500`.

---

## 3. Router

`Router::dispatch(Request)` matches the request **path** and **method** against
the registered routes:

```mermaid
flowchart TD
    D[dispatch Request] --> M{Path matches a route regex?}
    M -- no --> NF[404 Not Found]
    M -- yes --> ME{Method allowed?}
    ME -- no --> M405[405 Method Not Allowed + Allow header]
    ME -- yes --> P[Extract path params]
    P --> PIPE[Run route middleware pipeline]
    PIPE --> H[Invoke handler with Request + params]
```

- `{param}` and `{param:regex}` placeholders become named captures.
- Handlers may be closures or `[Controller::class, 'method']`.
- A non-`Response` return is coerced: arrays → `Response::json`, strings →
  `Response::html`.
- Reverse routing: `Router::url('users.show', ['id' => 5])` → `/users/5`.

---

## 4. Middleware Pipeline

Middleware wrap the request/response cycle as an **onion** — outermost first on
the way in, reverse on the way out. Global middleware (from `Bootstrap`) wrap
route middleware (from the `Router`), both composed by the same pipeline logic
in `DMF\Core\Middleware`.

```mermaid
flowchart LR
    IN[Request] --> G1[Global MW 1 ▸ before]
    G1 --> R1[Route MW ▸ before]
    R1 --> CORE[Controller]
    CORE --> R2[Route MW ◂ after]
    R2 --> G2[Global MW 1 ◂ after]
    G2 --> OUT[Response]
```

A middleware either calls `$next($request)` to continue, or short-circuits by
returning its own `Response`:

```php
final class RequireLogin extends DMF\Core\Middleware
{
    public function __construct(private DMF\Core\Session $session) {}

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->session->has('user_id')) {
            return Response::redirect('/auth/login.php');
        }
        return $next($request);   // continue the chain
    }
}
```

Because `Middleware` implements `__invoke`, instances plug straight into the
router: `$router->get('/admin', $h)->middleware(new RequireLogin($session));`.

---

## 5. Controller

The innermost layer is your handler ("controller"). It receives the `Request`
and matched route params, does the work using the resolved services, and returns
a `Response`. This is the **only** layer that contains application/business
logic — the framework provides none.

```php
final class HealthController
{
    public function __construct(private DMF\Core\Database $db) {}

    public function show(Request $request, array $params): Response
    {
        $ok = $this->db->scalar('SELECT 1') !== null;
        return Response::success(['status' => $ok ? 'ok' : 'degraded']);
    }
}
```

Typical collaborators, all resolved from the container:

```mermaid
flowchart TD
    CTL[Controller] --> V[Validator - input rules]
    CTL --> DB[Database - PDO queries]
    CTL --> VW[View - render HTML]
    CTL --> CA[Cache - memoize]
    CTL --> UP[Upload - store files]
    CTL --> MA[Mail - notify]
    CTL --> EV[Event - dispatch domain hooks]
    CTL --> RE[Response]
```

---

## 6. Response

The controller returns a `Response`; `Bootstrap` dispatches `response.prepared`,
then `Response::send()` emits the status line, headers, and body exactly once.

```mermaid
flowchart TD
    R[Response object] --> T{Type}
    T -->|json / success / error| J[Content-Type: application/json]
    T -->|html / text| HT[Content-Type: text/html or plain]
    T -->|redirect| RD[Location header + 3xx]
    T -->|download| DL[Stream file via readfile]
    J --> SEND[send]
    HT --> SEND
    RD --> SEND
    DL --> SEND
    SEND --> OUT[Client]
```

`success()`/`error()` emit the platform's documented JSON envelope
(`{ success, data | message, errors }` — see [API.md](./API.md)).

---

## 7. Shutdown

After the response is sent, `Bootstrap::terminate()` runs post-response work and
dispatches `app.terminating`, then PHP's registered shutdown function performs
the final safety check.

```mermaid
flowchart TD
    SENT[Response sent] --> TERM[Bootstrap.terminate]
    TERM --> EV[event app.terminating]
    EV --> WORK[Deferred work: audit log, cleanup, metrics]
    WORK --> PHP[register_shutdown_function]
    PHP --> FE{Fatal error occurred?}
    FE -- yes --> LOG[Logger.emergency + safe 500]
    FE -- no --> END[Process ends]
```

Use `app.terminating` for work that should not delay the user — audit logging,
cache warming, sending queued notifications, emitting metrics.

---

## Error Path

Failures are contained at every layer and always logged:

```mermaid
flowchart TD
    X[Throwable in middleware/controller] --> C[handle try/catch]
    C --> L[Logger.error]
    L --> D{APP_DEBUG?}
    D -- true --> M1[Response::error with message, 500]
    D -- false --> M2[Response::error Internal Server Error, 500]
    U[Uncaught outside handle] --> EH[set_exception_handler → critical + 500]
    F[Fatal error] --> SH[shutdown handler → emergency + 500]
```

No error leaks internals to the client in production, and no single request can
crash the process silently.

---

## Sequence Diagram

```mermaid
sequenceDiagram
    participant Apache
    participant Front as index.php
    participant Boot as Bootstrap
    participant Req as Request
    participant Router
    participant MW as Middleware
    participant Ctl as Controller
    participant Svc as Services (DB/View/…)
    participant Resp as Response

    Apache->>Front: HTTP request
    Front->>Boot: new Bootstrap → boot()
    Boot->>Boot: config, handlers, services, session
    Front->>Req: Request::capture()
    Front->>Boot: run() → handle(request)
    Boot->>MW: global pipeline
    MW->>Router: dispatch(request)
    Router->>MW: route pipeline
    MW->>Ctl: handler(request, params)
    Ctl->>Svc: query / render / cache
    Svc-->>Ctl: data
    Ctl-->>Router: Response
    Router-->>Boot: Response
    Boot->>Resp: send()
    Resp-->>Apache: status + headers + body
    Boot->>Boot: terminate() → app.terminating
    Boot->>Boot: shutdown handler (fatal check)
```

---

## Cross References

- [BOOTSTRAP.md](./BOOTSTRAP.md) — the startup phase in depth
- [CLASS_DIAGRAM.md](./CLASS_DIAGRAM.md) — every class and relationship
- [ARCHITECTURE.md](./ARCHITECTURE.md#request-flow) — the include-chain view
- [API.md](./API.md) — response envelope & status codes
- [SECURITY.md](./SECURITY.md#security-flow) — controls along the path

---

<sub>DMF PHP Template · Request Lifecycle · v1.1.0 · © 2026 Digital Media Foundation</sub>
