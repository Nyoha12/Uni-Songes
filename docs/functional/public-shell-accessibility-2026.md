# Accessibilité du shell public — 2026

## Objectif et périmètre

Cette correction garantit un landmark principal et une cible de lien
d’évitement cohérents sur les deux shells publics du thème Uni-Songes. Elle
rétablit aussi l’unique chemin d’affichage des messages Drupal sans rendre la
région d’en-tête complète.

La base inspectée est `origin/release/prod` au commit
`54562e22f4025b88ce2b755248db833151d1637b`, après intégration des changements
de navigation, de typographie et de mouvement autonome du fond. La correction
ne change aucune URL publique, aucun style, aucun JavaScript, aucune logique de
réservation ou Commerce et aucun fichier de la PR #80.

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
garde exacte des trois fichiers, absence de tout fichier de la PR #80 et revue
accessibilité indépendante.

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
- aucun chevauchement avec les listes de fichiers des PR #78 et #80 : OK ;
- revue accessibilité et revue de l’ordre de rendu Drupal indépendantes : OK,
  aucun blocage statique.

## Matrice d’exécution différée

La validation Drupal et navigateur est volontairement différée tant que la
PR #80 possède DDEV, Docker, Drush, Chromium et Mailpit. La PR doit rester en
brouillon jusqu’à libération de ces ressources.

| Scénario | Desktop | Mobile | Résultat attendu |
| --- | --- | --- | --- |
| Page d’accueil anonyme | Différé | Différé | Un `main`, cible d’évitement réelle, aucun doublon |
| Basic page normale | Différé | Différé | Un `main`, titre et contenu rendus une fois |
| Erreur de validation de réservation | Différé | Différé | Message visible une fois avant le formulaire |
| Erreur de connexion | Différé | Différé | Erreur visible une fois avant le formulaire |
| Connexion réussie | Différé | Différé | Statut visible une fois dans le contenu principal |
| Validation Webform | Différé | Différé | Erreurs/messages visibles une seule fois |
| Message Commerce/panier | Différé | Différé | Statut ou erreur visible une seule fois |
| Lien d’évitement au clavier | Différé | Différé | Le focus/viewport atteint `#main-content` |
| Header, menu et drawer | Différé | Différé | Header fixe et interactions inchangés, aucun doublon |
| Scrollframe et BGFX | Différé | Différé | Scroll central et mouvement autonome préservés |

Pour chaque scénario, contrôler explicitement l’absence de doublon de header,
marque, navigation, liens de compte, drawer, messages, contenu et landmark
principal.
