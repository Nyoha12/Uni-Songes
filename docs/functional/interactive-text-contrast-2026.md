# Contraste des textes interactifs de réservation — correction statique 2026

## Résultat et statut

Cette PR ferme deux défauts indépendants dans `styles.css`, sans toucher au
markup, au routage ou à la géométrie :

- les liens `.btn--cta` génériques conservent désormais leur texte blanc en
  `:hover` et `:active` ; ces sélecteurs `0-2-0` gagnent sur le `a:hover`
  générique `0-1-1` qui imposait l'ambre ;
- le correctif historique du submit Webform reste intact : `:focus` et
  `:focus-visible` utilisent un outline accent opaque et décalé.

La mesure Chromium déjà fusionnée a isolé le défaut du header : normal,
visited et focus valaient `5,4733:1`, tandis que hover et active tombaient à
`2,5485:1`. La paire corrigée est `#fff` sur `#0f766e`, soit `5,473250:1` avant
filtre et environ `5,340640–5,367179:1` après `brightness(.96)`, selon la
quantification. La paire défaillante `#f59e0b` sur `#0f766e` valait
`2,548468:1`, environ `2,503766–2,515729:1` après filtre.

Le scope de `.btn--cta` couvre le header desktop, le drawer mobile, la fin de
checkout et les actions du portail de réservation. Les actions de carte
emploient `.unisonges-offer-card__cta`; les actions de compte fusionnées par la
PR #99 emploient `.button--primary`, `.btn-primary`, `.auth-account__link` ou
les liens d'onglets; les liens de navigation ont leurs propres sélecteurs; et
les submits d'étape du tunnel emploient `.btn-primary` sans `.btn--cta` : aucune
de ces familles n'est modifiée. Aucun consommateur `.btn--cta` actuel n'est
désactivé ; le seul contrôle de réservation réellement désactivé est un submit
du tunnel sans cette classe et conserve donc exactement sa cascade.

La PR reste en brouillon. Aucune validation navigateur n'est revendiquée sur ce
nouveau diff : la matrice Chromium complète est différée jusqu'à la libération
des ressources DDEV détenues exclusivement par la PR #98.

## Périmètre contrôlé

La base distante a été rafraîchie puis la branche existante
`codex-fix-interactive-text-contrast` a été rebasée sur
`origin/release/prod`. La base et la base de fusion valent :

```text
5b8e80c2e2ac266978ba2be0b8eee2c56a04605f
```

Aucun DDEV, Docker, Drush, navigateur, Chromium, Mailpit ou VPS n'a été utilisé.
Aucun template, PHP, JavaScript, fichier de bibliothèque, URL publique,
arrière-plan, navigation, police de menu, page Contact ou fichier legacy sous
`public/` n'est modifié. Les deux seuls fichiers modifiés sont la règle d'état
ciblée de `styles.css` et le présent rapport.

Les sources inspectées comprennent :

- `css/styles.css`, dans son intégralité ;
- `unisonges_structure/css/reservation-first-tunnel.css`, dans son intégralité ;
- `css/navigation-submenus.css`, dans son intégralité et en lecture seule ;
- `css/auth-account.css`, `unisonges_theme.theme` et
  `unisonges_theme.libraries.yml`, fusionnés par la PR #99 et contrôlés en
  lecture seule avec son rapport
  `docs/functional/auth-account-experience-implementation-2026.md` ;
- tous les producteurs Twig, tableaux de rendu PHP et corps HTML déployables
  qui portent une action de réservation ;
- le rapport runtime fusionné
  `docs/functional/theme-library-integrity-2026.md`, qui contient la matrice
  d'états Chromium à l'origine de la correction CTA ;
- Drupal Core `11.3.3`, Bootstrap Barrio `5.5.20` et Bootstrap `5.3.8`, versions
  exactes verrouillées par `drupal/composer.lock`, extraites uniquement sous
  `/tmp` ;
- les noms de fichiers de toutes les PR ouvertes au moment du contrôle.

Le ZIP Barrio contrôlé porte le SHA-1 Composer
`1aa1dcd370eece14f61bed105398bf55565cc7a3`. Bootstrap correspond au commit
verrouillé `25aa8cc0b32f0d1a54be575347e6d84b70b1acd7` ; le ZIP récupéré pour l'audit
porte le SHA-256
`2cd6946ed5420dc3f140e06144803dda2bb4cc56cd73616cdda93810eaa607f2`.

## Inventaire exact des actions

Les titres de page, titres de section, textes d'aide et messages qui contiennent
« réservation » ne sont pas comptés comme actions. Les destinations indiquées
ci-dessous sont les destinations présentes dans la source, pas une affirmation
sur l'état de la base active. La recherche des producteurs déployables trouve
exactement neuf occurrences `.btn--cta` dans quatre fichiers : deux dans le
header, quatre dans le portail, une à la fin du checkout et deux dans le tunnel
(connexion anonyme et redémarrage après confirmation).

| Libellé / famille                                                                                                     | Route ou contexte                            | DOM ou sortie de rendu                                                                                                                                              | Source                                                                                                                                    | Propriétaire de couleur actuel                                                                                                                                   |
| --------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Réserver`                                                                                                            | header desktop, toutes pages                 | `.site-account > a.btn.btn--cta[href="/reserver"]`                                                                                                                  | `templates/partials/site-header.html.twig:39`                                                                                             | `.btn--cta` en normal/visited/focus ; nouvelle fermeture `.btn--cta:hover` et `.btn--cta:active`                                                                 |
| `Réserver`                                                                                                            | drawer mobile, toutes pages                  | `.mobile-drawer__account > a.btn.btn--cta[href="/reserver"]`                                                                                                        | `templates/partials/site-header.html.twig:61`                                                                                             | même cascade `.btn--cta` corrigée                                                                                                                                |
| `Réserver un cours`                                                                                                   | `/accueil`                                   | `p.unisonges-offer-card__cta > a[href="/reservation-cours"]`                                                                                                        | `drupal/scripts/apply-content-architecture-2026.sh:251`                                                                                   | parent `.unisonges-offer-card__cta`, puis héritage explicite du lien                                                                                             |
| `Réserver un cours`                                                                                                   | `/cours-et-stages`                           | même structure                                                                                                                                                      | même script, ligne 295                                                                                                                    | même composant                                                                                                                                                   |
| `Réserver un cours`                                                                                                   | `/cours`                                     | même structure                                                                                                                                                      | même script, ligne 322                                                                                                                    | même composant                                                                                                                                                   |
| `Réserver un cours de didgeridoo`                                                                                     | `/cours/didgeridoo`                          | `p.unisonges-offer-card__cta > a`                                                                                                                                   | même script, ligne 366                                                                                                                    | même composant                                                                                                                                                   |
| `Réserver un cours d'essai`                                                                                           | `/cours/didgeridoo`, action secondaire       | `.unisonges-detail-section p > a` sans CTA parent                                                                                                                   | même script, ligne 367                                                                                                                    | lien éditorial `.unisonges-detail-section p > a`                                                                                                                 |
| `Réserver un cours de guimbarde`                                                                                      | `/cours/guimbarde`                           | `p.unisonges-offer-card__cta > a`                                                                                                                                   | même script, ligne 389                                                                                                                    | CTA d'offre                                                                                                                                                      |
| `Réserver un cours de méditation / improvisation`                                                                     | `/cours/meditation-improvisation`            | même structure                                                                                                                                                      | même script, ligne 411                                                                                                                    | CTA d'offre                                                                                                                                                      |
| `Réserver un cours`                                                                                                   | `/contact`                                   | `.actions-row > a.btn[href="/reserver"]`                                                                                                                            | `templates/content/node--7.html.twig:10-15` puis `templates/includes/_actions-row.html.twig:1-5`                                          | aucun sélecteur `.actions-row`; lien global et `.btn` de mise en page seulement                                                                                  |
| `Réserver un cours`                                                                                                   | fin de checkout éligible                     | `.unisonges-checkout-course-notice a.btn.btn--cta[href="/reserver"]`                                                                                                | `unisonges_structure.module:837-843`                                                                                                      | même cascade `.btn--cta` corrigée                                                                                                                                |
| `Démarrer le parcours guidé`                                                                                          | `/reserver`, base actuelle                   | `.reservation-portal__actions > a.btn.btn--cta`                                                                                                                     | `templates/content/node--8.html.twig:13-15`                                                                                               | CTA du portail                                                                                                                                                   |
| `Se connecter pour réserver`, `Choisir un créneau`, `Choisir cours et créneau`                                        | variantes de `/reserver`                     | `.reservation-portal__actions > a.btn.btn--cta`                                                                                                                     | même template, lignes 24-52                                                                                                               | CTA du portail                                                                                                                                                   |
| `Commencer ma réservation`                                                                                            | uniquement PR #86, absent de la base         | `.reservation-portal__actions > a.btn.btn--cta`                                                                                                                     | diff ouvert de `node--8.html.twig`, futures lignes 16-19                                                                                  | CTA du portail inchangé                                                                                                                                          |
| `Se connecter`                                                                                                        | `/reservation-cours`, anonyme                | `.reservation-first-course .reservation-portal__actions > a.btn.btn--cta`                                                                                           | `ReservationFirstCourseTunnelForm.php:261-275`                                                                                            | CTA du portail                                                                                                                                                   |
| `Continuer vers les créneaux`, `Continuer vers les détails`, `Continuer vers le paiement`, `Confirmer la réservation` | étapes authentifiées de `/reservation-cours` | `input.reservation-first-course__action--next.button.button--primary.js-form-submit.form-submit.btn.btn-primary[type="submit"]` dans la configuration reproductible | même classe PHP, lignes 363-375, 448-469, 563-583 et 664-684 ; prétraitements Core/Barrio                                                 | Bootstrap attendu, absent de la cascade reproductible ; « Continuer vers les détails » porte réellement `disabled` et `is-disabled` si le widget manque (`:457`) |
| `Réserver un autre cours`                                                                                             | confirmation `/reservation-cours`            | `input.btn.btn--cta.button.button--primary.js-form-submit.form-submit.btn-primary[type="submit"]` dans `.reservation-portal__actions`                               | même classe PHP, lignes 754-770 ; prétraitements Core/Barrio                                                                              | CTA du portail ; aucune occurrence désactivée actuelle                                                                                                           |
| `Réserver ce stage`                                                                                                   | Stage publié avec billet éligible            | `a.unisonges-offer-card__cta` direct                                                                                                                                | `unisonges_theme.theme:137-142`                                                                                                           | CTA d'offre direct                                                                                                                                               |
| soumission du Webform historique (`Soumettre` par défaut)                                                             | formulaire de réservation historique         | `input.webform-button--submit.button.button--primary.js-form-submit.form-submit.btn.btn-primary[type="submit"]` sous le formulaire `cours-particuliers-reservation` | `webform.settings.yml:13`, Webform `WebformSubmissionForm.php:1438`, Core `Button::preRenderButton()` et `bootstrap_barrio.theme:816-864` | sélecteurs submit de `styles.css:1801-1836`, y compris la fermeture de focus                                                                                     |

`unisonges_structure.post_update.php:47-49,78` contient encore la définition
historique d'un lien de menu « Réserver un cours », mais le menu final documenté
ne l'utilise pas comme CTA principal. `templates/includes/_header.html.twig:17`
contient aussi un ancien lien nu ; aucun template de page actuel n'inclut ce
header. Les vingt occurrences semblables du frontend legacy sous `public/`
sont hors du thème Drupal et hors du périmètre autorisé.

Les submits non primaires du même tunnel ont également été inclus dans l'audit
de cascade : « Retour au cours » (`:461-468`), « Retour au créneau »
(`:575-582`), « Retour aux détails » (`:676-683` et `:618-622`), ainsi que
« Recommencer » (`:866-881`). Ils sortent tous en `<input type="submit">` avec
`btn btn-primary` ajouté par Barrio, même sans `#button_type: primary`, et
restent donc soumis à la même absence de feuille Bootstrap.

## Cascade réellement démontrable

### Chargement des bibliothèques

Bootstrap Barrio `5.5.20` est le thème de base et attache
`bootstrap_barrio/global-styling`. Cette bibliothèque contient les composants
propres à Barrio, mais aucun sélecteur générique `a`, `.btn`, `.btn:hover`,
`.btn:focus-visible`, `.btn:active` ou `.btn:disabled`.

Le code Barrio n'attache Bootstrap que dans l'un des deux cas suivants :

1. le module `bootstrap_library` existe, puis le réglage
   `bootstrap_barrio_library` sélectionne sa bibliothèque ;
2. ce module n'existe pas et `bootstrap_barrio_source` contient un identifiant
   de bibliothèque non vide.

Le dépôt ne verrouille ni n'active `drupal/bootstrap_library`, ne synchronise
aucun `bootstrap_barrio_source` et ne possède pas de
`unisonges_theme.settings.yml`. Le réglage
`bootstrap_barrio_library: production` de `bootstrap_barrio.settings.yml` est
donc inerte dans la branche exécutée sans ce module. La bibliothèque Barrio
`bootstrap` existe dans le paquet, mais elle n'est pas attachée et vise
`/libraries/bootstrap/dist/css/bootstrap.min.css`, chemin qui n'existe pas
dans le checkout reproductible.

Drupal Core `11.3.3` ne fusionne pas ici le fichier de réglages du thème de base
à la lecture : `ThemeSettingsProvider::buildThemeSettings()` charge
`unisonges_theme.settings`, absent du dépôt reproductible. Par conséquent,
`theme_get_setting('bootstrap_barrio_button')` est vide et la suggestion Twig
Barrio `input__submit_button` n'est pas ajoutée. Core conserve donc un
`<input type="submit">` et lui ajoute `button`, `button--primary` le cas échéant,
`js-form-submit` et `form-submit`. Le prétraitement Barrio ajoute ensuite `btn`
et `btn-primary` même sans la suggestion `<button>`. Cette distinction de tag
n'apporte toujours aucune couleur sans la feuille Bootstrap.

Le thème custom attache désormais globalement la bibliothèque existante
`unisonges_theme/unisonges-layout`, qui déclare `styles.css` puis
`navigation-submenus.css`. La bibliothèque `contact` en dépend et les deux
shells conservent leur attache explicite dédupliquée par Drupal. La validation
runtime fusionnée a confirmé le chargement unique de ces feuilles avec et sans
agrégation. Elle n'a pas ajouté Bootstrap aux submits du tunnel.

Enfin, le formulaire `/reservation-cours` attache bien
`unisonges_structure/reservation-first-tunnel`. Cette feuille ne gère que la
grille, les radios et l'ordre des actions ; elle ne déclare aucun `color`,
`background`, `opacity`, `outline` ou état désactivé sur les submits. Barrio
ajoute `btn btn-primary` aux submits, mais la feuille qui donne un sens visuel à
ces classes n'est pas attachée par la configuration reproductible.

### Intégration de la PR #99 fusionnée

La base `5b8e80c2e2ac266978ba2be0b8eee2c56a04605f` contient la PR #99. Sa
bibliothèque `auth-account` dépend de `unisonges-layout` : sur les seules routes
d'authentification et de compte où le thème l'attache, l'ordre est donc
`styles.css`, `navigation-submenus.css`, puis `auth-account.css`.

La recherche croisée des producteurs et des sélecteurs établit que :

- aucun token `.btn--cta` n'existe dans `auth-account.css`, les alters de
  formulaire de compte ou le template de messages de la PR #100 ;
- les actions primaires de compte portent `.button--primary`, `.btn-primary` ou
  sont des `input[type="submit"]` sous `.auth-account-form` ; les autres actions
  portent `.auth-account__link`, `.auth-account__secondary-action`, `.btn-close`
  ou sont des liens d'onglets locaux ;
- les sélecteurs `.btn--cta:hover` et `.btn--cta:active` de cette PR ne peuvent
  donc modifier ni ces contrôles, ni les erreurs de connexion, ni leurs liens ou
  boutons de fermeture ;
- le scope Webform exige un ancêtre dont l'identifiant contient
  `webform-submission-cours-particuliers-reservation`. Aucun formulaire de
  compte ne le possède et `/reserver` n'est pas une route `auth-account` ;
- le seul recouvrement intentionnel est le CTA `Réserver` du header ou du drawer
  affiché sur une page de compte. Cette PR y maintient le texte blanc ; la PR #99
  y ajoute seulement son outline `:focus-visible` de `3px` avec un offset de
  `3px`. Les propriétés se composent sans se remplacer.

Les paires opaques de la surface compte ont été recalculées indépendamment :

| Surface fusionnée par la PR #99    |   normal / visited |                                                                                                                             hover / focus / focus-visible |                                      active | disabled / résultat d'intégration                        |
| ---------------------------------- | -----------------: | --------------------------------------------------------------------------------------------------------------------------------------------------------: | ------------------------------------------: | -------------------------------------------------------- |
| action primaire de formulaire      | `5,473250:1` / n/a |                                                                                  hover et focus-visible `7,964671:1`; focus `5,473250:1`; outline visible | `5,473250:1` isolé, `7,964671:1` avec hover | aucune variante actuelle inventoriée ; cascade inchangée |
| `.auth-account__link`              |       `7,834799:1` |                                                              hover `11,593006:1`; focus `7,834799:1`; focus-visible conserve la paire et ajoute l'outline |                          `7,834799:1` isolé | n/a pour les liens actuels ; aucun match PR #95          |
| `.auth-account__secondary-action`  |       `7,834799:1` |                                                                                                  hover et focus-visible `10,313943:1`; focus `7,834799:1` |                          `7,834799:1` isolé | n/a ; aucun match PR #95                                 |
| action managed-file                |      `14,375439:1` |                                                                                            paire inchangée en hover/focus/focus-visible ; outline visible |                             paire inchangée | comportement Core/Barrio inchangé                        |
| erreur de connexion                |       `9,287848:1` |                                                                                  liens par héritage ; focus-visible conserve la paire et ajoute l'outline |                                    inchangé | présentation et template inchangés                       |
| CTA `Réserver` sur une page compte |       `5,473250:1` | hover `5,473250:1`, env. `5,340640–5,367179:1` après filtre; focus et focus-visible `5,473250:1`; outline PR #99 ≥ `5,462731:1` sur les surfaces auditées |                         même paire corrigée | aucun CTA désactivé actuel                               |

Les actions locales de compte restent propriétaires de leur cascade. Leur paire
de base vaut `7,664442:1` ; la paire active prévue vaut `5,473250:1`. Le
sélecteur actif fusionné place ses tokens dans `:where()`, si bien que la règle
de base plus spécifique peut conserver la première paire. Les deux résultats
dépassent `4,5:1`; cette nuance préexistante de distinction de l'onglet actif
n'est ni causée ni corrigée par la PR #95 et reste à observer dans la matrice
runtime.

Les fichiers possédés par la PR #99 — son rapport, `auth-account.css`,
`unisonges_theme.theme` et `unisonges_theme.libraries.yml` — ainsi que le
template de messages de la PR #100 sont byte-identiques entre la base et cette
branche. `auth-account.css` demeure donc l'unique propriétaire des formulaires,
actions et messages de compte.

### Sélecteurs custom et spécificité

L'inventaire exhaustif des pseudo-états pertinents donne :

- `a` est déclaré aux lignes 10, 60 et 155 ; seules les lignes 60/155 donnent
  une couleur ;
- aucun `a:visited`, `a:focus`, `a:focus-visible` ou `a:active` **global** n'est
  déclaré ; `a:hover:61` est le seul pseudo-état global coloré ;
- `:visited` est explicite pour le lien enfant du CTA d'offre, mais pas pour les
  liens `.btn--cta` génériques, dont la couleur auteur normale gagne déjà sur
  la couleur visited de l'agent utilisateur ;
- `.btn--cta:hover` et `.btn--cta:active` fixent maintenant explicitement le
  texte blanc sans modifier le filtre, l'ombre ou le fond ;
- `:focus` est présent sur le CTA d'offre et le lien éditorial de détail ; la
  correction ajoute `:focus` et `:focus-visible` uniquement au submit du
  Webform et renforce son outline ;
- aucun état disabled custom ne cible les CTA génériques inventoriés et aucun
  consommateur actuel `.btn--cta` n'est désactivé ;
- `.btn` ne donne que la géométrie, sauf dans le scope du portail ;
- les seuls submits colorés par le thème custom sont ceux du Webform historique
  via `input[type="submit"]` ou `.button--primary` ; aucun submit du tunnel ne
  reçoit de couleur de `reservation-first-tunnel.css`.

| Sélecteur                                                           |                                                          Spécificité | Déclarations de contraste utiles                   | Conséquence                                                                      |
| ------------------------------------------------------------------- | -------------------------------------------------------------------: | -------------------------------------------------- | -------------------------------------------------------------------------------- |
| `a` (`styles.css:60`, répété ligne 155)                             |                                                              `0-0-1` | `color: var(--accent)`                             | vert `#0f766e`, arrière-plan hérité/transparent                                  |
| `a:hover` (`:61`)                                                   |                                                              `0-1-1` | `color: var(--accent2)`                            | ambre `#f59e0b`; `2,148:1` sur blanc                                             |
| `.btn` (`:26`)                                                      |                                                              `0-1-0` | aucune couleur ; `text-decoration:none`            | mise en page uniquement ; pas de paire explicite                                 |
| `.btn--cta` (`:64`)                                                 |                                                              `0-1-0` | `background:var(--accent); color:#fff`             | paire normale `5,473:1`                                                          |
| `.btn--cta:hover` (`:65`, puis `:218`)                              |                                                              `0-2-0` | texte `#fff`, filtre `.96`, puis ombre             | gagne sur `a:hover` et conserve une paire supérieure à `4,5:1`                   |
| `.btn--cta:active` (`:66`)                                          |                                                              `0-2-0` | texte `#fff`                                       | ferme l'état actif, y compris lorsqu'il est simultanément survolé                |
| focus de champ Webform (`:1778-1784`)                               |                                                              `0-2-2` | outline accent à `.22`, bordure accent             | l'outline ne vaut que `1,33–1,37:1`; la bordure est ensuite perdue sur le submit |
| submit Webform normal (`:1801-1819`)                                | `0-3-2` pour `input[type="submit"]`, `0-3-1` pour `.button--primary` | fond `#0f766e`, texte `#fff`, bordure transparente | paire texte/fond `5,473:1`, mais gagne sur la bordure du focus précédent         |
| submit Webform hover (`:1821-1827`)                                 |                                                    `0-4-2` / `0-4-1` | filtre `.96`                                       | paire filtrée encore supérieure à `4,5:1`                                        |
| submit Webform `:focus` et `:focus-visible` (`:1829-1836`)          |                                                              `0-4-2` | bordure et outline opaques `#0f766e`, offset `2px` | correction : indicateur au moins `4,352:1` sur les fonds clairs audités          |
| `.reservation-portal__actions .btn` (`:2136`)                       |                                                              `0-2-0` | fond blanc translucide, texte `#0b1220`            | action secondaire du portail                                                     |
| `.reservation-portal__actions .btn--cta` (`:2145`)                  |                                                              `0-2-0` | fond `#0f766e`, texte `#fff`                       | paire fermée dans les états texte du lien et du submit actuels                   |
| `.unisonges-offer-card__cta` (`:2412`)                              |                                                              `0-1-0` | fond `var(--accent)`, texte `#fff`                 | paire du parent ou du lien direct                                                |
| `.unisonges-offer-card__cta a, ... a:visited` (`:2432`)             |                                                    `0-1-1` / `0-2-1` | `color:inherit`                                    | protège explicitement le lien enfant normal et visité                            |
| `.unisonges-offer-card__cta:hover, :focus, :focus-within` (`:2441`) |                                                              `0-2-0` | texte `#fff`, filtre `.96`                         | paire filtrée encore supérieure à `4,5:1`                                        |
| `.unisonges-offer-card__cta a:hover, ... a:focus` (`:2449`)         |                                                              `0-2-1` | `color:inherit`                                    | gagne sur le survol global                                                       |

Avant correction, sur un `a.btn.btn--cta` générique du header, du drawer ou du
checkout, `a:hover` (`0-1-1`) gagnait sur la couleur normale de `.btn--cta`
(`0-1-0`) : le survol devenait ambre sur vert, seulement `2,548468:1` avant
filtre et environ `2,503766–2,515729:1` après `brightness(.96)`. L'état actif à
la souris cochait aussi `:hover` et échouait de la même manière.

Après correction, `.btn--cta:hover` et `.btn--cta:active` ont une spécificité
`0-2-0` et gardent `#fff`. Le ratio vaut `5,473250:1` avant filtre et environ
`5,340640–5,367179:1` après filtre. Aucun `!important` ni nouveau token de
palette n'est nécessaire. La règle `:visited` n'est pas dupliquée : aucune
déclaration auteur concurrente ne la justifie et la mesure runtime fusionnée
confirme déjà `5,4733:1`.

Sur le submit Webform, le sélecteur de focus général (`0-2-2`) fixe d'abord une
bordure verte et un outline vert à 22 % d'opacité. Le sélecteur primaire placé
plus loin (`0-3-2`) remet ensuite la bordure à transparent ; l'outline faible
reste le seul indicateur. Les nouveaux sélecteurs `:focus` et `:focus-visible`
(`0-4-2`), placés après le bloc primaire, rétablissent une bordure et un outline
opaques avec la couleur accent existante. Ils ne ciblent ni le header, ni
Commerce, ni les champs non-submit, ni les liens éditoriaux.

Le paquet Bootstrap `5.3.8` verrouillé a aussi été audité comme cascade
potentielle, sans le considérer chargé : `.btn` est `0-1-0`, ses états hover,
focus-visible et disabled sont `0-2-0`, et certains états actifs atteignent
`0-3-0`. Les variables `.btn-primary` donnent `4,501:1` en normal, `5,839:1`
en hover/focus et `6,439:1` en actif. L'opacité disabled de `.65` compose à
environ `2,62:1` sur blanc : le texte désactivé est exempté du minimum WCAG,
mais ce résultat ne satisfait pas à lui seul l'exigence de lisibilité du
présent audit.

Si une future configuration attachait une feuille Bootstrap supplémentaire,
ses variables et ses sélecteurs actifs devraient être remesurés. Ce cas n'est
pas la cascade attachée qui a produit les valeurs Chromium fusionnées. Les
sélecteurs Webform `0-4-2` gagneraient toujours dans leur scope ; la matrice
runtime différée vérifiera également l'ordre réel des feuilles sans ajouter ici
de filet global.

### Recherche des modes d'effacement

Dans les quatre feuilles auditées et les composants Barrio attachés :

- aucun texte de CTA n'utilise `color:transparent` ;
- aucun `-webkit-text-fill-color` n'existe ;
- aucune opacité proche de zéro ne cible un CTA ou l'un de ses ancêtres ;
- aucun état `:visited` concurrent n'efface une couleur ;
- aucune classe disabled n'est appliquée à tort dans les tableaux de rendu ;
- aucune règle de CTA ne supprime un outline ;
- `reservation-first-tunnel.css` ne peut pas gagner une couleur, puisqu'il
  n'en déclare aucune ;
- `navigation-submenus.css` ne contient aucun sélecteur de réservation, de
  `.btn`, de `.btn--cta`, d'offre, de portail ou de formulaire submit ;
- `auth-account.css` ne contient aucun `.btn--cta` et ses actions, messages,
  liens, focus visibles et boutons managed-file restent dans le scope
  `.auth-account-page` ou `.auth-account-form` fusionné par la PR #99.

Les opacités nulles trouvées dans `styles.css` appartiennent aux anciens calques
d'arrière-plan et au backdrop fermé. Les `display:none` pertinents du header
séparent les variantes desktop/mobile et ne dépendent pas d'un survol.

## Fixtures HTML/CSS déterministes

Les fixtures statiques utilisent exactement les formes suivantes ; aucun DOM
navigateur n'est synthétisé :

```html
<p class="unisonges-offer-card__cta">
  <a href="/reservation-cours">Réserver un cours</a>
</p>
<a class="unisonges-offer-card__cta" href="/product/1">Réserver ce stage</a>
<div class="reservation-portal__actions">
  <a class="btn btn--cta" href="/reservation-cours"
    >Démarrer le parcours guidé</a
  >
</div>
<div class="reservation-portal__actions">
  <input
    class="btn btn--cta button button--primary js-form-submit form-submit btn-primary"
    type="submit"
    value="Réserver un autre cours"
  />
</div>
<div class="site-account">
  <a class="btn btn--cta" href="/reserver">Réserver</a>
</div>
<div class="mobile-drawer__account">
  <a class="btn btn--cta" href="/reserver">Réserver</a>
</div>
<div class="unisonges-checkout-course-notice">
  <a class="btn btn--cta" href="/reserver">Réserver un cours</a>
</div>
<section class="actions-row">
  <a class="btn" href="/reserver">Réserver un cours</a>
</section>
<form class="reservation-first-course">
  <div class="reservation-first-course__actions">
    <input
      class="reservation-first-course__action--next button button--primary js-form-submit form-submit btn btn-primary"
      type="submit"
      value="Confirmer la réservation"
    />
  </div>
</form>
<form class="reservation-first-course">
  <div class="reservation-first-course__actions">
    <input
      class="reservation-first-course__action--next button button--primary js-form-submit form-submit is-disabled btn btn-primary"
      type="submit"
      value="Continuer vers les détails"
      disabled
    />
  </div>
</form>
<form id="webform-submission-cours-particuliers-reservation-add-form">
  <div class="form-actions">
    <input
      class="webform-button--submit button button--primary js-form-submit form-submit btn btn-primary"
      type="submit"
      value="Soumettre"
    />
  </div>
</form>
<main class="auth-account-page auth-account-page--login">
  <div class="unisonges-system-messages">
    <div class="alert alert-danger">
      Erreur de connexion
      <button class="btn-close" type="button"></button>
    </div>
  </div>
  <form class="auth-account-form user-login-form">
    <div class="form-actions">
      <input
        class="button button--primary btn btn-primary"
        type="submit"
        value="Se connecter"
      />
    </div>
  </form>
  <a class="auth-account__link" href="/user/password"
    >Réinitialiser le mot de passe</a
  >
  <a
    class="auth-account__link auth-account__secondary-action"
    href="/user/register"
    >Créer un compte</a
  >
  <nav class="block-local-tasks-block">
    <a aria-current="page" href="/user">Voir</a>
  </nav>
</main>
```

`/product/1` est une valeur stable de fixture pour la route canonique
`/product/{product_id}` produite dynamiquement ; elle ne prétend pas identifier
un produit publié. La seconde fixture du tunnel est la variante disabled réelle
de la même famille, pas un composant supplémentaire. La classe
`webform-button--submit` vient du prétraitement Webform ; les classes
`button…form-submit` puis `btn…btn-primary` viennent respectivement de Core et
Barrio. La dernière fixture reprend les classes et relations de la surface
compte fusionnée par la PR #99 ; aucune de ses actions ne porte `.btn--cta`.

Le calcul sRGB applique WCAG 2.x à `#fff`, `#0f766e`, `#f59e0b` et aux
couleurs filtrées par `brightness(.96)`. La matrice représente uniquement la
cascade attachable prouvée par le dépôt ; `non fermé` signifie qu'aucune paire
opaque ne peut être démontrée statiquement.

| Fixture                                                 | normal                                       | visited                                    | hover                                          | focus                                                             | focus-visible                                  | active                                           | disabled                                                                  |
| ------------------------------------------------------- | -------------------------------------------- | ------------------------------------------ | ---------------------------------------------- | ----------------------------------------------------------------- | ---------------------------------------------- | ------------------------------------------------ | ------------------------------------------------------------------------- |
| wrapper CTA d'offre + lien enfant                       | `5,473:1`                                    | `5,473:1`, héritage explicite              | env. `5,34–5,37:1` après filtre                | texte conforme ; outline non supprimé, clarté runtime             | même paire ; clarté de l'indicateur runtime    | `5,473:1` isolé ; avec hover, env. `5,34–5,37:1` | n/a, aucun lien disabled actuel                                           |
| lien direct CTA d'offre                                 | `5,473:1`                                    | `5,473:1`, la classe auteur gagne sur l'UA | env. `5,34–5,37:1`                             | texte conforme ; outline non supprimé, clarté runtime             | même paire ; clarté de l'indicateur runtime    | `5,473:1` isolé ; avec hover, env. `5,34–5,37:1` | n/a dans la source actuelle                                               |
| lien CTA du portail                                     | `5,473:1`                                    | `5,473:1`                                  | env. `5,34–5,37:1`; le sélecteur portail gagne | texte `5,473:1`; outline non supprimé, clarté runtime             | même paire ; clarté de l'indicateur runtime    | `5,473:1` isolé ; avec hover, env. `5,34–5,37:1` | n/a pour les liens actuels                                                |
| submit CTA « Réserver un autre cours »                  | `5,473:1`                                    | n/a                                        | env. `5,34–5,37:1`; le sélecteur portail gagne | texte `5,473:1`; outline UA non supprimé, clarté runtime          | même paire ; clarté de l'indicateur runtime    | `5,473:1` isolé ; avec hover, env. `5,34–5,37:1` | n/a dans la source actuelle                                               |
| CTA générique du header et du drawer, corrigé           | `5,473:1`                                    | `5,473:1`                                  | `5,473:1`, env. `5,34–5,37:1` après filtre     | texte `5,473:1`; outline non supprimé, clarté runtime             | même paire ; clarté de l'indicateur runtime    | `5,473:1`, env. `5,34–5,37:1` avec hover         | n/a pour les liens actuels                                                |
| CTA générique de fin de checkout, corrigé               | `5,473:1`                                    | `5,473:1`                                  | `5,473:1`, env. `5,34–5,37:1` après filtre     | texte `5,473:1`; outline non supprimé, clarté runtime             | même paire ; clarté de l'indicateur runtime    | `5,473:1`, env. `5,34–5,37:1` avec hover         | n/a pour le lien actuel                                                   |
| Contact `.actions-row > a.btn`                          | texte vert, fond transparent : **non fermé** | même dépendance                            | ambre, fond transparent : **non fermé**        | non fermé ; outline non supprimé, clarté runtime                  | non fermé ; clarté de l'indicateur runtime     | non fermé                                        | n/a                                                                       |
| submit primaire du tunnel                               | paire UA : **non fermée**                    | n/a                                        | non fermé                                      | non fermé                                                         | outline UA non supprimé, apparence non prouvée | non fermé                                        | variante réelle : distinction et contraste non fermés                     |
| submit primaire du Webform historique, après correction | `5,473:1`                                    | n/a                                        | env. `5,34–5,37:1`                             | texte `5,473:1`; outline ≥ `4,352:1` sur les fonds clairs audités | même paire et même indicateur explicite        | `5,473:1` isolé ; avec hover, env. `5,34–5,37:1` | aucune occurrence disabled actuelle ; distinction théorique non spécifiée |

L'action secondaire « Réserver un cours d'essai » reste un lien éditorial : son
hover/focus explicite `#f59e0b` ne vaut que `2,148:1` sur blanc. Une correction
globale de `a:hover` ou des liens éditoriaux modifierait des contenus hors
périmètre et n'est pas ajoutée à cette PR.

Les CTA en forme de pilule conservent une forme, un fond, une graisse et une
ombre : leur signification ne repose pas sur la couleur seule. Le lien Contact
nu perd son soulignement via `.btn` sans recevoir de bordure ou de fond propre ;
c'est une mesure runtime et une décision de composant requises, pas un motif
pour modifier Contact depuis cette branche.

## Cause et décision de correction

La mesure runtime fusionnée rend la collision CTA déterministe :

1. `.btn--cta` donne le texte blanc et le fond accent en état normal ;
2. `a:hover` a une spécificité `0-1-1`, supérieure à `.btn--cta` (`0-1-0`), et
   remplace le blanc par `var(--accent2)` ;
3. `.btn--cta:hover` ne déclarait que le filtre et l'ombre, donc ne restaurait
   aucune couleur ;
4. un active à la souris est aussi hover et héritait de la même collision ;
5. les CTA du portail étaient déjà protégés par
   `.reservation-portal__actions .btn--cta` (`0-2-0`), ce qui explique leur
   ratio runtime stable.

La correction la plus petite ajoute `color:#fff` aux seuls
`.btn--cta:hover` et `.btn--cta:active`. Elle utilise la couleur déjà présente
en état normal et ne change ni fond, filtre, ombre, outline, opacité, dimension,
padding ou destination. Les liens éditoriaux, les cartes, les comptes et la
navigation ne correspondent pas à ces sélecteurs.

Le défaut de focus du submit Webform reste indépendant : ses sélecteurs
`0-4-2`, placés après le bloc primaire, conservent la bordure et l'outline
opaques `#0f766e` pour `:focus` et `:focus-visible`. Le fichier
`reservation-first-tunnel.css` reste inchangé et aucun filet CSS n'est ajouté
aux boutons qui n'emploient pas `.btn--cta`.

## Preuve runtime fusionnée et limite de cette phase

La validation runtime fusionnée avec la correction de bibliothèques a mesuré le
header `Réserver` dans quatre combinaisons agrégation/cache. Les ratios sont
restés identiques :

| État    | Avant ce diff | Déclaration gagnante avant ce diff |
| ------- | ------------: | ---------------------------------- |
| normal  |    `5,4733:1` | `.btn--cta { color:#fff }`         |
| visited |    `5,4733:1` | même couleur auteur                |
| hover   |    `2,5485:1` | `a:hover { color:var(--accent2) }` |
| focus   |    `5,4733:1` | `.btn--cta { color:#fff }`         |
| active  |    `2,5485:1` | état actif simultanément hover     |

Le CTA de réservation protégé par le scope portail est resté à `5,4733:1` dans
les cinq états. Ces résultats confirment à la fois la cause, le choix du scope
générique et l'absence de raison de modifier les règles éditoriales ou de
navigation.

Cette branche n'utilise pas les ressources runtime. Le nouveau diff est validé
statiquement et restera en brouillon jusqu'à une nouvelle matrice Chromium
réelle après libération de DDEV par la PR #98.

## PR ouvertes et garde de chevauchement

Seize PR sont ouvertes, dont la présente PR #95. La garde paginée a comparé les
fichiers des quinze autres PR (#82, #85 à #90, #92, #94, #96 à #98 et #101 à
#103) avec les deux fichiers autorisés ici : l'intersection est vide.

La PR #99 est fusionnée dans la base. Son scope `auth-account.css`,
`unisonges_theme.theme`, `unisonges_theme.libraries.yml` et son rapport est
byte-identique entre la base et cette branche. La PR #100 est également
fusionnée ; son template de message
`templates/misc/status-messages--unisonges-inline.html.twig` reste inchangé.
Selon la contrainte opérationnelle fournie, la PR #98 possède exclusivement les
ressources runtime ; elles n'ont pas été utilisées. La PR #86 modifie le markup
du portail sans chevauchement de fichiers et la PR #92 conserve le composant
distinct `.unisonges-offer-card__cta`.

## Validation statique exécutée

Les validations sont relançables depuis la racine du dépôt. Le parseur CSS est
installé temporairement par `npm exec` et n'écrit aucun actif dans le dépôt :

```bash
git fetch origin release/prod
test "$(git merge-base HEAD origin/release/prod)" = "$(git rev-parse origin/release/prod)"

for css_file in \
  drupal/web/themes/custom/unisonges_theme/css/styles.css \
  drupal/web/modules/custom/unisonges_structure/css/reservation-first-tunnel.css \
  drupal/web/themes/custom/unisonges_theme/css/navigation-submenus.css \
  drupal/web/themes/custom/unisonges_theme/css/auth-account.css
do
  npm exec --yes --package=csstree-validator@4.0.1 -- \
    csstree-validator "$css_file"
done

git diff --check origin/release/prod --
```

Un contrôle Node en lecture seule a ensuite :

- vérifié l'équilibre des accolades, commentaires et chaînes des quatre CSS ;
- confirmé l'absence de `-webkit-text-fill-color`, de texte transparent et
  d'opacité CTA ;
- vérifié la présence exacte des blocs de sélecteurs inventoriés ;
- calculé les rapports sRGB normal, hover et filtré ;
- évalué les dix fixtures de réservation, la variante tunnel disabled et les
  fixtures de compte sur les sept états du tableau ;
- échoué volontairement si une fixture marquée fermée perdait sa paire
  explicite ou si une fixture non fermée était présentée comme sûre ;
- prouvé que `.btn--cta:hover` et `.btn--cta:active` (`0-2-0`) gagnent sur
  `a:hover` (`0-1-1`) et que la règle visited normale reste sûre ;
- prouvé que le sélecteur focus `0-4-2` gagne sur le submit `0-3-2` et le focus
  de champ `0-2-2`, et que son bloc reste identique au correctif historique ;
- prouvé l'absence de `.btn--cta` dans le scope compte, l'ordre de source après
  `unisonges-layout`, la propriété persistante de ses messages et actions, et
  l'absence de changement de présentation des erreurs de connexion.

Les résultats calculés sont : paire corrigée `5,473250:1`, paire corrigée
filtrée `5,340640–5,367179:1`, ancienne collision `2,548468:1`, ancienne
collision filtrée `2,503766–2,515729:1`, ancien outline `1,330–1,368:1` et
nouvel outline au minimum `4,352:1`. Aucune fixture corrigée ne conserve la
paire à `2,548468:1`. Pour l'intégration #99, les calculs donnent notamment
`7,964671:1` au bouton primaire hover/focus-visible, `9,287848:1` à l'erreur de
connexion et au moins `5,462731:1` à l'outline focus-visible brun de la surface
compte sur les fonds audités.
Prettier `3.6.2` valide le rapport. Gitleaks `8.30.0`, téléchargé hors dépôt et
vérifié par SHA-256, ne trouve aucun secret dans les deux fichiers. La revue
accessibilité/contraste indépendante ne relève aucun blocker.

La garde de périmètre finale attend exactement :

```text
docs/functional/interactive-text-contrast-2026.md
drupal/web/themes/custom/unisonges_theme/css/styles.css
```

Elle vérifie aussi par `git diff --exit-code` qu'aucune règle de navigation,
police de menu ou arrière-plan n'a changé. `git diff --check`, le scan de
secrets et la comparaison paginée des fichiers des quinze autres PR ouvertes
ont réussi ; leur intersection avec les deux fichiers attendus est vide. Ces
gardes sont relancées sur l'index et le commit immédiatement avant le push.

## Matrice Chromium différée

Après libération des ressources par la PR #98, la validation runtime doit
couvrir le header `Réserver`, le drawer mobile, la fin de checkout, le portail,
les cartes, les formulaires/actions/messages de compte, l'erreur de connexion,
les liens de compte et les boutons sans `.btn--cta`, puis :

- états anonyme et authentifié ;
- normal, visited, hover, focus, focus-visible, active et disabled ;
- souris et clavier ;
- desktop, tablette et mobile ;
- zoom 100 %, 150 % et 200 % ;
- panneaux clairs et pages avec image de fond ;
- observation high-contrast/forced-colors ;
- absence de débordement horizontal ;
- absence de régression sur navigation, cartes, formulaires et boutons
  Commerce.

Le chargement des bibliothèques doit être contrôlé avec agrégation CSS activée
et désactivée, caches froids puis chauds. La matrice doit confirmer les règles
gagnantes, la paire corrigée en hover/active, l'indicateur focus-visible, la
distinction disabled et l'absence de changement des liens éditoriaux et de
navigation. La PR #95 reste en brouillon jusqu'à réussite de cette matrice
Chromium réelle.
