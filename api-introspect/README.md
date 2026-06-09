# api-introspect

Standalone OPNsense API inventory generator. Boots the OPNsense MVC framework
from the sibling `opnsense` git submodule (read-only — nothing is written into
that checkout) and walks the live controller and model classes via reflection
to produce a machine-readable inventory of every `/api/...` endpoint.

## Layout

```
api-introspect/            # this tool — lives beside opnsense/, not inside it
  composer.json            # PSR-4 autoload + platform (ext-*) requirements
  introspect.php           # CLI entry
  src/
    Bootstrap.php          # locates + boots the opnsense submodule
    ControllerDiscovery.php
    DiscoveredController.php
    RouteDeriver.php        # camel<->snake transform (inverse of Router::parsePath)
    ReturnAnalyzer.php      # AST analysis of action bodies (return-shape inference)
    VerbDetector.php
    ModelSchemaExtractor.php
    AclIndex.php
    NonModelScanner.php
    ContractGuard.php       # fingerprints the base methods soundness depends on
    Emitter/OpenApiEmitter.php
  fixtures/conf/config.xml  # minimal config the framework boots against
  verify-contract.php       # CLI: check / re-bless the contract lock
  base-contract.lock.json   # blessed fingerprints of the base-method surface
  tests/RouteDeriverTest.php
../opnsense/               # the OPNsense source, as a git submodule
```

`ReturnAnalyzer` (built on `nikic/php-parser`) recovers each action's return shape
where it is **declared** (so inherited base actions resolve too), in three
authoritative tiers before falling back to a guess — recorded per operation in
`direction_source`:

1. **`wrapper:<method>`** — the action returns a base CRUD wrapper
   (`getBase`/`searchBase`/`setBase`/`addBase`/`delBase`/`toggleBase`/…); direction
   is fixed and the body key + model path are read from its string-literal args.
2. **`base-action:<get|set>Action`** — the action is the *inherited, un-overridden*
   base `getAction`/`setAction`, grounded on declaring-class identity (an override
   would change the declaring class). These build their result with a dynamic
   `static::$internalModelName` key the AST can't read, so they are resolved from
   the base contract: the whole model node tree under the model name.
3. **`name-heuristic`** — the fallback when neither resolves (custom bodies built
   from loops, configd output, helper or `parent::` calls). Direction is then
   guessed from the method-name prefix; any literal `return [...]` keys parsed
   from the body are still surfaced under `returnKeys`.

## Requirements

- PHP >= 8.1 with `simplexml`, `dom`, `libxml`, `json`, `ctype`, `filter`.
  These are enforced by `composer.json`. The XML extensions are an OS package:

  ```sh
  sudo apt-get install php8.3-xml      # provides ext-simplexml + ext-dom
  ```

- Composer.
- The `opnsense` submodule populated:

  ```sh
  git submodule update --init
  ```

## Setup

```sh
cd api-introspect
composer install
```

## Usage

The tool emits a single artifact: an **OpenAPI 3 document** (to stdout, or
`--out`).

```sh
# OpenAPI 3 to stdout
php introspect.php

# Write to a file
php introspect.php --out openapi.json

# Restrict to one module while iterating
php introspect.php --module Firewall

# Point at an opnsense checkout elsewhere
php introspect.php --opnsense-root /path/to/core
#   or: OPNSENSE_ROOT=/path/to/core php introspect.php
```

Introspection provenance is carried on each operation as `x-opnsense-*`
extensions: `x-opnsense-direction` (read/write/command/unknown),
`x-opnsense-direction-source` (`wrapper:<method>` / `base-action:<name>` =
parsed-authoritative, or `name-heuristic` = fallback), `x-opnsense-verb-heuristic`,
`x-opnsense-controller`, and `x-opnsense-privilege`. Bodies and parameters may
also carry `x-opnsense-best-effort` (a plain-controller request scan),
`x-opnsense-options` (selectable keys for a multi-select CSV field), and
`x-opnsense-optional` (a path parameter whose action argument is optional — see
Notes).

The document itself is self-describing via a top-level `x-opnsense-introspection`
extension carrying the run metadata: `generator`, `generated_at`,
`opnsense_version`, `acl_privileges_indexed`, and `stats` — including the
`direction` and `direction_source` distributions, so you can see at a glance how
much of the spec is parsed-authoritative versus name-heuristic. (The local
checkout path is deliberately not included.)

By default the tool locates the `opnsense` directory beside itself.

## Tests

```sh
php tests/RouteDeriverTest.php
```

## Sample config

Model instantiation needs a `config.xml`. The tool ships a self-contained
minimal one at `fixtures/conf/config.xml` and points the framework at it
automatically (see `Bootstrap`). It carries no real data — just a couple of
placeholder interfaces (WAN/LAN/OPT1) so interface-style option enums resolve to
example values instead of empty lists.

The **field schema itself does not depend on config data** — only
runtime-populated option enums (interfaces, aliases, certificates, …) do, and
those are deployment-specific. The submodule also ships a richer example at
`opnsense/src/opnsense/service/tests/config/config.xml` if you want more
populated enums; it's a test fixture, so we don't couple to it by default.

## Notes / limitations

Limitations are carried into the output as provenance flags. In short: HTTP verbs
are heuristic (OPNsense enforces them inside method bodies, never declares them);
plain `ApiControllerBase` endpoints have only a best-effort request scan
(`x-opnsense-best-effort`); `__call` (dynamic-dispatch) controllers are not
statically enumerable, so they are counted in `stats.dynamic` but **not emitted**;
and runtime-populated option enums (interfaces, aliases, certificates, …) are
empty unless run against a live configured system.

Path parameters are always emitted as `required: true` — OpenAPI requires it for
every path-template placeholder. When the underlying action argument is optional
(meaning a collection-level variant of the route exists *without* that segment),
the parameter is flagged `x-opnsense-optional: true` rather than modelled as an
(invalid) optional path parameter.

`ArrayField` collections (firewall/NAT rules, routes, …) **are** expanded: each
is emitted with `"array": true` and an `items` list carrying the full per-row
field schema, derived from the array's template node even when no rows exist.

### Request vs response modelling

The OPNsense base controllers serialise the model node tree (`getNodes()`) as
the **response** of read actions and consume a subset as the **request** of
write actions. The tool classifies each action's `direction`
(`read`/`write`/`command`/`unknown`, heuristic, flagged `x-opnsense-direction`)
and emits accordingly:

- **read** (`get*`/`search*`/…): a `200` response body, no request body. A
  `get*` body is the `getNodes()` tree wrapped under the controller's body key —
  for `categorysource` controllers that key is the source's last segment (e.g.
  `rules.rule` → `rule`), otherwise the model name. `search*` actions produce a
  **different** shape (`rows`/`rowCount`/`total`/`current`) built by
  `UIModelGrid`: each row is **flat scalars** (`$field->getValue()`, not the
  `getNodes()` maps), and option/relation fields add a `%field` companion
  carrying the display label (declared optional — it is only emitted when the
  label differs from the raw value).
- **write** (`set*`/`add*`/…): a request body plus a `{result, validations}`
  response (`add*` also returns `uuid`).
- **command** (`del*`/`toggle*`/…): a `{result}` response, no body.

#### Where these shapes come from (provenance)

Every body shape is derived from a specific point in the OPNsense base classes,
not guessed. Paths are under `opnsense/src/opnsense/mvc/app/`:

| Shape | Defined in |
| --- | --- |
| `get` body = `{key: node.getNodes()}` | `controllers/OPNsense/Base/ApiMutableModelControllerBase.php` `getBase()` |
| inherited `get`/`set` body = whole model under model name | `ApiMutableModelControllerBase.php` `getAction()` / `setAction()` |
| `getNodes()` leaf = `getNodeData()` (map for list fields) | `models/OPNsense/Base/FieldTypes/BaseField.php` `getNodes()` + `BaseListField` |
| `search` grid rows (flat `getValue()` + `%field` label) | `library/OPNsense/Base/UIModelGrid.php` `fetch()` |
| write request = `setNodes(getPost(modelName))`; multi-select is CSV | `BaseField.php` `setNodes()`, `BaseListField.php` `setValues()` |
| write response `{result:"saved"}` / `{result:"failed",validations}` / `+uuid` | `ApiMutableModelControllerBase.php` `save()`, `validate()`, `addBase()` |
| `del` response `{result}`; `toggle` response `{result,changed}` | `ApiMutableModelControllerBase.php` `delBase()`, `toggleBase()` |

Field names/types/options are authoritative (read from a live model instance).
The body **wrapping/serialisation** above is derived from the base contracts;
which one an action uses is recovered by `ReturnAnalyzer` (the three tiers above):
the parsed base-wrapper call (incl. overrides like `GroupController::getAction`
calling `getBase`), or the inherited base action (`base-action:getAction` /
`setAction`, e.g. `auth/priv/get`). So `direction` and the body are authoritative
wherever a wrapper or base action resolves (`direction_source` is `wrapper:<name>`
or `base-action:<name>`). The method-name heuristic (`name-heuristic`) is the
fallback for actions that build a response dynamically (loops, configd output,
helper or `parent::` calls): direction is then taken from the name prefix
(read/write/command, or `unknown` if none matches) and any literal `return` keys
are surfaced under `returnKeys`. HTTP verbs remain heuristic (enforced inside
method bodies, never declared).

List fields serialise differently per direction, matching the framework:
in **responses** they are option maps `{key: {value, selected}}`; in
**requests** they are submitted scalars — an `enum` for single-select, or a
comma-separated string (with the selectable keys under `x-opnsense-options`) for
multi-select. `unknown` (custom) actions get a generic response and no body,
since their shape is not statically derivable.

### Soundness invariant

The spec never asserts a field that is not **provably** present. Concretely:

- No schema uses `additionalProperties: false`, so a real response may always
  carry **more** fields than documented (over-completeness is allowed; the spec
  is a lower bound on the shape, not an exact one).
- A field is marked `required` only when the body is **proven** — the direction
  came from a parsed base wrapper (`wrapper:*`) or an inherited base action
  (`base-action:*`). For a proven body the top-level key is provably present, so
  it is required: a read response always wraps under it (`{<key>: …}`), a write
  request must carry it, and a search response always has the full
  `rows`/`rowCount`/`total`/`current` envelope. Inside a write request, fields the
  model marks required are required too. For `name-heuristic` endpoints the body
  is a best-effort guess (we know the model, not how the action reshapes it), so
  **every field stays optional** — listing a property claims only "if present, it
  has this type," never that it exists.

So `x-opnsense-direction-source` doubles as a confidence flag: `wrapper:*` /
`base-action:*` bodies are exact contracts (modulo extra fields); `name-heuristic`
bodies are non-committal hints that cannot mislead a consumer into expecting a
field that may not be returned.

### Keeping it sound across versions (contract guard)

That soundness rests on the *behaviour* of a fixed set of OPNsense base methods —
the CRUD wrappers (argument order + serialised shape), the inherited
`get`/`setAction` envelope, `searchRecordsetBase`/`UIModelGrid::fetch`, and the
field node serialisation. `ControllerDiscovery` already hard-fails on structural
drift; `ContractGuard` covers the complementary risk: a base method whose body
changes meaning when you build against a newer OPNsense.

Each method in that surface is fingerprinted by its **normalised AST** (comments,
formatting and purely-syntactic variants stripped — so cosmetic edits don't trip
it) and checked against `base-contract.lock.json`. `introspect.php` runs this as
a pre-flight and **aborts on drift** (the spec could otherwise be silently
unsound), naming each changed method and any parameter reordering:

```sh
# check the lock (also suitable for CI; exits non-zero on drift)
php verify-contract.php

# after reviewing a drift and confirming the analyzer still holds, re-bless:
php verify-contract.php --update

# bypass the pre-flight while actively re-verifying:
php introspect.php --allow-contract-drift
```

The lock is blessed against a specific OPNsense version (recorded in the file).
Bumping the submodule is expected to trip the guard; the workflow is to review
the named changes, update `ReturnAnalyzer`/`OpenApiEmitter` if an assumption
moved, then re-bless. The tool never adapts to a changed base method on its own —
that manual re-bless is what preserves the soundness guarantee.
