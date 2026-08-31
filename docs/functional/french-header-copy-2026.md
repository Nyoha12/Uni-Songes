# Libellés français du header — 2026

## Périmètre

Cette modification corrige uniquement les libellés français statiques du
header global et de son tiroir mobile. Les deux contextes utilisent le même
partial Twig, `site-header.html.twig`, inclus par les pages standard et la page
d’accueil.

La base contrôlée est `origin/release/prod` au commit
`a8582ad691673c4096193bf3fb0f2a741739792c`. Aucun chemin public, condition
d’authentification, libellé de menu fourni par Drupal, comportement de
sous-menu, JavaScript ou CSS n’est modifié.

Cette base contient le menu final de la PR #78. Le partial continue de rendre
`page.primary_menu`, avec `page.navigation` comme repli, sans réécrire les
libellés, destinations ou relations parent/enfant fournis par Drupal.

## Corrections de copie

| Emplacement | Avant | Après |
| --- | --- | --- |
| CTA desktop | `Reserver` | `Réserver` |
| Compte anonyme desktop | `Creer un compte` | `Créer un compte` |
| CTA du tiroir mobile | `Reserver` | `Réserver` |
| Compte anonyme du tiroir mobile | `Creer un compte` | `Créer un compte` |

Les libellés déjà corrects restent identiques dans les deux contextes :
`Se connecter`, `Mon compte` et `Se déconnecter`. Les attributs accessibles
`Ouvrir le menu`, `Fermer` et `Menu mobile` ne nécessitent aucune correction.
Le fichier ne contient aucun attribut `title` ni aucune apostrophe dans ses
libellés statiques.

## Contrats préservés

- les deux CTA `Réserver` conservent exactement `href="/reserver"` ;
- `Mon compte` conserve `path('user.page')` ;
- `Se déconnecter` conserve `path('user.logout')` ;
- `Se connecter` conserve `path('user.login')` ;
- `Créer un compte` conserve `path('user.register')` ;
- les branches `{% if logged_in %}` / `{% else %}` sont inchangées et restent
  identiques sur desktop et mobile ;
- `page.primary_menu` et son repli `page.navigation` restent rendus directement
  par Drupal, sans réécriture de leurs libellés ;
- le conteneur mobile vide, ses attributs `data-navigation-submenus`,
  `aria-label`, `aria-controls`, `aria-expanded`, `hidden` et son identifiant
  `mobile-drawer` restent inchangés.

## Validation statique

Les contrôles sont exécutés depuis la racine du dépôt, sans DDEV, Docker,
Drush ni navigateur :

```bash
set -euo pipefail
header_base=origin/release/prod
header_file=drupal/web/themes/custom/unisonges_theme/templates/partials/site-header.html.twig
header_doc=docs/functional/french-header-copy-2026.md

test "$(git merge-base HEAD "$header_base")" = "$(git rev-parse "$header_base")"
git diff --check "$header_base" --

expected_files="$(printf '%s\n' "$header_doc" "$header_file" | LC_ALL=C sort)"
changed_files="$({
  git diff --name-only --no-renames "$header_base" --
  git ls-files --others --exclude-standard
} | LC_ALL=C sort -u)"
test "$changed_files" = "$expected_files"
```

Un contrôle structurel compare le partial avant/après après normalisation des
quatre seuls nœuds texte autorisés. Il prouve ainsi que les expressions
`href`/`path(...)`, les branches d’authentification et la structure accessible
restent octet pour octet identiques. Des assertions séparées vérifient :

- deux occurrences visibles de `Réserver` et de `Créer un compte`, une par
  contexte ;
- deux occurrences de chacun des libellés `Se connecter`, `Mon compte` et
  `Se déconnecter` ;
- aucune occurrence visible des variantes ASCII `Reserver` ou
  `Creer un compte` ;
- la parité exacte des cinq actions de compte entre desktop et mobile ;
- le décodage UTF-8 strict et la normalisation Unicode NFC ;
- la syntaxe/santé Twig du partial ;
- l’absence de chevauchement de fichiers avec les PR ouvertes au moment du
  contrôle.

## Validation Drupal et Chromium — 1er septembre 2026

Le commit rebasé `c877ec227db36210f9ceabece9e73572ac11f736` a été chargé
exactement dans le checkout de service, lui-même placé sur `release/prod` au
commit `a8582ad691673c4096193bf3fb0f2a741739792c`. L’empreinte du répertoire
ignoré `drupal/.ddev` est restée identique pendant ce chargement. Aucun accès au
VPS n’a été effectué.

Avant la première écriture locale, le snapshot DDEV nommé
`pr83-french-header-copy-pre-runtime-20260901T0744Z` et une archive des fichiers
publics ont été créés. L’état initial était :

- base de données normalisée :
  `161ef10fa5a32b0075cc19c4abd9a3ec8b9d8e0039be392db83f676397134b4b` ;
- configuration active, 314 objets :
  `314:e96a6b849b5e15c6e16fde5b6494a9e57fe9f7161dd8398c819963ddfdfc2127` ;
- fichiers publics, 245 fichiers et 838 007 octets :
  `51e4eb31f850df8f0f88b3406c0257c5c9f085fcb76c6bee7556acc26fa87d9b` ;
- thèmes Olivero/Claro, front `/node`, zéro nœud, 16 aliases, zéro lien de
  menu de contenu et utilisateurs 0 à 6.

Le thème existant, les quatre blocs nécessaires et les quatorze liens de menu
ont été activés uniquement dans la base isolée. Aucun nœud, alias ou utilisateur
n’a été créé. Le menu rendu correspondait au menu final de la PR #78 : cinq
racines et neuf enfants. Le bloc messages de la PR #81 était l’unique chemin
`[data-drupal-messages]` sous l’unique `main#main-content`, jamais dans le
header.

Chromium 140.0.7339.16 et Playwright 1.55.0 ont exécuté 18 profils : états
anonyme et authentifié, chacun sur les neuf combinaisons suivantes :

| Famille | 100 % | 150 % | 200 % |
| --- | --- | --- | --- |
| Desktop | `1800 × 1000`, facteur 1 | `1200 × 667`, facteur 1,5 | `900 × 500`, facteur 2 |
| Tablette | `1024 × 768`, facteur 1 | `683 × 512`, facteur 1,5 | `512 × 384`, facteur 2 |
| Mobile | `390 × 844`, facteur 1 | `260 × 563`, facteur 1,5 | `195 × 422`, facteur 2 |

La matrice complète a validé :

- [x] header desktop anonyme : `Réserver`, `Se connecter` et
  `Créer un compte` sont visibles une seule fois ;
- [x] header desktop authentifié : `Réserver`, `Mon compte` et
  `Se déconnecter` sont visibles une seule fois ;
- [x] tiroir mobile anonyme : les mêmes trois actions anonymes sont visibles ;
- [x] tiroir mobile authentifié : les mêmes trois actions authentifiées sont
  visibles ;
- [x] les glyphes accentués de `Réserver`, `Créer` et `Se déconnecter` sont
  rendus sans caractère de remplacement et les chaînes du DOM sont en NFC ;
- [x] les noms accessibles du bouton d’ouverture, du bouton de fermeture, de la
  navigation mobile et des liens correspondent à leurs libellés attendus ;
- [x] aucun identifiant dupliqué n’est présent, notamment
  `id="mobile-drawer"` ;
- [x] tous les liens conservent leurs destinations d’origine : `/reserver`,
  `user.page`, `user.logout`, `user.login` et `user.register`.

Les sous-menus et le tiroir ont été ouverts et fermés au clavier. La source de
navigation desktop reste unique et son clone mobile ne duplique aucun ID. Le
texte utilise la pile calculée `system-ui, "Segoe UI", Arial, sans-serif`, sans
mojibake, translittération ASCII ni accent combiné. Aucun profil ne présente de
débordement horizontal, erreur console/page/requête, requête externe ou réponse
HTTP 5xx. La fenêtre isolée des logs ne contient aucun avertissement/fatal PHP
ni événement Drupal de niveau warning ou plus critique. Les résultats, logs et
captures sont conservés uniquement sous
`/tmp/pr83-french-header-runtime-20260901T0744Z`.

## Restauration locale

Le snapshot nommé et l’archive des fichiers publics ont été restaurés. Le dump
normalisé de la base, la configuration active et l’empreinte des fichiers
publics sont strictement identiques aux références ci-dessus. Le contrôle final
confirme Olivero/Claro, le front `/node`, zéro nœud, les 16 aliases initiaux,
zéro lien de menu de contenu, zéro bloc Uni-Songes et exactement les utilisateurs
0 à 6. Il ne reste donc aucun nœud, alias, lien de menu ou utilisateur de
fixture.

Le checkout de service est propre sur `release/prod` au commit
`a8582ad691673c4096193bf3fb0f2a741739792c`. Les scripts temporaires ont été
retirés du dépôt et le projet DDEV a été arrêté.
