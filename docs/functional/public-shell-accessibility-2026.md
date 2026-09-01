# Accessibilité du shell public — 2026

## Objectif et périmètre

Cette correction garantit un landmark principal et une cible de lien
d’évitement cohérents sur les deux shells publics du thème Uni-Songes. Elle
rétablit aussi l’unique chemin d’affichage des messages Drupal sans rendre la
région d’en-tête complète.

La base inspectée est `origin/release/prod` au commit
`625c613dca22301b04a3f1bdc3c93db961fe9132`, après intégration des PR #80 et
#78 ainsi que des changements de navigation, de typographie et de mouvement
autonome du fond. La correction ne change aucune URL publique, aucun style,
aucun JavaScript, aucune logique de réservation ou Commerce et aucun fichier
des PR parallèles #82 à #85.

## État constaté

Le thème n’a pas de surcharge `html.html.twig`. Il hérite donc de Bootstrap
Barrio 5.5.20 le lien suivant, qui cible `#main-content` :

```twig
<a href="#main-content" class="visually-hidden-focusable">
  {{ 'Skip to main content'|t }}
</a>
```

- `page.html.twig` contient déjà exactement un
  `<main id="main-content">` et rend `page.content` une fois.
- `page--front.html.twig`, également sélectionné pour `/accueil`, rend
  `page.content` une fois dans `#unisonges-scrollframe`, mais ne contient aucun
  élément `main` ni cible `#main-content`.
- Le seul bloc `system_messages_block` actif pour `unisonges_theme`,
  `unisonges_theme_messages`, est placé dans `header`.
- Aucun des deux shells ne rend `page.header`. Les messages de statut,
  d’avertissement et d’erreur n’ont donc aucun chemin de rendu public.
- Le bloc de titre actif, `unisonges_theme_page_title`, est placé dans
  `content` avec le poids `-7`; le contenu principal a le poids `-3`.

La région `header` ne peut pas être rendue en bloc pour corriger ce défaut.
Elle contient aussi les blocs actifs de branding, compte, recherche, aide,
fil d’Ariane et signature Drupal. Le partial `site-header.html.twig` rend déjà
la marque, la navigation principale depuis `page.primary_menu`, les liens de
compte et le drawer mobile. Rendre toute la région créerait donc des doublons
et ajouterait des éléments étrangers au besoin.

## Stratégie de rendu

Le shell standard reste inchangé. Sur le shell d’accueil, un unique
`<main id="main-content" role="main">` enveloppe le scrollframe existant. Les
identifiants et l’ordre de `#unisonges-bgfx`, `#unisonges-bgfx-scroll`,
`#unisonges-bgfx-layer`, du header fixe et de `#unisonges-scrollframe` restent
inchangés.

Le bloc `unisonges_theme_messages` est déplacé seul de `header` vers `content`
avec le poids `-8`. Il précède ainsi le titre de page (`-7`), les actions et le
bloc de contenu principal. Chaque shell rend `page.content` exactement une
fois à l’intérieur de son unique `main`, ce qui donne le chemin suivant :

```text
lien d’évitement → #main-content
                    └── wrappers structurels propres au shell
                        └── #unisonges-scrollframe
                            └── page.content (une fois)
                                ├── messages Drupal (-8, une fois)
                                ├── titre de page (-7)
                                ├── actions et onglets
                                └── contenu principal (-3, une fois)
```

Cette correction ne rend pas `page.header` et ne touche pas le partial du
header. Elle ne duplique donc ni branding, ni navigation principale, ni liens
de compte, ni drawer mobile, ni contenu, ni messages.

## Validation statique

Les contrôles sont exécutés depuis la racine du worktree, sans DDEV, Docker,
Drush ni navigateur :

```bash
shell_base=origin/release/prod

git diff --check "$shell_base" --

# Un main planifié par shell, avec la cible du lien d’évitement.
test "$(rg -c '<main id="main-content"' \
  drupal/web/themes/custom/unisonges_theme/templates/page.html.twig)" = 1
test "$(rg -c '<main id="main-content"' \
  drupal/web/themes/custom/unisonges_theme/templates/page--front.html.twig)" = 1

# Un seul chemin de contenu par shell et un seul bloc messages actif du thème.
test "$(rg -c '\{\{ page\.content \}\}' \
  drupal/web/themes/custom/unisonges_theme/templates/page.html.twig)" = 1
test "$(rg -c '\{\{ page\.content \}\}' \
  drupal/web/themes/custom/unisonges_theme/templates/page--front.html.twig)" = 1
```

La vérification finale couvre aussi : compilation/sanity Twig disponible sans
amorcer Drupal, parsing YAML du bloc modifié, raisonnement des landmarks,
cohérence de la cible du lien d’évitement, unicité du chemin des messages,
garde exacte des trois fichiers, absence de tout fichier des PR #82 à #85 et
revue accessibilité indépendante.

Garde exacte du périmètre, fichiers non suivis compris :

```bash
shell_expected_files="$(printf '%s\n' \
  docs/functional/public-shell-accessibility-2026.md \
  drupal/config/sync/block.block.unisonges_theme_messages.yml \
  drupal/web/themes/custom/unisonges_theme/templates/page--front.html.twig |
  LC_ALL=C sort)"

shell_changed_files="$({
  git diff --no-renames --name-only --diff-filter=ACMRDTUXB \
    "$shell_base" --
  git ls-files --others --exclude-standard
} | LC_ALL=C sort -u)"

diff -u \
  <(printf '%s\n' "$shell_expected_files") \
  <(printf '%s\n' "$shell_changed_files")
```

### Résultats du 31 août 2026

- compilation syntaxique des deux shells avec Twig.js 3.0.0 : OK ;
- validation de la structure HTML avec html-validate 9.7.1 : OK ; la règle
  `no-redundant-role` est neutralisée car `role="main"` est le motif déjà
  présent dans le shell standard et sa suppression élargirait inutilement le
  diff ;
- parsing de la configuration modifiée avec js-yaml 4.1.0 : OK ;
- `git diff --check`, garde exacte des trois fichiers et garde des fichiers
  interdits : OK ;
- deux shells, deux éléments `main`, deux cibles `#main-content`, un rendu
  `page.content` par shell et un seul bloc messages actif pour le thème : OK ;
- aucun chevauchement avec les listes de fichiers des PR #82 à #85 : OK ;
- revue accessibilité et revue de l’ordre de rendu Drupal indépendantes : OK,
  aucun blocage statique.

## Validation Drupal et Chromium

Le commit source `d190d4e331f0fdf9370d947ca0c5bd3d0d87c9a7` a été chargé tel
quel dans le checkout de service. Le répertoire ignoré `drupal/.ddev` a été
préservé. Le thème et les seules dépendances de test ont été activés par les
API Drupal ; le bloc messages a été modifié par l’API d’entité Block. Aucun
import de configuration complet ou partiel n’a été exécuté. Les hubs Forum et
Blog utilisaient les vraies configurations fusionnées des PR #80 et #78.

Le harness Chromium Playwright 1.55.0 a exécuté 14 contrôles, tous réussis,
avec un viewport desktop de 1440 × 900 et un viewport mobile de 390 × 844.
Les réponses HTML serveur ont été vérifiées séparément du DOM enrichi par
JavaScript. Les captures et le résultat JSON ont été conservés uniquement sous
`/tmp/pr81-playwright/evidence/`.

| Scénario | Serveur | Desktop | Mobile | Résultat |
| --- | --- | --- | --- | --- |
| Accueil anonyme | OK | OK | OK | Un unique `main#main-content` et un rendu du contenu |
| Basic page normale | OK | OK | OK | Landmark, titre et contenu uniques |
| Réservation | OK | OK | OK | Landmark unique ; erreur de discipline dans un unique `alert-danger` |
| Connexion et inscription | OK | OK | OK | Landmarks uniques ; erreur de connexion rendue une fois |
| Connexion réussie | — | OK | — | Aucun doublon ; zéro message de statut, donc au plus un attendu |
| Produit et panier Commerce | OK | OK | OK | Landmarks uniques ; ajout au panier dans un unique `alert-success` |
| Forum et Blog | OK | OK | OK | Landmarks uniques et vrais blocs de hubs présents |
| Validation Webform Contact | OK | OK | OK | Un unique résumé d’erreur accessible |
| Soumission Webform Contact | — | OK | — | Un unique `alert-success` ; deux livraisons Mailpit locales |
| Avertissement Drupal | — | OK | — | Un unique `alert-warning` via un lien de réinitialisation d’un autre compte |
| Lien d’évitement clavier | — | OK | — | `Tab`, `Entrée`, cible `:target`, puis focus réel dans le `main` |
| Header, navigation et drawer | OK | OK | OK | Header fixe, source serveur unique et drawer mobile fonctionnel |
| Scrollframe et BGFX | — | OK | — | Scroll réel et transform autonome de `#unisonges-bgfx-scroll` |

Les trois sévérités gardent les classes et rôles Barrio attendus : statut
`.alert-success[role="status"]`, avertissement
`.alert-warning[role="alert"]` et erreur `.alert-danger[role="alert"]`. Pour
chaque opération, le DOM contient un seul wrapper `[data-drupal-messages]`,
dans `main#main-content`, avant le bloc ou formulaire concerné et jamais dans
le header. Les erreurs inline propres aux formulaires ne constituent pas un
second rendu du bloc système.

Sur les dix routes représentatives, les assertions communes ont confirmé un
seul landmark principal, une seule cible d’évitement, une seule marque, un
seul bloc de navigation dans le HTML serveur, un seul drawer, un seul
scrollframe, un seul jeu d’IDs BGFX et un seul bloc de contenu. Le clone du menu
mobile renomme ses IDs. Aucun ID dupliqué, débordement horizontal, réponse 5xx,
erreur console ou erreur de page n’a été observé. Les logs du run réussi ne
contiennent aucun warning ou fatal PHP et aucun événement watchdog de sévérité
4 ou plus critique.

## Restauration locale

Le snapshot nommé
`pr81-public-shell-accessibility-pre-runtime-20260831T153500Z` a été créé avant
toute écriture. Avant sa restauration, le nettoyage ciblé a supprimé deux
soumissions Contact marquées, un panier/ordre et sa ligne, quatre nœuds, quatre
aliases et un lien de menu. Aucun produit, utilisateur, paiement ou dépôt de
réservation temporaire n’avait été créé. Les quatre messages Mailpit marqués
ont aussi été supprimés par leurs IDs exacts.

Après restauration, un nouveau processus Drupal a confirmé : zéro nœud, zéro
lien de menu, zéro soumission, zéro ordre et zéro ligne de commande ; 16
aliases, quatre produits, quatre variations et sept utilisateurs, soit les
comptes de base. Les IDs et marqueurs temporaires sont tous absents. Le front
est revenu à `/node`, les seuls thèmes activés sont Olivero et Claro, et les
empreintes avant/après sont strictement identiques :

- base de données normalisée :
  `6c81065691a71a4c33c357a35b52e12b159f6877339ed0bf2b2f0ff372c6b369` ;
- configuration active :
  `f1c730b40df5ef1063370c36b1006dace96fb26ab8ac2db12c9ea3c74c3f8dd0` ;
- fichiers publics normalisés :
  `31f3c1526a6213fc2016da7bdf8efab93ea0f22fc3baee51f25acb4ccdff9756`
  (245 fichiers, zéro symlink, 1 370 487 octets).

Le checkout de service a finalement été remis sur `release/prod` au commit
`625c613dca22301b04a3f1bdc3c93db961fe9132`, avec un statut Git propre. DDEV
a été arrêté et les harnesses temporaires ont été supprimés.
