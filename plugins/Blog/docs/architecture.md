# Architecture decision records

## ADR-001: layered, replaceable persistence
HTTP delegates to `BlogService`; domain policy is pure; infrastructure adapters hold persistence, cache, and observability. The default repository is memory-only to make plugin boot deterministic. Production must provide a transactional repository using parameterized SQL and the supplied indexes.

## ADR-002: authorization at the inbound boundary
Every request derives a role from the trusted `blog.actor` request attribute and checks `BlogAuthorization` before application access. This protects against client-side bypass (OWASP Broken Access Control). The host authentication middleware owns attribute creation.

## ADR-003: mutation consistency
Every mutation invalidates read cache, emits a structured audit/event record with before/after values, and increments a named counter. Subscribers can consume `blog.audit` logs or replace `BlogObservability` with an event-bus webhook adapter. Do not synchronously call untrusted webhook URLs in the request path; use an outbox worker with signed delivery and retries.

## Performance and security
List requests filter, sort, and paginate in one repository query in production: `O(log n + page_size)` with the listed indexes; full-text search uses the GIN index. Allowlisted sort fields prevent SQL identifier injection, request bodies are type-checked and HTML is stripped at the boundary (XSS defense), and comments are rate limited. Configure output CSP, CSRF protections for cookie sessions, TLS, secure auth, and database least privilege in the host application.
