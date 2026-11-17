# REST admin: one panel for the APIs you already run

`RestPanel` puts a small admin panel and an MCP server in front of remote REST
APIs. It is not a CRUD generator and it does not generate PHP: an operator
writes a specification (services, resources, fields, actions and pagination)
and the panel renders it at runtime. Add a collection to the JSON and the next
page load has a table; no deploy, no class.

The module is installed and removed like every other slice:

```sh
php bin/cms module:add RestPanel
php bin/cms migrate
```

It depends on `Cardboard` (the admin shell) and nothing else. `Cardboard` does
not import it back; `make mago-guard` holds that direction.

## What it serves

| route | what it answers |
|---|---|
| `GET /mark/rest` | registered services |
| `GET/POST /mark/rest/services` | create a service |
| `GET/POST /mark/rest/services/{id}/edit` | edit its base URL, token variable, headers |
| `POST /mark/rest/services/{id}/delete` | remove it and its resources |
| `GET /mark/rest/services/{id}` | one service, its resources and their action sets |
| `GET/POST /mark/rest/services/{id}/resources` | create a resource |
| `GET/POST /mark/rest/services/{id}/resources/{resource}` | edit it, including its fields |
| `POST /mark/rest/services/{id}/resources/{resource}/delete` | remove it |
| `GET .../items` | one page of the collection |
| `GET .../items/{item}` | one member, every declared field |
| `GET/POST .../items/create`, `.../items/{item}/edit` | opt-in write forms |
| `POST .../items/{item}/delete` | opt-in delete |

Every route is under `/mark/rest`, which `MarkRoutePolicy` classifies as
`settings.manage`: configuration, not content. The longest-prefix rule covers
the child routes; a role without that permission gets 403 before a page renders.

## What it owns

- **`restpanel.db`**: services, resources, fields and relations as rows. The
  database is the runtime truth; the panel reads it on every request.
- **A versioned JSON snapshot** under `storage/data/restpanel/`, replaced
  atomically (tmp + rename) after a save. It is export, not state: a snapshot
  that cannot be written does not undo a save, and `SpecStore::load()`
  re-validates a hand-edited file before trusting it.
- **The specification model**: `ServiceSpec` is the only object a write path
  can hold, and `SpecValidator` is the only door into it. Unknown keys, an
  unknown action or pagination word, a resource path without `{id}`, a relation
  to a missing resource and a cycle are refused before anything is written.
- **The outbound seam**: `HttpRequestSpec`/`HttpResponse`, a curl transport, a
  fake transport for tests, and an SSRF guard that checks the resolved host
  against `RESTADMIN_ALLOWED_HOSTS` before `curl_init`.

### The token is never stored

A service stores `token_env`: the **name** of an environment variable, never
its value. The transport reads the variable at request time and sends it under
`Authorization: Bearer ...` (or raw, for an API-key header). A missing variable
means no header; the service answers 401 and the panel says which variable to
set. `restpanel.db`, the snapshot and every MCP answer contain the name only.

## The MCP server

`bin/cardboard-rest-mcp` speaks JSON-RPC 2.0 over stdio, one document per line.
It exposes fifteen tools: `list_services`, `register_service`,
`probe_endpoint`, `create_resource`, `update_resource`, `add_field`,
`remove_field`, `rename_field`, `list_resources`, `fetch_items`, `fetch_item`,
`create_item`, `update_item`, `delete_item`, `health`.

The protocol rules are small and tested: `initialize`, `tools/list`,
`tools/call`, `ping`, notifications without a reply, batches as one array, and
`-32_700`/`-32_600`/`-32_601`/`-32_602` for the four malformed-request cases.

Two things a caller must know:

- **`delete_item` requires `confirm: true`**, and the resource must carry
  `delete` in its action set. The two are independent: the spec opts in, the
  call states intent.
- **`update_resource` requires `confirm: true` when it changes `actions`.**
  Widening permissions is a deliberate act, not a side effect of editing a path.

A tool failure is `isError: true` with the message (and, for a 422, the field
errors); a malformed call or an unknown tool is a JSON-RPC error. The two are
not interchangeable: one is retryable, the other is not.

## What it refuses

- **A write the spec did not opt into.** `actions` defaults to `list, read`;
  the panel hides the button, the route checks again, and the remote API is
  never called.
- **A field the spec does not declare.** MCP item writes reject an unknown key
  and a `read_only` field instead of forwarding it. The panel form never renders
  a control for a key the spec does not name.
- **A host outside the allowlist.** `RESTADMIN_ALLOWED_HOSTS` (comma-separated)
  is checked by `SsrfGuard`; an empty allowlist refuses every outbound request
  rather than allowing every host.
- **A page it cannot read.** A 2xx that is not JSON, or JSON with no collection,
  is a mapping error with its own message; it is not rendered as an empty table.

## Limits, and the reasons

- **No OAuth2 PKCE or refresh.** v1 sends a static token from an environment
  variable. A refresh flow needs a token store the kernel does not have; writing
  one into `restpanel.db` would be the plaintext secret this design avoids.
- **No cookie/session authentication.** The transport sends headers, not cookies.
  A panel for a session-authenticated API needs a cookie jar and a login flow,
  which is a different product.
- **One panel, one specification.** There is no multi-tenant split: every
  service in `restpanel.db` is visible to anyone with `settings.manage`.
- **Relations are read-only in the panel.** The validator accepts `belongs_to`
  and `has_many` and the model stores them; the item form does not edit a
  relation graph in v1.
- **`per_page` is capped at 100 over MCP.** A model asking for the whole
  collection is asking the panel to hold it in memory; the cap is the answer.
- **No live health of a remote API.** `health` checks the local specification
  database. `probe_endpoint` is the explicit way to touch a service.

## Tests

`modules/RestPanel/tests/` runs offline:

- `MappingTest`, `ReaderTest`, `PaginationStrategyTest`, `ErrorMapperTest`: the
  answer shapes, against JSON fixtures taken from the reference implementation.
- `SpecValidatorTest`, `SpecStoreTest`, `SpecRepositoryTest`: the one door into
  a specification and the two places it is stored.
- `PanelTest`, `ScenarioTest`: the HTTP pages: escaping, a 422 beside its field,
  a refused write that never reaches the transport, and the full path from a
  registered service to a deleted item.
- `McpServerTest`: protocol, all fifteen tools, the delete confirmation and the
  declined undeclared field.
- `CurlTransportTest`: a real local `php -S`, a body cap and an SSRF refusal.

The fixtures live in `modules/RestPanel/tests/fixtures/` and are static JSON:
`blog-page-1`, `blog-item`, `blog-item-jsonapi`, `shop-page-1`, `oauth-page-1`,
`error-401`, and the three 422 shapes (Laravel, Django, a list of field errors).
