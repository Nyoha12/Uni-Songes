# Fondation du pied de page public — 2026

## Objectif et périmètre

Cette modification fournit exactement un landmark de pied de page à chacun des
deux shells publics du thème Uni-Songes. Le pied de page reste statique : il ne
crée aucune route, aucun contenu Drupal, aucun texte juridique et aucune donnée
d’organisation.

Reprise du 7 septembre 2026 depuis le head publié
`65cbfbd347756cd73ccec202cf7da27de31e8c24`, worktree propre. Après fetch et
vérification de la ref GitHub, rebase sans conflit sur `origin/release/prod`
`9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c`. Cette base comprend notamment
#99/#100 et #103, fusionnée le 3 septembre au commit `36b023c`.

La nouvelle autorisation du 7 septembre confie ensuite le runtime exclusivement
à #94, après restauration et arrêt par le terminal 1. Le head testé est
`3cebbbf36ebd26ef5a5d0e04e26b1e0f06a6bfd5` ; la base distante n’a pas changé,
donc aucun nouveau rebase. La validation réelle ci-dessous démontre trois
blocages : repli sur sortie finalement vide, contraste des blocs configurés,
ordre visuel des messages de compte. La PR reste en brouillon. Cette reprise
ne modifie que ce compte rendu, pas les trois Twig. Aucun travail sur les alias,
#90, les worktrees/preuves #113 ou #82, aucun VPS ni service transactionnel externe.

## Audit du shell existant

### Modèle de défilement et footer

Avant cette modification, le shell normal suivait cette structure :

```text
main#main-content.layout
└── .container
    ├── page.highlighted?
    ├── page.help?
    └── #unisonges-scrollframe.scrollframe
        └── .scrollframe__inner
            └── page.content
footer.site-footer?                  hors scrollframe
└── page.footer
```

Le shell d’accueil s’arrêtait après `page.content` et n’avait aucun footer.
L’ancien `templates/includes/_footer.html.twig` existait comme fichier, mais
aucun template ne l’incluait. Sa présence ne constituait donc ni un chemin de
rendu ni une configuration active.

La cascade CSS verrouille `html` et `body` à la hauteur du viewport avec
`overflow: hidden`. Elle fixe `#unisonges-scrollframe`, lui donne une hauteur
contrainte, `overflow: auto` et un `z-index` de 2000. Le footer normal extérieur
restait dans le flux racine non défilable et à un niveau visuel inférieur. Le
contenu fixe du frame ne contribuait pas à la hauteur de son ancêtre ; le footer
pouvait donc se trouver derrière le frame ou hors viewport. Le focus clavier ne
pouvait pas faire défiler le frame vers ce footer, puisqu’il n’en était pas un
descendant.

BGFX reste autonome depuis #91/#98 : aucune dépendance à `scrollTop` ni listener
de scroll. Le footer dans `.scrollframe__inner` contribue à la plage de
défilement existante. La règle historique `overflow: auto` de cet inner n’est
pas accompagnée d’une hauteur contrainte ; #99 et #103 la remplacent par
`overflow: visible` sur leurs routes. Aucun nouveau scroller n’est ajouté.

Les règles de géométrie relues sont `.site-footer` et `.container`
(`css/styles.css:3,39`), le frame fixe et sa hauteur de viewport (`:1458–1473`),
son centrage/largeur/z-index et l’overflow de l’inner (`:1540–1554`).
`css/auth-account.css:43–65` borne la largeur/hauteur du frame des comptes et
définit l’inner flex. `editorial-home.css:2–12` garde le même frame sur
`body.section-accueil`, avec padding adaptatif et inner overflow-visible ; sa
grille interne gère le rail et les Articles. Les dimensions calculées et
l’absence de piège ont été contrôlées dans la passe réelle ci-dessous ; elles
ne règlent pas les trois blocages constatés.

### Région Drupal et comportement de Bootstrap Barrio

`system.theme.yml` désigne `unisonges_theme` comme thème public par défaut. Son
fichier `unisonges_theme.info.yml` déclare seulement les régions `header`,
`primary_menu`, `content` et `footer`. L’audit conservé du 2 septembre comptait
19 blocs synchronisés Uni-Songes, aucun dans `footer` ; ce total historique
précède #103. Son nouveau bloc est explicitement dans `content`, poids 0.
Lors de l’audit statique, la configuration active n’avait pas été interrogée.
Le snapshot local de cette passe ne contient aucun bloc Uni-Songes en footer ;
cela ne prouve toujours pas l’état de production.

Composer verrouille Bootstrap Barrio 5.5.20. Son template de page fournit son
propre footer et cinq régions `footer_*`, mais les deux templates de page du
sous-thème remplacent ce template. Barrio n’ajoute donc aucun footer autour de
leur sortie. Les blocs `bootstrap_barrio_powered` et `olivero_powered`
appartiennent à d’autres thèmes et ne peuplent pas la région Uni-Songes.

La classe `.site-footer` et la classe `.container` sont définies dans la feuille
du thème. L’hypothèse statique sur `d-flex`/`flex-column` est infirmée : la
présence de Bootstrap 5.3.8 dans Composer et du réglage `production` ne prouve
pas son chargement. Barrio ne lit ce réglage qu’avec le module
`bootstrap_library`, absent de la configuration fusionnée ; sinon il attend
`bootstrap_barrio_source`, également absent. Chromium calcule donc
`main { display: block }`. Aucun asset n’a été ajouté pour masquer cet écart.

### Landmarks, messages, comptes et navigation existants

La PR #81 a établi un unique `main#main-content` dans chaque shell, cible du
lien d’évitement hérité de Bootstrap Barrio. La PR #84 conserve un seul H1
sémantique. La PR #100 prévoit un seul bloc `system_messages_block` synchronisé du
thème dans la région `content`, au poids `-8`, et un seul wrapper en flux normal
`.unisonges-system-messages`. Les shells rendent `page.content` exactement une
fois dans `main`, ne rendent jamais `page.header`, et le footer ne contient ni
message, ni destination tardive, ni contournement JavaScript. Le chemin des
messages reste donc unique, dans le contenu principal, sans wrapper fixe ou
toast.

Les classes `main.d-flex.flex-column` de la première passe sont conservées,
mais sans effet flex réel. #99 définit le titre à `order: -30` et les messages
à `order: -20`, avec les poids de blocs source −7 et −8. La comparaison runtime
confirme que le nouveau main interrompt le flex de l’inner : les messages
précèdent maintenant le titre. C’est une régression de #94. Le formulaire,
ses actions et le compte restent dans `page.content`, le footer après `main`
et hors de tout formulaire. Aucun fichier de #99/#100 n’est modifié.

#103 est fusionnée. Son bloc `content/0`, ses deux panneaux `details`, sa
liste et son pager restent sous `page.content` dans `main`, avant le footer.
Les fixtures utilisent maintenant le template éditorial réellement fusionné.
Les gardes du helper #103 verrouillent les configurations titre/messages/contenu,
pas le texte des shells : elles restent intactes. Le helper fusionné a désormais
activé le bloc et son marqueur dans la base locale sauvegardée, sans import.

La source serveur du menu principal reste l’unique bloc
`system_menu_block:main`, rendu une fois par `page.primary_menu` dans le header.
Le drawer mobile vide reçoit ensuite seulement une copie JavaScript de sa liste
racine avec des IDs réécrits. Aucun fichier de header, de navigation ou de
drawer n’est modifié ici.

L’architecture de menu finale confirmée conserve cinq racines : Cours & Stages,
Concerts & Événements, Projets collectifs, À propos et Contact. Le footer
statique réutilise seulement les cinq destinations publiques explicitement
confirmées pour ce périmètre ; il n’essaie pas de reproduire tout l’arbre du
menu principal.

### Périmètre et preuves conservées

L’audit du 2 septembre avait contrôlé 77 entrées de fichiers des 15 autres PR,
sans chevauchement. Il est conservé comme preuve datée, sans nouvelle campagne
globale le 7 septembre. La garde actuelle compare le diff à la base vérifiée et
exige les quatre fichiers autorisés. Les sources fusionnées #99/#100/#103, les
styles, les dépendances et les autres worktrees ne sont pas modifiés.

## Structure et stratégie retenues

Les deux shells ont maintenant la même hiérarchie accessible :

```text
#unisonges-bgfx
└── #unisonges-bgfx-scroll
    └── #unisonges-bgfx-layer
site-header
#unisonges-scrollframe.scrollframe
└── .scrollframe__inner
    ├── main#main-content.d-flex.flex-column
    │   ├── page.highlighted? / page.help?  shell normal seulement
    │   └── page.content                    une fois
    └── footer.site-footer                  une fois
        └── .container
            ├── identité « Uni-Songes »
            └── page.footer ou navigation de repli
```

L’include `_footer.html.twig` possède le seul élément `<footer>`. Chaque shell
l’inclut exactement une fois après `main`, dans `.scrollframe__inner`. La
structure du header fixe, l’ID du scrollframe et les IDs des trois couches BGFX
restent inchangés.

La stratégie de région est volontairement exclusive et déterministe :

- `footer_region|render` est évalué une fois et son résultat conservé ;
- si ce résultat n’est pas vide après `trim`, il est affiché une fois et
  remplace la navigation de repli ;
- sinon, la navigation statique de repli est rendue ;
- l’identité concise « Uni-Songes » reste visible dans les deux cas.

Cette branche évite de rendre simultanément des liens de repli et un futur bloc
administré qui pourrait contenir les mêmes liens. Elle fournit néanmoins un
landmark et un contenu utile lorsque la région est vide. Le défaut corrigé le
7 septembre était le test booléen du tableau brut : des métadonnées `#cache`
ou des enfants `#access: false` suffisaient à masquer le repli. Le test porte
désormais sur la sortie, sans `striptags` ni `raw` : un contenu « 0 » ou non
textuel reste accepté. La simulation ne reproduit pas les wrappers vides et
placeholders : les tests Drupal démontrent que ce choix n’assure pas le repli
final dans ces cas. Les métadonnées sont conservées dans les probes réelles.

Les liens visibles dans le cas de repli sont exactement :

| Libellé | Destination |
| --- | --- |
| Cours & Stages | `/cours-et-stages` |
| Projets collectifs | `/ateliers` |
| À propos | `/a-propos` |
| Blog | `/blog` |
| Contact | `/contact` |

Les liens `/mentions-legales` et `/politique-confidentialite` sont
intentionnellement absents. Leur existence canonique Drupal et leur contenu
approuvé doivent faire l’objet d’une décision explicite du propriétaire de
contenu. Aucun texte ne doit être repris du site Cloudflare historique et aucun
lien ne devra être publié avant cette validation.

## Validation statique conservée, avant runtime

Les preuves de la session précédente (Twig 3/3, quatre fixtures chaîne,
unicité/ordre HTML, revues indépendantes) servent de base. La reprise réutilise
Node 24.20.0, Twig.js 3.0.0 et html-validate 9.7.1 depuis leurs caches existants,
sans installation ni dépendance ajoutée.

Nouvelles vérifications ciblées :

- contre-exemple avant correction : le tableau PHP `[]` est faux, mais
  `['#cache' => …]` et un enfant refusé sont vrais ; le vieux Twig retire le
  repli dans les deux derniers cas ;
- les quatre fixtures chaîne sont conservées ; huit formes de render arrays
  sont créées comme vrais tableaux PHP, transportées en JSON et rendues par un
  filtre factice limité à `#markup`, `#plain_text`, enfants et `#access: false` ;
- normal/accueil × ces dix cas : 20 documents HTML, un main portant les classes
  utilitaires, un contenu,
  un header, une source de navigation, un drawer, un chemin de messages, un
  footer frère suivant et un exemplaire de chaque ID BGFX ;
- un appel de `|render` par footer ; zéro ou un contenu configuré, jamais le
  repli en plus ; mêmes cinq alias, sans Ressources, Boutique ni liens légaux ;
- chaque fixture accueil rend le template fusionné #103 avec deux Articles,
  un pager et deux panneaux ; ils sont tous dans main et absents du footer ;
- trois templates modifiés compilés, 20/20 fixtures et assertions DOM réussies ;
  les dix pages normales n’ont aucun diagnostic HTML ; chaque accueil signale
  seulement `unique-landmark` sur `#mobile-drawer`, également reproduit avec le
  shell de base sans footer. Ce drawer `aside hidden` n’a pas de nom propre ;
  le validateur le compte avec le rail #103. Le header est hors périmètre :
  aucune correction ici, à examiner au runtime drawer fermé/ouvert. Tout autre
  diagnostic échoue ; les trois exceptions historiques restent inchangées
  (`role="main"`, `<img />`, espaces) ;
- UTF-8/NFC, diff exact de quatre fichiers, `git diff --check` et scan des
  ajouts pour secrets ; aucun CSS, JS, PHP, bibliothèque ou config modifié.

Les fonctions Drupal sont simulées : ce contrôle ne compile pas avec le moteur
PHP/Twig de Drupal et ne prouve ni cacheabilité, ni activation #103, ni wrappers
réels, ni géométrie calculée. La revue indépendante ciblée confirme l’intégration
structurelle #103 et conserve ces limites explicites, notamment l’ordre #99.

### Reproduction des fixtures statiques

Le harness intégral reste versionné dans ce document au commit
`3cebbbf36ebd26ef5a5d0e04e26b1e0f06a6bfd5` (`git show
3cebbbf:docs/functional/public-footer-foundation-2026.md`). Il utilise les caches
Twig.js et html-validate existants, sans installation. Ses 20 résultats restent
des preuves de simulation ; les contre-exemples Drupal ci-dessous les complètent.

## Revue clavier et landmarks

L’ordre source reste : lien d’évitement hérité, header et contrôles de
navigation, contenu principal, puis navigation de footer. Activer le lien
d’évitement cible toujours l’unique `main#main-content`, désormais descendant
du vrai conteneur défilant. Les liens du footer suivent tout le contenu
principal ; leur prise de focus demande au même scrollframe de les rendre
visibles. Aucun `tabindex`, rôle ARIA redondant, footer fixe ou JavaScript n’est
ajouté.

Le `<footer>` est frère de `main`, et non son descendant. Il représente donc le
pied de page du site et bénéficie du rôle implicite `contentinfo`. La navigation
de repli possède un nom accessible propre. Les liens sont dans une liste
sémantique verticale et peuvent revenir à la ligne sans largeur imposée.

## Validation réelle du 7 septembre 2026

### Préparation isolée et preuves

Checkout servant initial `/workspaces/Uni-Songes`, branche `release/prod`,
propre à `9ef3d4a`. DDEV et router arrêtés, aucun autre test actif. Snapshot frais
`pr94-footer-before-runtime-20260907`, export SQL, archive des fichiers publics
et empreintes pris avant les fixtures. État initial : 314 configurations,
59 modules, thèmes Olivero/Claro, front `/node`, maintenance désactivée,
7 comptes locaux, 0 node, 16 alias, 4 produits locaux, 0 commande/soumission.
Aucun de ces contenus préexistants n’a été supprimé pour préparer les tests.

Les API Drupal ont préparé le thème, ses quatre blocs du shell et les quatre
hubs exacts nécessaires aux helpers fusionnés. Leurs gardes sont restées
intactes : Forum/Blog puis accueil #103, en maintenance, dry-run puis apply
avec sauvegarde et, pour #103, token exact. Les extensions requises par leur
inventaire ont été activées temporairement ; aucun import de configuration.
L’ordre initial d’activation de Redirect/Sitemap ajoutait deux extra-fields au
formulaire Forum : cet essai a été annulé par la transaction du helper, puis
la préparation ordonnée a réussi. Aucun fichier de ces fonctionnalités modifié.

Un bloc de probe temporaire, non suivi, a fourni les états de région ; six
Basic pages et douze Articles ont servi aux parcours. Les deux alias de fixture
Contact/réservation pointaient directement vers les routes Drupal existantes,
sans modifier les alias initiaux. Aucun paiement, soumission, email, accès
Google, Mailpit ou VPS. Le module et les données de probe ne font pas partie
de la PR.

Preuves locales durables :
`/workspaces/Uni-Songes/.git/pr94-runtime-20260907.HD21qnVF/`.
Les scripts ciblés `region-probe.php`, `block-cache-probe.php`, `browser.cjs`,
les JSON/HTML, captures et logs des helpers y sont conservés. Ils réutilisent
Drupal 11.3.3, PHP 8.3.31, Twig PHP 3.22.2, Chromium 140.0.7339.16 et les caches
Playwright 1.62.0/html-validate 9.7.1, sans installation de dépendance.
Les dumps et états d’authentification restent locaux, jamais publiés dans Git.

### Région réelle, cache et BigPipe

Trois Twig compilés par le moteur PHP réel ; 16 probes de région avec/sans
wrapper ; 54 observations navigateur (9 états × 3 rôles × froid/chaud),
complétées par les cas normaux, région sans bloc placé et image corrigée.

| État réel | Résultat final |
| --- | --- |
| Aucun bloc placé, bloc vide, bloc refusé | Identité et cinq liens exacts ; pass |
| Métadonnées seules sans wrapper | Repli et métadonnées conservés ; pass |
| Bloc réel à métadonnées seules | Wrapper vide, repli absent ; **bloquant** |
| Texte visible ou « 0 » | Sortie une fois, aucun repli ; contraste insuffisant |
| Image enfant du bloc | Une image chargée, 40 × 40 px, aucun repli ; pass |
| Lazy visible | Sortie une fois, aucun repli, attachments conservés ; pass rendu |
| Lazy vide, ou lazy privé refusé à l’anonyme | Sortie finalement vide, repli absent ; **bloquant** |

Les 21 probes du vrai BlockViewBuilder vérifient un maximum d’un appel de
build/lazy par rendu, ainsi que les tags de bloc/probe, contextes de rôle/query
et de compte pour le lazy privé, et les bibliothèques/settings attachés.
Les traces HTTP montrent les caches HIT/MISS et la stabilité des résultats.
Aucun texte membre/admin n’apparaît chez l’anonyme ; chaque rôle reçoit sa
sortie attendue. Le flux BigPipe membre contient start/stop et des substitutions
dans les deux cas lazy visible/vide ; aucun placeholder ne subsiste dans le DOM,
aucune exception de rendu anticipé n’est observée sur ces requêtes.

`trim()` examine ici un wrapper ou placeholder, pas la visibilité finale.
Retirer des balises ne serait pas une correction sûre : cela perdrait les
images et ne résoudrait pas le rendu différé. La décision de repli exige une
politique de rendu autorisée au-delà des seuls Twig actuels.

Le premier probe d’image plaçait ses attributs à la racine du bloc, où Drupal
les transférait au wrapper : il ne prouvait pas une image visible. Le probe
corrigé utilise un enfant image ; six passages froid/chaud sur trois rôles
confirment une seule image chargée. Les premiers 404 Contact et 403 compte
provenaient respectivement d’une chaîne d’alias de fixture et d’un lien de
connexion à usage unique réutilisé ; les reprises ciblées retournent 200,
avec UID 2 et surface propriétaire vérifiés. Ces essais restent dans les logs.

### Navigateur et comparaison à la base

Parcours : accueil sans Article puis 12 Articles (10 + pager vers les 2
suivants), Basic courte/longue, Blog, Forum, Contact réel, réservation, panier
vide, connexion, inscription, réinitialisation et compte propriétaire.
Footer toujours frère après main, hors formulaire ; un contenu, un header,
une source de menu, un drawer, IDs BGFX/scrollframe et cible skip conservés.
Le message de test apparaît une fois dans main, jamais dans le footer.

Les comportements accueil/longue page/connexion ont été regroupés aux tailles
1440×900, 768×1024, 390×844, 320×740 et 640×450. Ce dernier viewport contrôle
le reflow équivalent à 200 % d’une fenêtre 1280×900 ; ce n’est pas une commande
de zoom natif de l’interface du navigateur. Les 25 observations géométriques
de branche passent : footer entièrement atteignable en fin de scrollframe,
pas d’overflow horizontal ni de scroller imbriqué. Header/BGFX restent fixes.
Les panneaux fermés/ouverts restent dans main. Le drawer se ferme avec Échap
après sa transition et rend le focus au bouton. Les cinq liens du footer sont
parcourus avec Tab et restent visibles ; le skip mène au contenu et le Tab
suivant au premier champ de connexion. Aucun formulaire n’a été utilisé pour
effectuer une réservation, un paiement ou un envoi de message.

Deux problèmes visuels restent :

- Barrio donne à `.site-footer .content` du blanc à 65 %, et aux liens du blanc
  à 80 %, sur le fond clair du frame (`rgb(255,250,240)` + noir à 2 % du footer).
  Contrastes calculés depuis les styles réels : environ **1,06:1 / 1,07:1**.
  Les captures confirment un contenu presque invisible. Ces règles héritées
  rendent le cas configuré non acceptable ; aucun CSS correctif ajouté.
- Sur les cinq tailles, la base affiche **titre → messages → formulaire**,
  la branche **messages → titre → formulaire**. Le nouvel ancêtre main non
  flex introduit cette régression #99, malgré l’unique chemin inline #100.
  Reprendre la classe `scrollframe__inner` sur main changerait la structure et
  le padding du footer ; ajouter Bootstrap changerait les assets. Aucune de
  ces décisions n’est introduite silencieusement ici.

HTML et arbre accessible comparés sur les mêmes fixtures base/branche :
`unique-landmark` sur le drawer sans nom est strictement hérité. Fermé, il est
absent de l’arbre accessible ; ouvert, son complément non nommé apparaît
dans les deux versions. Le rail #103 reste nommé. Les diagnostics sur styles
inline BGFX et attributs `hidden` sont également identiques ; aucune exclusion
générale n’a masqué ces résultats. La branche ajoute l’unique `contentinfo`.
Les requêtes finales ne produisent aucun HTTP 5xx ni erreur console nouvelle.
Les dépréciations ECA/Entity pendant la préparation sont distinguées des tests
du footer ; l’unique entrée PHP du watchdog est antérieure à cette reprise
et vient d’un ancien `php:eval` Drush, pas des pages testées.

### Décision et point de reprise

La revue indépendante confirme les trois blocages et ne trouve aucun correctif
Twig sûr dans le périmètre imposé. PR #94 reste **brouillon**, sans modification
des trois templates testés et sans merge. Avant une nouvelle passe, il faut
autoriser une solution ciblée pour la décision de rendu différé, le contraste
du bloc et le flex du main, ou décider explicitement d’un autre contrat de
structure. Rejouer ensuite ces cellules, leurs caches et la comparaison auth ;
les preuves sans changement restent réutilisables. Le zoom navigateur natif
reste distinct du contrôle de reflow équivalent effectué ici.

### Restauration et libération

Le snapshot frais a été restauré, puis le checkout servant remis sur sa branche
initiale `release/prod` à `9ef3d4a`, propre. Les six pages, douze Articles,
huit alias et autres données de probe ont disparu avec cette restauration ;
les sept comptes, seize alias et quatre produits locaux préexistants sont
inchangés. Les configurations, thèmes, front `/node`, maintenance et empreintes
d’entités correspondent exactement au relevé initial (`cmp` des deux JSON).

- Dump SQL normalisé, avant/après restauration :
  `e753afc47351ef4869fd87184b5df9602fa40650046e16b53836834cb4b89d7a`.
- 314 configurations actives, empreinte canonique :
  `e96a6b849b5e15c6e16fde5b6494a9e57fe9f7161dd8398c819963ddfdfc2127`.
- Archives normalisées des fichiers publics, identiques avant/après :
  `8ddca913cb61f995cf210bcbb878ff17a7af2c4c69a53eef9677568a514b47e1`.

Le module temporaire et les seuls fichiers générés pendant les tests ont été
déplacés dans le dossier de preuves privé, hors de l’arbre servi ; ils restent
récupérables. Les deux fichiers publics initiaux et leurs dossiers sont
préservés. Aucun retrait de données d’un autre travail. DDEV et router sont
arrêtés, Chromium fermé : environnement explicitement libéré, sans lancement
automatique de #90 ou d’une autre validation.

Gardes finales : diff exact des quatre fichiers autorisés, aucun changement des
trois Twig dans cette reprise, UTF-8/NFC, `git diff --check`, scan des ajouts
pour secrets ; aucun recouvrement avec les fichiers des 21 autres PR ouvertes.
