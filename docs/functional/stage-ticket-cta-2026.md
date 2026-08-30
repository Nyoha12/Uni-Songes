# Stage ticket CTA — 2026

Status: implemented and validated by static checks, the complete local DDEV
runtime/HTTP matrix, and an anonymous Chromium browser.

## Scope

This change exposes the existing `field_linked_ticket` relationship on a
published Stage rendered in the `full` view mode. It does not add a route,
render a Commerce entity, or change ticket synchronization.

There is no Stage-specific node template in the custom theme. Full Stage pages
currently inherit Bootstrap Barrio's `node.html.twig`, while the Stage display
configuration renders date, venue, capacity, price, and body but hides
`field_linked_ticket`. The theme preprocess therefore adds one narrowly scoped
render array to the existing `content` array and leaves the inherited template
unchanged.

## Rendering contract

The ticket block is added only when all of these page conditions hold:

- the entity is a `stage` node;
- the view mode is `full`;
- the Stage is published.

The purchase CTA is exposed only when all of these ticket conditions hold:

- `field_linked_ticket` resolves to a `ticket_stage` Commerce product;
- the product is published and the current visitor may view it;
- the product canonical route is accessible to the current visitor;
- at least one linked variation is both published and viewable.

When those conditions pass, the block contains a single link labelled
“Réserver ce stage”. Its destination comes from the product's `canonical` URL;
no public path is hard-coded. The Stage never renders the linked product,
variation, add-to-cart form, or `field_linked_ticket` field.

The existing `field_ticket_price` formatter remains the primary price display
and is not duplicated. If the Stage price is empty, the ticket block shows a
fallback price only when every eligible variation has the same non-empty
price. Different currencies, different amounts, or missing variation prices
produce no fallback amount.

When the relationship is missing or fails any publication/access check, the
block contains only “Billetterie bientôt disponible” and no product link.

## Cache and access safety

The render array carries cache dependencies for the Stage, resolved product,
each inspected variation, product access, variation access, and canonical URL
access. Interface-language and country contexts are included where relevant.
This prevents a CTA decision made for one permission set, entity state, locale,
or price format from being reused incorrectly.

## Static case matrix

| Case | Expected Stage output |
| --- | --- |
| Linked, published and accessible ticket with a published/accessible variation | One canonical “Réserver ce stage” link; the known Stage price stays visible, or one unambiguous variation price is used as fallback; no add-to-cart form. |
| Linked product or all linked variations unpublished/inaccessible | No product link; controlled “Billetterie bientôt disponible” status. |
| Missing or broken relationship | No product link; controlled “Billetterie bientôt disponible” status. |

Unpublished Stage nodes and non-full render modes receive no additional ticket
block.

## Explicit non-changes

- No ticket/product/variation creation behavior changed.
- No product price or Stage price value changed.
- No capacity or availability logic changed.
- No Commerce product, script, module business logic, schema, or synced config
  changed.
- No versioned global CSS, public URL, DNS, routing, tunnel, DDEV, Composer, or
  VPS file changed.
- Existing scoped classes `unisonges-detail-section`,
  `unisonges-price-note`, and `unisonges-offer-card__cta` are reused.

## Repository validation

Run from the repository root without DDEV:

```bash
set -euo pipefail
git fetch --no-tags origin release/prod
base_ref=origin/release/prod

test "$(git merge-base HEAD "$base_ref")" = "$(git rev-parse "$base_ref")"
test "$(git rev-list --left-right --count "$base_ref"...HEAD | cut -f1)" = 0
git diff --check "$base_ref"...HEAD
git diff --check "$base_ref" --

expected_files="$(printf '%s\n' \
  docs/functional/stage-ticket-cta-2026.md \
  drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme |
  LC_ALL=C sort)"
changed_files="$({
  git diff --name-only --no-renames "$base_ref" --
  git ls-files --others --exclude-standard
} | LC_ALL=C sort -u)"
diff -u <(printf '%s\n' "$expected_files") <(printf '%s\n' "$changed_files")
```

The synced display configuration is a useful static guard, but not proof of
the active runtime display:

```bash
python3 - <<'PY'
from pathlib import Path

stage = Path(
    "drupal/config/sync/core.entity_view_display.node.stage.default.yml"
).read_text(encoding="utf-8")
product = Path(
    "drupal/config/sync/core.entity_view_display.commerce_product.default.default.yml"
).read_text(encoding="utf-8")

assert "  field_linked_ticket: true" in stage
assert "\n  variations:\n" in product
assert product.count("type: commerce_add_to_cart") == 1
print("hidden Stage relationship + one default Commerce formatter: OK")
PY
```

PHP syntax is checked in an isolated official PHP CLI container, with the
workspace mounted read-only, no image pull, and networking disabled:

```bash
docker image inspect php:8.3-cli >/dev/null
docker run --rm --pull=never --network none \
  -v "$PWD:/workspace:ro" \
  -w /workspace \
  php:8.3-cli \
  php -l drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme
```

The following isolated harness loads the real theme file, calls
`unisonges_theme_preprocess_node()`, and inspects the resulting render arrays.
Its doubles do not claim to replace Drupal runtime coverage; they make the
published, unavailable, access, scope, price, and no-form branches
reproducible without DDEV. The double checks cache contexts, while Drupal's
real cache tags and semantic transitions were asserted at runtime:

```bash
docker run --rm --pull=never --network none -i \
  -v "$PWD:/workspace:ro" -w /workspace php:8.3-cli php <<'PHP'
<?php

namespace Drupal\Core\Cache {
  final class CacheableMetadata {
    private array $contexts = [];
    public function addCacheableDependency(mixed $dependency): static {
      foreach ($dependency->contexts ?? [] as $context) {
        $this->contexts[$context] = $context;
      }
      return $this;
    }
    public function addCacheContexts(array $contexts): static {
      foreach ($contexts as $context) {
        $this->contexts[$context] = $context;
      }
      return $this;
    }
    public function applyTo(array &$build): void {
      $build['#cache']['contexts'] = array_values($this->contexts);
    }
  }
}

namespace Drupal\Core\Language {
  interface LanguageInterface {
    public const TYPE_INTERFACE = 'language_interface';
  }
}

namespace Drupal\node {
  interface NodeInterface {}
}

namespace Drupal\commerce_product\Entity {
  interface ProductInterface {}
  interface ProductVariationInterface {}
}

namespace {
  function t(string $text, array $args = []): string {
    return strtr($text, $args);
  }

  final class Drupal {
    public static function service(string $id): object {
      return new class {
        public function format(string $number, string $currency, array $options): string {
          return "$number $currency";
        }
      };
    }
  }

  final class AccessResultStub {
    public array $contexts = ['user.permissions'];
    public function __construct(private bool $allowed) {}
    public function isAllowed(): bool {
      return $this->allowed;
    }
  }

  final class UrlStub {
    public function __construct(public string $path, private bool $allowed = TRUE) {}
    public function access(mixed $account = NULL, bool $returnAsObject = FALSE): AccessResultStub {
      return new AccessResultStub($this->allowed);
    }
  }

  final class PriceStub {
    public function __construct(private string $number, private string $currency) {}
    public function getNumber(): string {
      return $this->number;
    }
    public function getCurrencyCode(): string {
      return $this->currency;
    }
    public function equals(self $other): bool {
      if ($this->currency !== $other->currency) {
        throw new RuntimeException('Currency mismatch reached equals().');
      }
      return $this->number === $other->number;
    }
  }

  final class FieldStub {
    public function __construct(public mixed $entity = NULL, private bool $empty = TRUE) {}
    public function isEmpty(): bool {
      return $this->empty;
    }
  }

  final class VariationStub implements
    \Drupal\commerce_product\Entity\ProductVariationInterface {
    public function __construct(
      private bool $published,
      private bool $viewable,
      private ?PriceStub $price = NULL,
    ) {}
    public function isPublished(): bool {
      return $this->published;
    }
    public function access(string $operation, mixed $account = NULL, bool $returnAsObject = FALSE): AccessResultStub {
      return new AccessResultStub($this->viewable);
    }
    public function getPrice(): ?PriceStub {
      return $this->price;
    }
  }

  final class ProductStub implements \Drupal\commerce_product\Entity\ProductInterface {
    public function __construct(
      private bool $published,
      private bool $viewable,
      public UrlStub $url,
      private array $variations,
      private string $bundle = 'ticket_stage',
    ) {}
    public function bundle(): string {
      return $this->bundle;
    }
    public function isPublished(): bool {
      return $this->published;
    }
    public function access(string $operation, mixed $account = NULL, bool $returnAsObject = FALSE): AccessResultStub {
      return new AccessResultStub($this->viewable);
    }
    public function toUrl(string $rel): UrlStub {
      return $this->url;
    }
    public function getVariations(): array {
      return $this->variations;
    }
  }

  final class NodeStub implements \Drupal\node\NodeInterface {
    private array $fields;
    public function __construct(
      ?ProductStub $product,
      bool $hasStagePrice = TRUE,
      private bool $published = TRUE,
      private string $bundle = 'stage',
    ) {
      $this->fields = [
        'field_linked_ticket' => new FieldStub($product, $product === NULL),
        'field_ticket_price' => new FieldStub(NULL, !$hasStagePrice),
      ];
    }
    public function bundle(): string {
      return $this->bundle;
    }
    public function isPublished(): bool {
      return $this->published;
    }
    public function hasField(string $name): bool {
      return isset($this->fields[$name]);
    }
    public function get(string $name): FieldStub {
      return $this->fields[$name];
    }
  }

  function check(bool $condition, string $message): void {
    if (!$condition) {
      throw new RuntimeException($message);
    }
  }

  function countType(mixed $value, string $type): int {
    if (!is_array($value)) {
      return 0;
    }
    $count = (($value['#type'] ?? NULL) === $type) ? 1 : 0;
    foreach ($value as $child) {
      $count += countType($child, $type);
    }
    return $count;
  }

  function containsKey(mixed $value, string $key): bool {
    if (!is_array($value)) {
      return FALSE;
    }
    if (array_key_exists($key, $value)) {
      return TRUE;
    }
    foreach ($value as $child) {
      if (containsKey($child, $key)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  function build(NodeStub $node, string $viewMode = 'full'): ?array {
    $variables = ['node' => $node, 'view_mode' => $viewMode, 'content' => []];
    unisonges_theme_preprocess_node($variables);
    return $variables['content']['unisonges_stage_ticket_cta'] ?? NULL;
  }

  function checkUnavailable(?array $build, string $case): void {
    check(is_array($build), "$case: missing block");
    check(!isset($build['link']), "$case: unexpected link");
    check(
      ($build['status']['#value'] ?? '') === 'Billetterie bientôt disponible',
      "$case: missing controlled status",
    );
    check(countType($build, 'form') === 0, "$case: unexpected form");
    check(!containsKey($build, '#lazy_builder'), "$case: unexpected lazy builder");
  }

  $themePath = '/workspace/drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme';
  $themeSource = file_get_contents($themePath);
  foreach ([
    'commerce_add_to_cart',
    'commerce_cart',
    'entity_view',
    'getViewBuilder',
    "'#lazy_builder'",
    "'#type' => 'form'",
  ] as $forbidden) {
    check(!str_contains($themeSource, $forbidden), "forbidden Commerce render: $forbidden");
  }
  require $themePath;

  $variation = new VariationStub(TRUE, TRUE, new PriceStub('20.00', 'EUR'));
  $url = new UrlStub('/product/fixture');
  $product = new ProductStub(TRUE, TRUE, $url, [$variation]);
  $available = build(new NodeStub($product));
  check(($available['link']['#type'] ?? '') === 'link', 'available: link missing');
  check(($available['link']['#title'] ?? '') === 'Réserver ce stage', 'available: label');
  check(($available['link']['#url'] ?? NULL) === $url, 'available: canonical URL');
  check(!isset($available['status']) && !isset($available['price']), 'available: duplicate output');
  check(countType($available, 'link') === 1, 'available: link count');
  check(countType($available, 'form') === 0, 'available: form count');
  check(!containsKey($available, '#lazy_builder'), 'available: lazy builder');
  check(in_array('languages:language_interface', $available['#cache']['contexts'], TRUE), 'language context');
  check(in_array('user.permissions', $available['#cache']['contexts'], TRUE), 'access context');

  checkUnavailable(build(new NodeStub(new ProductStub(FALSE, TRUE, $url, [$variation]))), 'unpublished product');
  checkUnavailable(build(new NodeStub(NULL)), 'missing relationship');
  checkUnavailable(build(new NodeStub(new ProductStub(TRUE, TRUE, $url, []))), 'no variation');
  checkUnavailable(build(new NodeStub(new ProductStub(TRUE, TRUE, $url, [new VariationStub(FALSE, TRUE)]))), 'unpublished variation');
  checkUnavailable(build(new NodeStub(new ProductStub(TRUE, TRUE, $url, [new VariationStub(TRUE, FALSE)]))), 'inaccessible variation');
  checkUnavailable(build(new NodeStub(new ProductStub(TRUE, FALSE, $url, [$variation]))), 'inaccessible product');
  checkUnavailable(build(new NodeStub(new ProductStub(TRUE, TRUE, new UrlStub('/product/fixture', FALSE), [$variation]))), 'inaccessible canonical');
  checkUnavailable(build(new NodeStub(new ProductStub(TRUE, TRUE, $url, [$variation], 'default'))), 'wrong bundle');

  check(build(new NodeStub($product), 'teaser') === NULL, 'teaser gate');
  check(build(new NodeStub($product, TRUE, FALSE)) === NULL, 'publication gate');
  check(build(new NodeStub($product, TRUE, TRUE, 'page')) === NULL, 'bundle gate');

  $fallback = build(new NodeStub($product, FALSE));
  check(($fallback['price']['#value'] ?? '') === 'Tarif : 20.00 EUR', 'price fallback');
  check(in_array('country', $fallback['#cache']['contexts'], TRUE), 'price context');
  $mixed = new ProductStub(TRUE, TRUE, $url, [
    new VariationStub(TRUE, TRUE, new PriceStub('20.00', 'EUR')),
    new VariationStub(TRUE, TRUE, new PriceStub('20.00', 'USD')),
  ]);
  check(!isset(build(new NodeStub($mixed, FALSE))['price']), 'mixed-currency fallback');

  echo "render-array cases: OK\n";
}
PHP
```

No Twig file changes in this patch. Twig and active Drupal/Commerce rendering
were verified by the local DDEV and Chromium runs recorded below.

Immediately before commit, then after commit, run:

```bash
git diff --cached --check
git diff --check origin/release/prod...HEAD
```

## DDEV and Chromium validation protocol

The following protocol was executed with exclusive ownership of the local DDEV
project. It separates code identity, transaction rollback, anonymous HTTP and
browser rendering, cache transitions, and targeted cleanup.

### Runtime preflight and code identity

With exclusive ownership, fail before creating fixture data unless every check
passes. The minimal local `standard` database did not contain the Stage and
ticket configuration required by the repository. Its complete active
configuration and theme state were recorded first. Only the missing local
prerequisites were then created through Drupal entity APIs from individual
synced definitions, with every temporary item recorded for targeted cleanup.
No configuration import was run.

1. Record the PR head SHA, a clean-worktree status, the DDEV version,
   `ddev describe`, `drush status`, PHP version, base URL, and active theme.
2. Compare the SHA-256 of
   `drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme` in this
   worktree with `/var/www/html/web/themes/custom/unisonges_theme/unisonges_theme.theme`
   in the running web container. A mismatch means DDEV is not serving PR #74:
   stop and arrange an owner-controlled checkout/mount. Do not silently copy
   over the shared checkout or its `.ddev` files.
3. Verify in active configuration that the `stage` fields, `ticket_stage`
   product and variation types, EUR currency, one unambiguous published store,
   anonymous `view commerce_product` permission, the required event-date field,
   and the venue/capacity fields exist.
4. Resolve the active product view display and require its `variations`
   component to use `commerce_add_to_cart`. The repository has no synced
   `commerce_product.ticket_stage.default` display, so the minimal local test
   creates that temporary display through the entity API after recording the
   baseline, then removes it during targeted cleanup. Never run a
   configuration import.
5. Run one `drush cr` after the correct code is mounted. Do not rebuild caches
   again during the state-transition test.

Use a fixed, collision-checked marker derived from the tested head, for example
`PR74-STAGE-CTA-<12-char-sha>`. Store all exact IDs, URLs, title, SKU, code hash,
and the database-backup path in a manifest under a fresh `/tmp` evidence
directory. Refuse to proceed if the marker, title, or SKU already exists.

### Transaction and fixture lifecycle

Run two deliberately separate lifecycles:

- **Rollback probe:** in one `drush php:script` process, open a database
  transaction, create marked entities, then use `account_switcher` with an
  `AnonymousUserSession` and assert that the active account ID is `0`. Render
  the Stage and exercise available, unpublished-product,
  no-published-variation, missing-field, and no-form assertions. In nested
  `finally` blocks, switch the original account back, call `rollBack()`, and
  invalidate the captured entity tags. A second Drush process must find zero
  entities by the recorded IDs, title, and SKU. The inaccessible-product and
  inaccessible-variation branches remain in the static harness unless the
  active site provides a reversible access mechanism that needs no config
  change. This is the deterministic transaction-rollback proof.
- **Browser fixture:** HTTP/PHP-FPM requests use another database connection
  and cannot see an uncommitted Drush transaction. Create this fixture in a
  transaction that rolls back on setup failure but commits after validation.
  Install an `EXIT`/`INT`/`TERM` cleanup trap before committing it. Export the
  local database first as an emergency restoration fallback. The test requires
  exclusive ownership and no concurrent cron, traffic, or other database
  writer; the dump is not restored when targeted cleanup succeeds.

Create and validate the fallback dump before setup:

```bash
EVIDENCE_DIR="$(mktemp -d /tmp/pr74-stage-ticket-cta.XXXXXX)"
DB_BACKUP="$EVIDENCE_DIR/pre-test.sql.gz"
(cd "$DDEV_ROOT" && ddev export-db --file="$DB_BACKUP")
test -s "$DB_BACKUP"
```

Create the browser fixture through Drupal entity APIs in this order:

1. one published `ticket_stage` variation with the marked SKU, `37.00 EUR`,
   and capacity `12`;
2. one published `ticket_stage` product using the validated store, the marked
   title, and that variation;
3. one published Stage with fixed future dates, venue/capacity, the product in
   `field_linked_ticket`, Pathauto disabled, and an empty Stage price so the
   safe variation-price fallback is exercised.

Creating the product before the Stage avoids the autosync branch that hardcodes
store ID `1`. The product's required reverse page relation is transiently empty
inside the setup transaction; the Stage hook must populate it and full entity
validation (`validate()->count() === 0`) must pass for the Stage, product, and
variation before the transaction commits. Any violation rolls setup back. Once
saved, never save the Stage during state transitions: its insert/update hook
can recreate or republish the ticket.

The targeted cleanup must use only manifest IDs and verify their type/marker
before deleting Stage, product, and any remaining variation. Product and
variation absence is allowed after the missing-target step, but no different
entity may have reused an ID. The cleanup must also check for an exact
`/node/<nid>` alias and any order item referencing the variation. Commit the
targeted cleanup transaction, invalidate the three entity cache tags, then use
a fresh Drush process, before any dump import, to require all of the following
to be zero:

- loads by Stage, product, and variation ID;
- entity queries by marked node/product title and variation SKU;
- products whose `field_linked_page` targets the Stage;
- exact path aliases for the Stage system path;
- order items whose purchased entity is the variation.

No purchase control is submitted, so the test must not create a cart or order.
An advanced auto-increment value is not a fixture entity, but any marked entity
or reference is a blocking cleanup failure. Delete the fallback dump only
after these assertions pass. If cleanup fails before commit, roll its
transaction back. If the fresh-process verification fails after commit, that
cleanup transaction can no longer be rolled back. In either case, retain the
dump and evidence, stop, and request the DDEV owner's confirmation before
running `(cd "$DDEV_ROOT" && ddev import-db --file="$DB_BACKUP")`. Only import
after confirming that no non-fixture write occurred since the export, then
rebuild caches and repeat the zero-residue checks in a fresh process.

### Anonymous HTTP and browser matrix

Use a fresh cookie-free HTTP client and a private browser context with locale
`fr-FR`, timezone `Europe/Paris`, viewport `1440x1000`, and no stored session.
For HTTP evidence, fetch without a query string or cache-bypass header and save
headers, body, final URL, status, redirect count, and SHA-256 for every response:

```bash
fetch() {
  label="$1"
  path="$2"
  curl --silent --show-error --location --max-redirs 3 \
    --connect-timeout 10 --max-time 30 --cookie /dev/null \
    --header 'Accept: text/html' \
    --header 'Accept-Language: fr-FR,fr;q=0.9' \
    --dump-header "$EVIDENCE_DIR/$label.headers" \
    --output "$EVIDENCE_DIR/$label.html" \
    --write-out 'status=%{http_code}\nfinal=%{url_effective}\nredirects=%{num_redirects}\n' \
    "$BASE_URL$path" | tee "$EVIDENCE_DIR/$label.meta"
  sha256sum "$EVIDENCE_DIR/$label.headers" \
    "$EVIDENCE_DIR/$label.html" >> "$EVIDENCE_DIR/SHA256SUMS"
}
```

Apply this sequence. Each Stage state is fetched twice at the exact same URL;
the first response after a save and the second warm response must both match.

| Step | Mutation, with no cache rebuild | Stage expectation | Product expectation |
| --- | --- | --- | --- |
| 1 | Initial published product and published variation | HTTP 200; one canonical CTA; one safe fallback price; no unavailable status; zero Commerce add-to-cart forms/buttons. | Anonymous canonical HTTP 200, no login redirect; exactly one normal add-to-cart form and one visible, enabled submit control. |
| 2 | Unpublish only the variation through the entity API | HTTP 200; zero CTA; one controlled unavailable status; zero Commerce add-to-cart forms/buttons. | Published product remains HTTP 200 but has zero usable add-to-cart forms/buttons. |
| 3 | Republish the variation | The CTA and price return on both identical-URL fetches. | Exactly one normal purchase form/control returns. |
| 4 | Unpublish only the product | HTTP 200; zero CTA; one controlled unavailable status; no product link. | Anonymous canonical access is denied (403/404), never 200 or a login detour. |
| 5 | Republish the product | The CTA returns on both identical-URL fetches. | Anonymous canonical HTTP 200 with exactly one form/control. |
| 6 | Delete the linked fixture product without resaving the Stage | HTTP 200; unresolved/cleared relationship gives zero CTA, one controlled unavailable status, and no broken link. | Product no longer resolves. |

The autosync hook ordinarily prevents a saved Stage relationship from
remaining empty. The exact empty-field browser fixture was created through the
Entity API while suppressing only that hook implementation for the one setup
save; the module remained installed, active configuration was unchanged, and
no fixture row was written directly with SQL. The earlier anonymous HTTP run
also covered deletion of a linked target without resaving the Stage.

Capture `X-Drupal-Cache` and `X-Drupal-Dynamic-Cache` when present, but do not
require `HIT`: the active performance configuration may set page max age to
zero. Cache correctness is the semantic transition
available -> unavailable -> available on the first and second identical-URL
GET after each product/variation save, with no `drush cr` and no cache buster.

### DOM assertions and evidence

Scope Stage assertions to
`article.node--type-stage.node--view-mode-full`. Identify the ticket container
by its exact `Billetterie` heading, then require:

- available: exactly one `a.unisonges-offer-card__cta` with exact accessible
  name `Réserver ce stage`; its absolute `href` equals the fixture product's
  canonical URL; exactly one visible price containing `37` and the active EUR
  formatting; no controlled status;
- unavailable/missing: zero CTA, exactly one
  `Billetterie bientôt disponible`, and zero links to the product path;
- every Stage state: zero
  `form:has(input[name="form_id"][value^="commerce_order_item_add_to_cart_form"])`
  and zero `.button--add-to-cart`. Other unrelated forms do not affect this
  assertion.

On the available product canonical page, require exactly one
`form:has(input[name="form_id"][value^="commerce_order_item_add_to_cart_form"])`
and exactly one visible, enabled `.button--add-to-cart` inside it. Do not submit
the form. In the no-usable-variation state, both counts must be zero. Follow the
CTA once in the private context and require final HTTP 200 on the expected
origin/path with no `/user/login` redirect.

Save fixed-name full-page and ticket-container screenshots for the initial
available state and the variation-unpublished controlled fallback. Retain a
manifest of browser/version, viewport, locale, timezone, IDs/paths, all other
state transitions, DOM counts, response metadata, code hashes, and cleanup
results.

Any wrong status/final URL, simultaneous CTA and unavailable status, stale
first or second response, Stage Commerce form/button, product form/control
count other than the expected zero or one, invisible/disabled purchase control,
price omission/duplication, code-hash mismatch, or cleanup residue blocks
runtime sign-off. Do not merge or mark the pull request ready on failure.

## Executed DDEV and Chromium validation — 30 August 2026

The final matrix ran only in the local Codespace against application commit
`da424698ceeba43db036257f3317124926748505`, rebased on
`origin/release/prod` at `ddff8cc30aadfc64f1120a9cce7a33095260c5a2`.
The theme file served by DDEV matched the worktree SHA-256
`bc4e92203c491c33d006a0fddcb0961d0b5512dc216444a3fa2d11bf190b1ab4`.
DDEV 1.25.3 served Drupal 11.3.3 on PHP 8.3.31 and MariaDB 10.11. No VPS,
production URL, config import, DNS, or routing change was used.

The starting local database was a standard installation without the Stage and
ticket bundles needed for the cases. The test installed `datetime_range`,
`commerce_cart`, Bootstrap Barrio, and the Uni-Songes theme temporarily, then
created only the prerequisite active configuration through Drupal APIs from
individual repository YAML definitions. It made Uni-Songes the temporary
default theme and retained Claro as admin theme. This was not a config import.

Playwright 1.62.1 and Chromium 151.0.7922.34 were installed only under `/tmp`.
The anonymous context used `fr-FR`, `Europe/Paris`, a `1440x1000` viewport, and
`http://127.0.0.1:8080`. Browser routing rejected every non-local origin; none
was attempted.

| Fixture/state | Result |
| --- | --- |
| Published accessible ticket: Stage 32, product 23, variation 23 | Stage HTTP 200, exactly one visible CTA and `Tarif : 37,00 €`, zero Commerce forms/controls. A real click reached canonical `/product/23` with HTTP 200 and no login redirect; the product had exactly one visible, enabled normal purchase control in exactly one add-to-cart form. |
| Unpublished linked product: Stage 33, product 24 | Zero CTA and exactly one controlled unavailable status. |
| No linked ticket: Stage 34 | Zero CTA and exactly one controlled unavailable status. |
| No published usable variation: Stage 35, product 25 | Zero CTA and exactly one controlled unavailable status; the canonical product had zero add-to-cart forms/controls. |
| Wrong product bundle: Stage 36, product 26 | Zero CTA and exactly one controlled unavailable status. |
| Cache transitions on Stage 32 | Available -> variation unpublished -> restored -> product unpublished -> restored was correct on both identical-URL GETs per state. Each first response was `MISS` and each warm response `HIT`. There was no cache rebuild or manual Stage-tag invalidation after any entity save. |

The preceding anonymous HTTP/DOM run additionally proved that the unpublished
product canonical page returned 403 and the deleted target returned 404 while
the Stage failed closed. Its rollback probe created and rendered marked Stage,
ticket, and wrong-bundle entities inside a transaction; a second Drush process
found every recorded ID, title, SKU, alias, reverse reference, and order-item
reference absent after rollback.

Chromium measured the CTA at `197.953125x66.140625` CSS pixels with
`inline-flex`, visible opacity, active pointer events, and bounds inside both
the viewport and `#unisonges-scrollframe`. All five interior
`elementFromPoint()` probes hit the CTA; a trial click and the real navigation
both passed. The custom theme stylesheet returned HTTP 200. Console errors,
page errors, external requests, failed local requests, abnormal local
responses, and PHP problem markers were all zero.

The available and unavailable full-page and ticket-section screenshots are in
`/tmp/pr74-stage-ticket-browser.FeQJGm/`. The marker was
`PR74-STAGE-CTA-DA424698CEEB`. Fresh-process cleanup checks found zero marked
nodes, products, variations, SKUs, aliases, order items, reverse references,
or loadable fixture IDs. The temporary matrix configuration fingerprint was
identical before and after its fixtures:
`371:420de252e628624da0636ed14637f161ad7fe4a143b8197d4fb8ac39394cd6f4`.

Final cleanup restored the exact original 314-object active-configuration
snapshot and fingerprint
`314:e96a6b849b5e15c6e16fde5b6494a9e57fe9f7161dd8398c819963ddfdfc2127`.
Full normalized configuration and theme/module-state diffs were empty;
Olivero/Claro were restored, deleted field/storage definitions and temporary
field tables were zero, the main checkout was clean on `release/prod`, and
DDEV was stopped.
