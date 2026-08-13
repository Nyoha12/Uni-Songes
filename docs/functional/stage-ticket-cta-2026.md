# Stage ticket CTA — 2026

Status: implemented and statically validated; browser/DDEV verification remains
pending, so the pull request must stay in draft.

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
- No global CSS, URL, DNS, routing, tunnel, DDEV, Composer, or VPS file/action
  changed.
- Existing scoped classes `unisonges-detail-section`,
  `unisonges-price-note`, and `unisonges-offer-card__cta` are reused.

## Repository validation

Run from the repository root without DDEV:

```bash
git diff --check

expected_files="$(printf '%s\n' \
  docs/functional/stage-ticket-cta-2026.md \
  drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme |
  LC_ALL=C sort)"
changed_files="$({
  git diff --name-only release/prod --
  git ls-files --others --exclude-standard
} | LC_ALL=C sort -u)"
diff -u <(printf '%s\n' "$expected_files") <(printf '%s\n' "$changed_files")
```

The three required static cases and the absence of a second Commerce form are
checked with source assertions:

```bash
python3 - <<'PY'
from pathlib import Path

source = Path(
    "drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme"
).read_text(encoding="utf-8")

cases = {
    "linked published ticket": (
        "$product->isPublished()",
        "$product_access->isAllowed() && $url_access->isAllowed()",
        "$variation->isPublished()",
        "$variation_access->isAllowed()",
        "$product->toUrl('canonical')",
        "'#title' => t('Réserver ce stage')",
    ),
    "linked unpublished ticket": (
        "if ($product->isPublished())",
        "if (!$variation->isPublished())",
        "else {",
        "'#value' => t('Billetterie bientôt disponible')",
    ),
    "missing ticket": (
        "$product = NULL",
        "!$node->get('field_linked_ticket')->isEmpty()",
        "'#value' => t('Billetterie bientôt disponible')",
    ),
}

for case, needles in cases.items():
    missing = [needle for needle in needles if needle not in source]
    assert not missing, f"{case}: missing assertions: {missing}"
    print(f"{case}: OK")

for forbidden in ("commerce_add_to_cart", "commerce_cart", "entity_view"):
    assert forbidden not in source, f"forbidden Commerce render: {forbidden}"
print("duplicate add-to-cart guard: OK")
PY
```

PHP syntax is checked in an isolated official PHP CLI container, with the
workspace mounted read-only and networking disabled for the check itself:

```bash
docker run --rm --network none \
  -v "$PWD:/workspace:ro" \
  -w /workspace \
  php:8.3-cli \
  php -l drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme
```

No Twig file changes in this patch. Twig runtime rendering and the three cases
above remain covered by the pending browser/DDEV verification.

Immediately before commit, then after commit, run:

```bash
git diff --cached --check
git diff --check release/prod...HEAD
```

## Pending browser/DDEV verification

Once the tunnel owner releases DDEV, keep the pull request in draft and verify:

1. A published Stage linked to a published ticket shows one CTA, the expected
   price, and a canonical product link.
2. The canonical product page is reachable anonymously and contains exactly
   one working add-to-cart form.
3. The Stage page itself contains no add-to-cart form.
4. Unpublishing the linked product, then its variation, removes the link and
   shows only the controlled status.
5. Removing the relationship produces the same controlled status with no
   broken URL.
6. Teaser listings and unpublished Stage previews do not gain the full-page
   ticket block.
7. Cache rebuilds and anonymous/authenticated views do not expose stale CTA or
   price states.

DDEV was intentionally not used during this implementation because it is owned
by the active tunnel workflow.
