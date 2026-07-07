# Tiger Webservices — the TIGER message pattern

*Philosophy and implementation of Tiger's API layer. Read this before adding an
endpoint or touching the `/api` gateway.*

This document adapts Beau Beauchamp's essay **"REST is Dying. Get Rid of It. —
TIGER: Advanced Easier Webservices You Can Use Today"** to how the **Tiger
platform actually implements it today**. The philosophy is his; the class names,
guards, and examples below are the running code in `tiger-core`.

---

## 1. Why not REST

REST is an architectural *style*, not a protocol, and as the web moved on its
constraints started to cost more than they gave:

- **The verbs are too few and too blunt.** GET/POST/PUT/DELETE force every
  operation into four shapes, and the responses are inconsistent — a `DELETE`
  often returns *nothing*, and a `200` tells you shockingly little.
- **Endpoint sprawl.** "37 endpoints" is normal, each versioned, each maintained.
  That's code bloat. (Zend literally shipped *Apigility* to manage the sprawl.)
- **No native contract or docs.** WSDL had this for SOAP; REST bolts on OpenAPI.

Tiger doesn't fight REST — it sidesteps it. **Your use case should drive the
technology, not the other way around.**

## 2. The one idea: a message, not a URL

TIGER is a light **JSON-RPC-ish message pattern**. There is **one endpoint,
`/api`**, and the *routing metadata travels inside the message* alongside the
payload. The message names a **service + method** (SOA) or a **controller +
action** (MVC), and Tiger routes the whole thing there.

> One endpoint. The message says where it's going. That's the whole trick.

A request is just a POST body:

```js
// fetch (modern); jQuery $.post('/api', {...}) works identically
await fetch('/api', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    module:    'account',      // which module owns the target
    service:   'user',         // -> Account_Service_User
    method:    'save',         // -> its save($params) method
    firstname: 'Thundarr',     // ...the payload rides along
    lastname:  'Barbarian',
  }),
});
```

No path to design, no verb to argue about, no new endpoint to register. Add a
method to a service and it's callable.

A **secondary URL form** exists for convenience (`GET`/`POST`
`/api/:module/:service/:action`), but the **POST-body form above is the primary
one** every client uses.

## 3. Two first-class modes

You name **one** of these in the message — they are peers, not a fallback chain:

| Mode | You send | Resolves to | Who writes the response |
|---|---|---|---|
| **Service** | `module` + `service` + `method` | `Module_Service_Service::method($params)` | the base — you call `_success()`/`_error()` |
| **Controller** | `module` + `controller` + `action` | `Module_ControllerController::actionAction()` | the controller owns it (via `_forward`) |

- **Service mode** is the default and the clean one: the service does its work and
  Tiger returns the standard envelope as JSON.
- **Controller mode** is more flexible (and more brittle, depending on your
  taste): the entire payload just lands in the action, and the controller emits
  whatever it wants. Use it to reach an existing controller action through the
  same gateway.

## 4. Writing a service

A service extends **`Tiger_Service_Service`**. The factory constructs it *with the
whole message*, and the base routes to the method the message named — which
receives the **entire payload as `$params`**. You never hand-wire arguments; the
message *is* the argument.

```php
class Account_Service_User extends Tiger_Service_Service
{
    // Called for { service: 'user', method: 'save', ... }
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) {           // ACL gate — never a role-string compare
            $this->_error('api.error.not_allowed');
            return;
        }

        $user = new Tiger_Model_User();
        $id   = $user->insert([
            'email' => $params['email'],
        ]);

        $this->_success(['user_id' => $id], 'account.user.saved');
    }
}
```

The slick part (straight from the article): by the time the object is
constructed, **all the work is done** — the caller just reads `getResponse()`.

Response helpers the base gives you:

| Helper | Effect |
|---|---|
| `_success($data, $msgKey, $redirect)` | `result=1`, payload, optional redirect + a success message |
| `_error($msgKey, $data)` | `result=0` + an error message |
| `_formErrors($form)` | `result=0` + `Zend_Form` field errors in `form` |

Message arguments are **translation keys** (`account.user.saved`) resolved in the
caller's locale — pass a plain string and it's used verbatim.

## 5. The response contract

Every service response is the **same object**, serialized as JSON — a real
contract between client and server. That consistency is the point:

```json
{
  "result":   1,          // 1 = success, 0 = failure
  "data":     { },        // service payload (object or array), or null
  "redirect": null,       // optional client-side redirect
  "form":     null,       // optional keyed field errors (Zend_Form)
  "messages": [           // zero or more feedback messages
    { "message": "Saved.", "class": "success", "field": null }
  ]
}
```

`messages[].class` maps to a Bootstrap alert context (`success`/`error`/`alert`/
`info`) so the client renders feedback without inspecting content. Specialized
consumers (DataTables, Select2) may return their own shapes — this is the default.

**DataTables (server-side processing) is built in.** A grid is client/server like
everything else: the view renders an empty `<table id>`, and rows are fetched from an
`/api` service — never server-rendered. The pattern (modeled on AskLevi):

- The service exposes a `datatable(array $params)` action. `Tiger_Service_Service`
  provides the two helpers: **`_dtParams()`** normalizes the DataTables request
  (`draw`/`start`/`length`/`search`/`order`), and **`_dtResponse($draw, $recordsTotal,
  $recordsFiltered, $data)`** emits `{draw, recordsTotal, recordsFiltered, data}` inside
  the standard envelope.
- **`data` is structured rows only — never HTML.** Each row also carries the caller's
  **server-computed permission flags** (e.g. `can_edit`, `can_delete`, privilege-checked
  via the ACL), and the client's `columns[].render` builds the cells + gates the controls
  off those flags. Authorization lives on the server; the client only draws.
- The shared client helper **`tiger.datatable.js`** (`tigerDataTable('#id', {service,
  columns, order, extraData})`) does the `/api` POST and unwraps the Tiger envelope into
  the shape DataTables consumes. See `modules/cms` (`Cms_Service_Page::datatable` + the
  content list view) for the reference implementation.

## 6. Routing & resolution

The factory resolves each field from **POST body › GET query › route params**
(the URL form uses `svc_*` names so they don't collide with ZF1's reserved
`:module/:controller/:action`). The class name is *built* from the sanitized
segments:

```
module=account, service=user   ->  Account_Service_User
module=account, controller=team ->  Account_TeamController
method/action                  ->  the method (service) / <action>Action (controller)
```

Routes (registered in `Tiger_Application_Bootstrap::_initApiRoutes`):
- `POST /api` — the message-body form (primary)
- `/api/:svc_module/:svc_service/:svc_action` — the URL form (secondary)

Both dispatch to the default-namespace `ApiController`, which hands the request to
`Tiger_Ajax_ServiceFactory` and then either `_forward`s (controller mode) or
`json_encode`s the `ResponseObject` (service mode).

## 7. Security — the gateway is paranoid on purpose

Turning message text into class names and method calls is the sharp end. Four
independent guards protect it:

1. **Reserved modules.** The framework namespaces — **`tiger`, `zend`, `core`,
   `default`, `library`, `application`** — can **never** be a `module`. Because the
   class name is `ucfirst(module)."_Service_"…`, a `module=tiger` would otherwise
   resolve `Tiger_Service_*` **kernel** code. The gateway refuses it with a generic
   error *before touching a class* — and it 's a generic failure, so the response
   never even confirms the name is special. Extend the list via
   `Tiger_Ajax_ServiceFactory::reserve('name')`. **Kernel services are not public,
   ever.**
2. **Sanitization.** Routing segments are stripped to `[a-zA-Z]` (action allows
   digits/underscore) *before* becoming class names — no `\`, `_`-injection, or
   path traversal into another namespace.
3. **Type guard.** A resolved service must actually `extends Tiger_Service_Service`,
   or it won't dispatch. And within the base, only real, public, non-`_` methods the
   message names are callable — internal helpers are unreachable.
4. **ACL, deny-by-default.** Every call is authorized before the target runs:
   resource = the target class, privilege = the action. Role comes from the
   authenticated identity (`guest` when anonymous) via `Zend_Acl` — never a raw
   role-string compare. An unknown/un-allow-listed target is *denied*, not passed.
   *(While the ACL engine is still being built the gateway fails **open**; it flips
   to fail-**closed** the moment `Tiger_Service_Acl` registers.)*

Nothing throws to the client — every failure becomes a clean `result=0` envelope,
and the details go to the log.

## 8. Where it lives (the file map)

| Piece | Class | Location |
|---|---|---|
| Gateway dispatcher | `Tiger_Ajax_ServiceFactory` | `library/Tiger/Ajax/ServiceFactory.php` |
| Base service | `Tiger_Service_Service` | `library/Tiger/Service/Service.php` |
| Response envelope | `Tiger_Model_ResponseObject` | `library/Tiger/Model/ResponseObject.php` |
| Feedback message | `Tiger_Model_MessageObject` | `library/Tiger/Model/MessageObject.php` |
| HTTP entry | `ApiController` | `core/controllers/ApiController.php` |
| Routes | — | `Tiger_Application_Bootstrap::_initApiRoutes` |

Kernel services (`Tiger_Service_Auth`, …) live in `library/Tiger/Service/` and are
**reserved** — reachable in-process (e.g. a login controller calls them), never via
`/api`. App/module services (`Account_Service_*`, `Billing_Service_*`) live in their
module and *are* the public API surface.

## 9. Discovery & versioning (the open door)

Because routing is data in the message, discovery and versioning fall out for free:
a client can pass a `version` field to target a specific service version, or a
metadata flag asking the gateway to describe available services and response models
— an OpenAPI-style "discovery" without the bolt-on. Not built yet; the pattern makes
it a small problem when a public API needs it.

## 10. The point

TIGER isn't a "recognized standard," and that's deliberate — the recognized ones
didn't fit. One secure endpoint, a consistent contract, routing that lives in the
message, and no endpoint zoo to maintain. It's less code to write, less to break,
and far easier for the next engineer (or AI) to extend: add a method to a service
and it's live.

---

*Philosophy © Beau Beauchamp ("REST is Dying", JavaScript in Plain English, 2021).
This document describes the pattern as implemented in `tiger-core`; see
[ARCHITECTURE.md](ARCHITECTURE.md) for how it fits the wider platform.*
