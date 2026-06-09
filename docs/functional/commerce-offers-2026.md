# Commerce offers 2026

This note describes the safe update path for the confirmed 2026 Uni-Songes
Commerce course offer decisions. It does not require config import and does not
edit `config/sync`.

## Confirmed decisions

| Offer | Target price | Script action |
| --- | --- | --- |
| Cours d'essai, one per account | 10 EUR | Update `/product/4` only if the product id, product type, and single variation mapping are safe. Target SKU: `COURS-ESSAI-10`. |
| Didgeridoo private lesson, 1 hour, all levels, full rate | 25 EUR | Ensure `/product/5` remains the didgeridoo full-rate product. Target SKU: `COURS-DIDGERIDOO-1H-25`. |
| Didgeridoo private lesson, 1 hour, all levels, student rate | 15 EUR | Ensure `/product/6` remains the didgeridoo student-rate product. Target SKU: `COURS-DIDGERIDOO-1H-ETUDIANT-15`. |
| Guimbarde private lesson, 1 hour, full rate | 25 EUR | Create or update a `cours_deb_inter` product/variation if absent. Target SKU: `COURS-GUIMBARDE-1H-25`. |
| Guimbarde private lesson, 1 hour, student rate | 15 EUR | Create or update a `cours_deb_inter` product/variation if absent. Target SKU: `COURS-GUIMBARDE-1H-ETUDIANT-15`. |
| Méditation / improvisation private lesson, 1 hour, full rate | 25 EUR | Create or update a `cours_deb_inter` product/variation if absent. Target SKU: `COURS-MEDITATION-IMPRO-1H-25`. |
| Méditation / improvisation private lesson, 1 hour, student rate | 15 EUR | Create or update a `cours_deb_inter` product/variation if absent. Target SKU: `COURS-MEDITATION-IMPRO-1H-ETUDIANT-15`. |
| Old packs `/product/7` and `/product/8` | N/A | Unpublish only, do not delete. |
| Old advanced course `/product/9` | N/A | Unpublish only, do not delete. |
| Stages | Existing stage publication -> ticket system | List `ticket_stage` diagnostics only. Do not create generic fixed stage products in this script. |

## Product type choice

The script reuses the existing `cours_deb_inter` product and variation type for
new guimbarde and méditation / improvisation private lesson products. This is
the safest current option because the existing course-credit logic already adds
one reservable course credit per purchased `cours_deb_inter` order item.

The script does not rename product types or variation types. Those labels are
configuration and would require a separate, explicit config change.

## Runtime behavior

- Default mode is dry-run.
- `--apply` is required for all writes.
- The script uses Drupal Commerce entity APIs through Drush `php:script`; it does
  not use raw SQL.
- Apply mode stops before writing if a target SKU/product/title match is
  ambiguous, if an expected product id has the wrong type, or if new product
  creation is needed but no unambiguous Commerce store can be selected.
- New private lesson products are created only for confirmed private lesson
  offers when no safe existing product/variation match exists.
- Products `/product/7`, `/product/8`, and `/product/9` are unpublished only when
  the product id and expected bundle match.
- `ticket_stage` products are never mutated by this script.

## Current-vs-target course map

| Current product | Target product | Target SKU | Target price | Apply behavior |
| --- | --- | --- | --- | --- |
| `/product/4` `COURS-ESSAI-20` | `Cours d'essai - 1 seance` | `COURS-ESSAI-10` | 10 EUR | Update title, SKU, and variation price if `/product/4` is safely matched. |
| `/product/5` `COURS-DEB-INTER-ADULTE-40` or `COURS-DIDGERIDOO-1H-25` | `Cours didgeridoo 1h - tous niveaux - plein tarif` | `COURS-DIDGERIDOO-1H-25` | 25 EUR | Ensure `/product/5` matches the didgeridoo full-rate target. |
| `/product/6` `COURS-DEB-INTER-ETUDIANT-30` or `COURS-DIDGERIDOO-1H-ETUDIANT-15` | `Cours didgeridoo 1h - tous niveaux - tarif etudiant` | `COURS-DIDGERIDOO-1H-ETUDIANT-15` | 15 EUR | Ensure `/product/6` matches the didgeridoo student-rate target. |
| Absent or matching guimbarde full-rate product | `Cours guimbarde 1h - tous niveaux - plein tarif` | `COURS-GUIMBARDE-1H-25` | 25 EUR | Create or update a `cours_deb_inter` product/variation. |
| Absent or matching guimbarde student product | `Cours guimbarde 1h - tous niveaux - tarif etudiant` | `COURS-GUIMBARDE-1H-ETUDIANT-15` | 15 EUR | Create or update a `cours_deb_inter` product/variation. |
| Absent or matching méditation / improvisation full-rate product | `Cours meditation / improvisation 1h - tous niveaux - plein tarif` | `COURS-MEDITATION-IMPRO-1H-25` | 25 EUR | Create or update a `cours_deb_inter` product/variation. |
| Absent or matching méditation / improvisation student product | `Cours meditation / improvisation 1h - tous niveaux - tarif etudiant` | `COURS-MEDITATION-IMPRO-1H-ETUDIANT-15` | 15 EUR | Create or update a `cours_deb_inter` product/variation. |
| `/product/7` `PACK4-DEB-INTER-ADULTE-100` | Unpublished old pack | N/A | N/A | Unpublish product only; no delete. |
| `/product/8` `PACK4-DEB-INTER-ETUDIANT-50` | Unpublished old pack | N/A | N/A | Unpublish product only; no delete. |
| `/product/9` `COURS-AVANCE-40` | Unpublished old advanced course | N/A | N/A | Unpublish product only; no delete. |

## Stage handling

Stage products remain owned by the existing stage publication -> ticket system.
The update script lists `ticket_stage` products, linked pages, variations, SKUs,
prices, and diagnostic categories only. It does not plan or apply price changes
to stage products, and it does not create generic stage products.

## Commands

Dry-run from the Drupal root:

```bash
cd drupal
./scripts/update-commerce-offers-2026.sh --dry-run
```

Apply from the Drupal root after reviewing the dry-run:

```bash
cd drupal
./scripts/update-commerce-offers-2026.sh --apply
```

Dry-run on a VPS path requires an explicit path acknowledgement:

```bash
cd /var/www/...
./scripts/update-commerce-offers-2026.sh --dry-run --allow-vps
```

Do not run `--apply` on the VPS until the dry-run output, database backup, and
product mapping have been reviewed.

## Rollback notes

- The script only saves matched Commerce product title/status fields, matched
  Commerce product variation SKU/price fields, and confirmed new private lesson
  Commerce product/variation entities.
- It does not delete products, create orders, create webform submissions, call
  Google Calendar, run config import, or edit `config/sync`.
- Before production apply, take a database backup.
- To roll back after apply, restore the database backup or manually reset the
  changed product titles, SKUs, prices, and publication statuses.
- Newly created private lesson products can be unpublished manually if the
  database backup is not restored.
- If the script reports ambiguous SKU, title, product id, product type, or store
  matches, do not apply; resolve the active Commerce data first and rerun the
  dry-run.
