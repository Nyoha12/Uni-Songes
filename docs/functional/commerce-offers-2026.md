# Commerce offers 2026

This note describes a safe update path for the 2026 Uni-Songes Commerce course
and stage offer decisions. It does not require config import and does not edit
`config/sync`.

## Current production products from diagnostic

| Product | Type | SKU | Current price | Current role |
| --- | --- | --- | --- | --- |
| `/product/4` | `cours_essai` | `COURS-ESSAI-20` | 20 EUR | Trial/private course offer. |
| `/product/5` | `cours_deb_inter` | `COURS-DEB-INTER-ADULTE-40` | 40 EUR | Beginner/intermediate adult course. |
| `/product/6` | `cours_deb_inter` | `COURS-DEB-INTER-ETUDIANT-30` | 30 EUR | Beginner/intermediate student course. |
| `/product/7` | `pack_4_deb_inter` | `PACK4-DEB-INTER-ADULTE-100` | 100 EUR | Four-course adult pack. |
| `/product/8` | `pack_4_deb_inter` | `PACK4-DEB-INTER-ETUDIANT-50` | 50 EUR | Four-course student pack. |
| `/product/9` | `cours_avance` | `COURS-AVANCE-40` | 40 EUR | Advanced course. |

The update script also lists active `ticket_stage` products and variations at
runtime because stage products are generated and maintained by the existing
stage publication/ticket system.

## 2026 target decisions

| Offer | Target price | Script action |
| --- | --- | --- |
| Didgeridoo private lesson, 1 hour, all levels, full rate | 25 EUR | Update the existing adult beginner/intermediate product if the old or new SKU maps to exactly one `cours_deb_inter` product variation. |
| Didgeridoo private lesson, 1 hour, all levels, student rate | 15 EUR | Update the existing student beginner/intermediate product if the old or new SKU maps to exactly one `cours_deb_inter` product variation. |
| Didgeridoo stage | 20 EUR flat | Report matching `ticket_stage` products and proposed price changes only; apply through the stage publication/ticket system after explicit content matching. |
| Music improvisation / meditation stage | 20 EUR flat | Report matching `ticket_stage` products and proposed price changes only; apply through the stage publication/ticket system after explicit content matching. |
| Special stages | Existing stage publication/ticket system | No automatic Commerce mutation. |

## Current-vs-target course map

| Current product | Target product | Target SKU | Target price | Apply behavior |
| --- | --- | --- | --- | --- |
| `/product/5` `COURS-DEB-INTER-ADULTE-40` | `Cours didgeridoo 1h - tous niveaux - plein tarif` | `COURS-DIDGERIDOO-1H-25` | 25 EUR | Safe to update title, SKU, and variation price if this SKU mapping is unique. |
| `/product/6` `COURS-DEB-INTER-ETUDIANT-30` | `Cours didgeridoo 1h - tous niveaux - tarif etudiant` | `COURS-DIDGERIDOO-1H-ETUDIANT-15` | 15 EUR | Safe to update title, SKU, and variation price if this SKU mapping is unique. |
| `/product/4` `COURS-ESSAI-20` | Pending | Pending | Pending | No update. Trial offer decision is still ambiguous. |
| `/product/7` `PACK4-DEB-INTER-ADULTE-100` | Pending | Pending | Pending | No update, unpublish, or delete without explicit pack decision. |
| `/product/8` `PACK4-DEB-INTER-ETUDIANT-50` | Pending | Pending | Pending | No update, unpublish, or delete without explicit pack decision. |
| `/product/9` `COURS-AVANCE-40` | Pending | Pending | Pending | No update or unpublish without explicit consolidation/deactivation decision. |

## Ambiguous decisions

- Trial lesson: no explicit decision says whether `cours_essai` stays at 20 EUR,
  becomes a didgeridoo one-hour offer, is hidden, or is retired.
- Packs: no explicit decision says whether four-course packs stay available,
  change price, are hidden, or are retired.
- Advanced course product: "all levels" suggests the advanced-only product might
  become redundant, but deactivation needs an explicit decision.
- Non-didgeridoo private lessons: guimbarde and music improvisation/meditation
  private lesson prices are not documented by the 2026 decisions above.
- Product type naming: existing bundle ids (`cours_deb_inter`, `cours_avance`)
  are still usable for course credit attribution, but changing labels or bundle
  structure would be later config work.
- Stage tickets: stage prices are synchronized from stage content
  `field_ticket_price`; exact stage products/nodes must be matched before an
  operator changes those prices.

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

- The script only saves matched Commerce product title fields and matched
  Commerce product variation SKU/price fields.
- It does not delete products, create orders, create webform submissions, call
  Google Calendar, run config import, or edit `config/sync`.
- Before production apply, take a database backup.
- To roll back after apply, restore the database backup or manually set the two
  updated variations back to:
  - `COURS-DEB-INTER-ADULTE-40`, 40 EUR, previous adult product title.
  - `COURS-DEB-INTER-ETUDIANT-30`, 30 EUR, previous student product title.
- If the script reports ambiguous SKU matches, do not apply; resolve the active
  Commerce data first and rerun the dry-run.
