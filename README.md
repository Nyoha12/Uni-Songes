# UniSonges — site statique Cloudflare Pages

Ce dépôt contient une base **100% statique** (HTML/CSS vanilla) pour UniSonges.

## Déploiement Cloudflare Pages
- **Project root**: `/`
- **Output directory**: `public`
- **Build command**: laisser vide, ou utiliser `exit 0`.

> Recommandation: mettre `exit 0` si Cloudflare Pages exige une commande de build.

## Périmètre PR#1
- Architecture des routes SEO-ready (dossiers avec `index.html`)
- Navigation commune (header/footer)
- Baseline SEO (meta, canonical, OG, robots, sitemap)
- Intégration Google Schedule sur `/reserver-un-cours/`
- Aucun backend, aucun e-commerce, aucune API server-side
