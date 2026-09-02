# Contraste des CTA et du focus de réservation — validation 2026

## Résultat

La PR #95 corrige deux défauts ciblés dans `styles.css` :

- `.btn--cta:hover` conserve maintenant `color: #fff` ;
- `.btn--cta:active` conserve maintenant `color: #fff` ;
- la correction de focus du Webform historique de réservation reste intacte.

Le minimum mesuré sur les CTA corrigés est `5,340640:1` en hover et active,
filtre compris. Le défaut connu, environ `2,5485:1`, n'est plus reproductible.
Les états normal, visited, focus et focus-visible mesurent `5,473250:1`.

La validation a porté sur le vrai rendu Drupal dans Chromium, avec et sans
agrégation, ainsi que sur les consommateurs qui ne doivent pas être modifiés.
Aucune modification de markup, PHP, Twig, JavaScript, navigation, arrière-plan,
route, taille, padding ou disposition n'est introduite par cette PR.

## Base Git et périmètre

La branche existante `codex-fix-interactive-text-contrast` a été rebasée sans
conflit sur :

```text
origin/release/prod
8cc82f9af6899aedc14490931c415293d0bdf0cb
```

Ce commit est le merge de la PR #98. Les merges des PR #99 et #100 sont aussi
des ancêtres de cette base. L'historique réécrit a été poussé sur la branche
existante avec un lease explicite sur l'ancien head distant ; aucune autre
branche et aucune autre PR n'ont été créées.

La PR modifie exactement deux fichiers :

```text
drupal/web/themes/custom/unisonges_theme/css/styles.css
docs/functional/interactive-text-contrast-2026.md
```

Les propriétaires suivants restent byte-identiques à la base :

- `css/auth-account.css` ;
- `css/navigation-submenus.css` ;
- `unisonges_theme.theme` et `unisonges_theme.libraries.yml` ;
- le template de messages livré par la PR #100 ;
- le PHP, les templates Twig et le JavaScript de réservation ;
- les fichiers de l'arrière-plan autonome de la PR #98.

## Correction et cascade

Le diff fonctionnel est volontairement minimal :

```css
.btn--cta:hover {
  color: #fff;
  filter: brightness(0.96);
}
.btn--cta:active {
  color: #fff;
}
```

La cause était `a:hover { color: var(--accent2); }`, de spécificité `0-1-1`,
qui remplaçait le blanc de `.btn--cta`, de spécificité `0-1-0`. Le sélecteur
`.btn--cta:hover`, de spécificité `0-2-0`, conserve le blanc dans l'état qui en
a besoin. `.btn--cta:active`, également `0-2-0`, ferme explicitement l'état
active. Aucun `!important` n'est ajouté.

Il n'existe pas de règle auteur globale `a:visited`. La règle `.btn--cta`
continue donc à fournir le blanc aux liens visités ; la mesure navigateur le
confirme. Ajouter `.btn--cta:visited` aurait été redondant.

La règle plus tardive `.reservation-portal__actions .btn--cta` conserve déjà
la même paire blanc/teal. Les règles de header, drawer et typographie qui suivent
ne redéfinissent pas la couleur. La correction ne peut donc pas atteindre un
lien qui ne possède pas la classe `.btn--cta`.

## Inventaire complet de `.btn--cta`

L'audit des producteurs déployables trouve neuf consommateurs source. Les
quatre actions du portail sont conditionnelles et ne sont pas toutes visibles
simultanément.

|   # | Consommateur                   | Libellé accessible           | Contexte                      |
| --: | ------------------------------ | ---------------------------- | ----------------------------- |
|   1 | CTA header desktop             | `Réserver`                   | toutes pages, affichage large |
|   2 | CTA drawer mobile              | `Réserver`                   | navigation compacte           |
|   3 | fin de checkout                | `Réserver un cours`          | commande éligible terminée    |
|   4 | portail, entrée guidée         | `Démarrer le parcours guidé` | `/reserver`                   |
|   5 | portail anonyme                | `Se connecter pour réserver` | `/reserver`                   |
|   6 | portail éligible               | `Choisir un créneau`         | `/reserver`                   |
|   7 | portail non éligible           | `Choisir cours et créneau`   | `/reserver`                   |
|   8 | tunnel anonyme                 | `Se connecter`               | `/reservation-cours`          |
|   9 | redémarrage après confirmation | `Réserver un autre cours`    | submit confirmé               |

Les neuf producteurs ont été rendus au moyen de fixtures locales restaurables.
La matrice principale contient douze scénarios CTA, car le CTA du header ou du
drawer est répété aux largeurs desktop, reflow, tablette, 390 px et 320 px.

## Mesures de contraste réelles

Chromium `140.0.7339.16` retourne les mêmes valeurs avec l'agrégation CSS/JS
activée et désactivée.

| État           | Premier plan calculé | Fond calculé        | Filtre effectif                                  |    Contraste |
| -------------- | -------------------- | ------------------- | ------------------------------------------------ | -----------: |
| normal         | `rgb(255, 255, 255)` | `rgb(15, 118, 110)` | aucun                                            | `5,473250:1` |
| visited, liens | identique            | identique           | aucun                                            | `5,473250:1` |
| hover          | blanc                | teal                | `brightness(.96)`                                | `5,340640:1` |
| active         | blanc                | teal                | `brightness(.96)` car le pointeur reste en hover | `5,340640:1` |
| focus          | identique au normal  | identique           | aucun                                            | `5,473250:1` |
| focus-visible  | identique au normal  | identique           | aucun                                            | `5,473250:1` |

Pour hover et active, la couleur effectivement filtrée est
`rgb(244.8, 244.8, 244.8)` sur `rgb(14.4, 113.28, 105.6)`. Le calcul WCAG
indépendant donne `5,340640439:1`, au-dessus du minimum `4,5:1` pour du texte
de taille normale.

La paire fautive historique était `#f59e0b` sur `#0f766e` :

- sans filtre : `2,548468203:1` ;
- avec `brightness(.96)` appliqué aux deux couleurs : `2,503766436:1`.

Chaque scénario réel mesure désormais au moins `5,340640:1` ; l'état connu à
`~2,5485:1` est donc éliminé, pas simplement déplacé vers active.

## Focus et contrôles désactivés

Le focus clavier de chaque CTA a été atteint et son nom accessible contrôlé.
Les captures élémentaires montrent un anneau externe net : outline Chromium
automatique sombre sur desktop, tablette et reflow, anneau ambre visible dans
les contextes tactiles, et adaptation système en forced colors. En forced
colors, le CTA testé mesure `13,994023:1` et conserve son nom `Réserver`.

La correction historique du Webform est inchangée octet pour octet depuis le
head de la PR avant rebase. Elle reste limitée aux quatre sélecteurs suivants :

```css
form[id*="webform-submission-cours-particuliers-reservation"] .form-actions input[type="submit"]:focus,
form[id*="webform-submission-cours-particuliers-reservation"] .webform-actions input[type="submit"]:focus,
form[id*="webform-submission-cours-particuliers-reservation"] .form-actions input[type="submit"]:focus-visible,
form[id*="webform-submission-cours-particuliers-reservation"] .webform-actions input[type="submit"]:focus-visible
```

Son rendu focus et focus-visible est exactement : bordure `#0f766e`, outline
opaque `2px solid #0f766e`, offset `2px`. Il ne peut pas cibler les formulaires
d'authentification.

Aucun consommateur `.btn--cta` déployé n'est désactivé. Le vrai contrôle
désactivé du tunnel, `Continuer vers les détails`, est un submit sans cette
classe. Sa validation dédiée passe `9/9` : sémantique disabled et nom
accessible présents, couleurs/bordures à alpha `0.3` distinctes du frère
activé opaque, focus impossible et aucune avancée du tunnel.

## Contrôles explicitement inchangés

Les familles suivantes ont été exercées en normal, visited lorsque applicable,
hover, active, focus et focus-visible :

- lien éditorial de cours ;
- liens de navigation desktop et drawer ;
- CTA des cartes d'offre `.unisonges-offer-card__cta` ;
- bouton nu de type Contact ;
- submit du Webform historique ;
- boutons Commerce d'ajout au panier, panier et checkout ;
- actions d'authentification et de compte de la PR #99 ;
- fermeture du message d'erreur de connexion de la PR #100.

Chacun reste hors de `.btn--cta`. Les règles qui les possèdent et leurs couleurs
calculées sont inchangées par rapport à la base. Les rapports de contraste des
surfaces transparentes de cette matrice servent uniquement de preuve de
non-régression de cascade ; ils ne sont pas présentés comme de nouvelles
certifications WCAG, car un fond alpha doit être composé avec tous ses ancêtres.

## Intégration PR #99 et PR #100

Les routes `/user/login`, `/user/register`, `/user/password` et le profil du
propriétaire chargent toujours `auth-account.css` après les feuilles globales.
Le scope `auth-account-page` et la variable `--auth-account-surface: #fffaf2`
sont présents, et aucun contrôle de compte ne correspond à `.btn--cta`.

Le passage principal confirme sur chaque route : un seul `main`, un seul `h1`,
aucun ID dupliqué et un seul chemin de messages lorsqu'un message existe.
L'erreur de connexion est affichée immédiatement avec la présentation approuvée :

```text
fond       rgb(253, 240, 238)
texte      rgb(122, 31, 18)
bordure    rgb(200, 88, 73)
```

Le bouton de fermeture garde le nom accessible `Close`, une cible `44 × 44 px`,
un contraste composé de `9,287848:1` et un outline focus-visible brun de `3px`
avec offset `3px`. La fermeture fonctionne et aucun message retardé ne réapparaît
sur la requête propre suivante.

Une passe Chromium complémentaire ferme la matrice d'états des contrôles de
compte. Elle couvre douze contrôles dans chacun des deux modes d'agrégation et
passe `293/293` assertions par mode :

| Famille PR #99/#100             | normal / visited |          hover / active |        focus |                         focus-visible |
| ------------------------------- | ---------------: | ----------------------: | -----------: | ------------------------------------: |
| submits login/register/password |     `5,473250:1` |            `7,964671:1` | `5,473250:1` |           `7,964671:1`, outline `3px` |
| lien « mot de passe oublié »    |     `9,921376:1` |           `11,593006:1` | `9,921376:1` |           `9,921376:1`, outline `3px` |
| autres liens auth               |     `7,834799:1` | `10,313943–11,593006:1` | `7,834799:1` | `7,834799–10,313943:1`, outline `3px` |
| quatre actions du profil        |     `7,664442:1` |            `7,664442:1` | `7,664442:1` |           `7,664442:1`, outline `3px` |
| fermeture du message            |     `9,287848:1` |            `9,287848:1` | `9,287848:1` |           `9,287848:1`, outline `3px` |

Les quatre actions de profil réellement rendues sont `View`, `Payment methods`,
`Edit` et `Orders`. Aucun état n'est recoloré par la PR #95. La présentation du
message reste possédée par `auth-account.css` et le template fusionné par la
PR #100 reste inchangé.

## Intégration PR #98

Le contrôleur d'arrière-plan livré par la PR #98 est byte-identique à la base.
La trace Chromium dédiée dure `88,660 s`, soit deux cycles complets :

```text
t = 0 s     y = -0,098 px
t = 22 s    y = -13,889 px
t = 44 s    y = -0,111 px
t = 66 s    y = -13,887 px
t = 88 s    y = -0,110 px
```

Le cycle de `44 s` est confirmé, avec une amplitude mesurée de `13,791 px`.
Les positions à un cycle d'écart ne diffèrent que de `0,013 px`, puis
`0,001 px`. Aucun listener scroll, wheel ou touch n'est enregistré par ce
contrôleur. Les transformations restent identiques après scroll de 0, 25, 50,
75 et 100 %, molette et geste tactile.

La garde minimale observée reste d'environ `90,098 px` en haut et
`1156,111 px` en bas : aucune bordure vide n'apparaît. Le contraste CTA reste
`5,473250:1` au repos et `5,340640:1` en hover pendant tout le mouvement. Avec
`prefers-reduced-motion: reduce`, la transformation reste à `y = 0` tout en
conservant le dimensionnement et le contrôleur unique.

## Matrice Chromium

La source exacte du head rebasé a été chargée dans le checkout servant, sans
supprimer les fichiers DDEV ignorés. La matrice a été exécutée deux fois,
agrégation activée puis désactivée, avec cache froid et chaud :

| Cellule                 | Dimensions CSS |              DPR | Entrée           | Résultat |
| ----------------------- | -------------: | ---------------: | ---------------- | -------- |
| desktop 100 %           |   `1440 × 900` |                1 | souris + clavier | conforme |
| reflow équivalent 150 % |    `960 × 900` |              1.5 | souris + clavier | conforme |
| reflow équivalent 200 % |    `720 × 900` |                2 | souris + clavier | conforme |
| tablette                |   `768 × 1024` |                1 | souris + clavier | conforme |
| mobile                  |    `390 × 844` | émulation mobile | tactile          | conforme |
| mobile étroit           |    `320 × 720` | émulation mobile | tactile          | conforme |
| forced colors           |        desktop |                1 | clavier          | conforme |
| reduced motion          |        desktop |                1 | clavier          | conforme |

Chaque passe principale réussit `458/458` assertions. La passe complémentaire
compte réussit `293/293` assertions supplémentaires dans chaque mode. Les deux
modes ont zéro réponse HTTP 5xx, zéro erreur console et zéro erreur de page.
Les caches chauds renvoient correctement les actifs attendus, y compris les
réponses `304`.

Toutes les cellules ont :

- zéro overflow horizontal document ou cadre ;
- zéro ID dupliqué ;
- un drawer utilisable au clic ou au toucher lorsqu'il est compact ;
- des noms accessibles non vides ;
- aucun warning PHP attribuable à la PR #95.

L'audit des logs trouve zéro entrée de sévérité Drupal `<= 3`, zéro motif PHP
warning/fatal/notice et seulement les avertissements attendus des fixtures
(cron concurrent et refus d'inscription initial), aucun avertissement inattendu.

Les preuves JSON et les captures restent sous :

```text
/tmp/pr95-interactive-contrast-runtime-20260902T100800Z
```

## Snapshot et restauration

Le snapshot nommé pris avant toute écriture de fixture est :

```text
pr95-interactive-contrast-pre-runtime-20260902T100800Z
SHA-256 531aed2be40d022e4508c374def17a6a06de3ae11d63d15c751f8f78f04c2a47
```

Les empreintes avant et après restauration sont strictement identiques :

| Surface                            | Empreinte SHA-256 finale                                           |
| ---------------------------------- | ------------------------------------------------------------------ |
| base de données normalisée         | `161ef10fa5a32b0075cc19c4abd9a3ec8b9d8e0039be392db83f676397134b4b` |
| configuration active, 314 lignes   | `07ec23fcbcbab78e48b746283be7ffb12fda49b5c59264fdf0fea31e0ec32702` |
| fichiers publics                   | `fb1121f1100122f262f4e2910627a6241457b5abc157ef2cb96bee860a6da1ba` |
| utilisateurs, 7 lignes             | `374162b81e6886c2b4c86a4853ba116dcad9e591c984ad0ff07a6b66e9aa8623` |
| alias, 16 lignes                   | `d02e6fe25774d1f5e85f53b9bd31e7bade6f64613be4854c082ff1305d955f85` |
| fichiers DDEV statiques, 43 lignes | `f7debf29b6f48b28b8c6ab510a24b97a8682df380a1561b342abac2f5c3110a2` |

`system.theme` (`olivero` / `claro`) et `system.site` (front `/node`) sont
byte-identiques. Les comptes d'entités reviennent à zéro pour nodes, liens de
menu, Webforms et commandes ; les sept utilisateurs et seize alias initiaux
sont conservés.

Le supplément compte a utilisé un second snapshot nommé, restauré puis supprimé
après preuve d'égalité. Les helpers navigateur et liens de connexion à usage
unique ont été supprimés. Le checkout servant est revenu propre sur sa branche
locale `release/prod` au commit initial
`5b8e80c2e2ac266978ba2be0b8eee2c56a04605f`. DDEV est arrêté et délisté ; aucun
conteneur `ddev-unisonges-*` et aucun processus Chromium de cette tâche ne reste.

## Contrôles statiques de clôture

Les contrôles suivants réussissent sur le diff final :

- parse CSS strict de `styles.css`, `navigation-submenus.css`,
  `auth-account.css` et `reservation-first-tunnel.css` ;
- équilibre des accolades, commentaires et chaînes sur les mêmes feuilles ;
- assertions de spécificité, ordre source, unicité des nouveaux sélecteurs et
  absence de `!important` ajouté ;
- fixtures déterministes normal, visited, hover, focus, focus-visible, active
  et disabled ;
- recalcul WCAG indépendant, y compris rejet explicite des anciennes paires ;
- Prettier sur ce rapport ;
- `git diff --check` ;
- garde exacte des deux fichiers ;
- garde de chevauchement avec les PR ouvertes ;
- scan de secrets.

Au dernier contrôle, GitHub liste 17 PR ouvertes, dont 16 autres PR hors #95.
Aucune ne modifie l'un des deux fichiers de cette PR.

Une revue accessibilité indépendante a validé la cascade, les mesures CTA, le
focus Webform, les consommateurs et la restauration. Sa demande de compléter
les pseudo-états des actions de compte et du bouton de fermeture a conduit à la
passe `293/293` décrite plus haut.

## Matrice différée

Aucune cellule de la matrice Chromium locale demandée ne reste différée. N'ont
pas été exécutés, car hors périmètre ou explicitement interdits :

- accès et validation sur le VPS ou en production ;
- navigateurs autres que Chromium ;
- lecture manuelle avec un lecteur d'écran matériel/OS.

Ces limites ne sont pas présentées comme des succès. La PR #95 peut sortir du
brouillon après les gardes finales Git et GitHub, mais elle ne doit pas être
fusionnée dans le cadre de cette validation.
