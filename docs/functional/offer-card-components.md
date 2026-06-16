# Offer and card components

Reusable front-end classes for course and stage offer windows. These classes are
presentation-only and can be used in Drupal-rendered markup without changing the
Commerce or reservation logic.

## Class purpose

| Class | Purpose |
| --- | --- |
| `unisonges-page-intro` | Introductory panel for page context before the offer grid. |
| `unisonges-card-grid` | Responsive offer layout. Three cards on desktop, two on tablet, one on mobile. |
| `unisonges-offer-card` | Individual offer card, sized so CTAs align cleanly across cards. |
| `unisonges-offer-card__title` | Offer name or short headline. |
| `unisonges-offer-card__text` | Short descriptive copy for the offer. |
| `unisonges-offer-card__meta` | Price, duration, level, capacity, or scheduling metadata. |
| `unisonges-offer-card__cta` | Primary action link, button, or link wrapper for the offer. |
| `unisonges-detail-section` | Supporting content panel below the offer grid. |
| `unisonges-price-note` | Short pricing, eligibility, or payment note. |

## Current content conventions

- Keep card titles short and literal: course name, stage family, or clear action.
- Keep descriptions to one useful sentence. Avoid generic beginner /
  intermediate / advanced framing for private lessons.
- Use consistent price wording: `10 EUR`, `25 EUR / heure`, `15 EUR / heure
  etudiant`, `20 EUR par stage`.
- Use direct CTAs based on the real flow: buy confirmed products, reserve by
  contact, or open the relevant Stage dates. Do not add placeholder public URLs.
- For special stages, keep tariff and ticket details on the published Stage
  page because they vary by date and format.

## Current visual conventions

- The component block is scoped to the `unisonges-*` classes only.
- Body copy inside these panels uses a more readable system font while the rest
  of the site keeps the playful Uni-Songes identity.
- Cards and panels use the same 8px radius, light border, translucent white
  surface, and restrained shadow.
- Price metadata is visually separated from descriptive text with an accent
  strip and compact padding.
- Card CTAs are full-width on mobile, wrap text safely, and keep white text on
  the Uni-Songes accent color.

## Example markup

```html
<section class="unisonges-page-intro">
  <h1>Cours</h1>
  <p>Choisissez le format adapte a votre pratique.</p>
</section>

<section class="unisonges-card-grid" aria-label="Exemples d'offres">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours de didgeridoo</h2>
    <p class="unisonges-offer-card__text">
      Souffle continu, vibration, rythmes, voix et construction d'un jeu personnel.
    </p>
    <p class="unisonges-offer-card__meta">Essai 10 EUR. Puis 25 EUR / heure, 15 EUR / heure etudiant.</p>
    <a class="unisonges-offer-card__cta" href="/cours/didgeridoo">Voir les tarifs et acheter</a>
  </article>

  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Stages didgeridoo</h2>
    <p class="unisonges-offer-card__text">
      Deux rendez-vous collectifs reguliers : debutant et intermediaire.
    </p>
    <p class="unisonges-offer-card__meta">20 EUR par stage.</p>
    <a class="unisonges-offer-card__cta" href="/stages/didgeridoo">Voir les dates</a>
  </article>
</section>

<section class="unisonges-detail-section">
  <h2>Avant de reserver</h2>
  <p class="unisonges-price-note">
    Les billets de stage restent geres sur chaque page Stage publiee.
  </p>
</section>
```

## Usage for /cours and /stages

Use `unisonges-page-intro` for the page lead text, followed by one
`unisonges-card-grid` containing the available course or stage offers. Each offer
uses one `unisonges-offer-card` with title, description, metadata, and CTA.

For `/cours`, keep metadata focused on confirmed price and reservation flow. For
private lessons, describe the working material rather than fixed levels. For
`/stages`, keep metadata focused on the confirmed price, recurring family, and
published Stage booking flow. Use `unisonges-detail-section` for practical
information below the cards, and `unisonges-price-note` for short price or
payment caveats.

## Visual testing checklist

- Desktop: three offer cards render on one row when enough horizontal space is
  available.
- Tablet: the grid falls back to two columns without text overflow.
- Mobile: cards stack in one column and CTA buttons remain tappable.
- Long titles, metadata, and CTA labels wrap without overlapping adjacent
  content.
- Cards remain readable over the existing Uni-Songes page backgrounds and inside
  the current scroll frame.
- Body copy inside cards and detail panels is readable despite the playful site
  identity.
- Price notes stand out from descriptive paragraphs without looking like a
  separate offer card.
- CTA text remains visible before hover, including when Drupal wraps an inner
  link inside `unisonges-offer-card__cta`.
- Hover and focus states keep CTA text legible.
- Existing pages without these classes are visually unchanged.
