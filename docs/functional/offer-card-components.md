# Offer and card components

Reusable front-end classes for course and stage offer windows. These classes are
presentation-only and can be used in Drupal-rendered markup without changing the
Commerce or reservation logic.

## Class purpose

| Class | Purpose |
| --- | --- |
| `unisonges-page-intro` | Introductory window for page context before the offer grid. |
| `unisonges-card-grid` | Responsive offer layout. Three cards on desktop, two on tablet, one on mobile. |
| `unisonges-offer-card` | Individual offer window, sized so CTAs align cleanly across cards. |
| `unisonges-offer-card__title` | Offer name or short headline. |
| `unisonges-offer-card__text` | Short descriptive copy for the offer. |
| `unisonges-offer-card__meta` | Price, duration, level, capacity, or scheduling metadata. |
| `unisonges-offer-card__cta` | Primary action link or button for the offer. |
| `unisonges-detail-section` | Supporting content window below the offer grid. |
| `unisonges-price-note` | Short pricing, eligibility, or payment note. |

## Example markup

```html
<section class="unisonges-page-intro">
  <h1>Cours</h1>
  <p>Choisissez la formule adaptee a votre pratique.</p>
</section>

<section class="unisonges-card-grid" aria-label="Offres de cours">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours d'essai</h2>
    <p class="unisonges-offer-card__text">
      Une premiere seance pour rencontrer l'equipe et tester la methode.
    </p>
    <p class="unisonges-offer-card__meta">1 cours - paiement avant reservation</p>
    <a class="unisonges-offer-card__cta" href="/cours/essai">Reserver</a>
  </article>

  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Pack 4 cours</h2>
    <p class="unisonges-offer-card__text">
      Un format suivi pour progresser sur plusieurs seances.
    </p>
    <p class="unisonges-offer-card__meta">4 credits - validite selon l'offre</p>
    <a class="unisonges-offer-card__cta" href="/cours/pack-4">Acheter</a>
  </article>
</section>

<section class="unisonges-detail-section">
  <h2>Avant de reserver</h2>
  <p class="unisonges-price-note">
    Les credits de cours sont disponibles apres validation du paiement.
  </p>
</section>
```

## Usage for /cours and /stages

Use `unisonges-page-intro` for the page lead text, followed by one
`unisonges-card-grid` containing the available course or stage offers. Each offer
uses one `unisonges-offer-card` with title, description, metadata, and CTA.

For `/cours`, keep metadata focused on level, credit count, payment timing, and
reservation availability. For `/stages`, keep metadata focused on date, duration,
capacity, and booking status. Use `unisonges-detail-section` for practical
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
- Hover and focus states keep CTA text legible.
- Existing pages without these classes are visually unchanged.
