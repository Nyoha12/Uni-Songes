# Composants des hubs publics — 2026

## Statut et périmètre

Cette phase est uniquement statique. Elle converge les templates des pages
Concerts, Orchestre et D’Jam vers les composants visuels 2026 déjà présents
dans le thème, sans modifier les routes, les entités de contenu, le menu, le
CSS global ni la logique métier.

Base vérifiée : `origin/release/prod` au commit
`3e53bc5b3d1b3ded9a207bc2e16ec48aa84b9bdf`.

La PR reste en brouillon tant que les ressources runtime appartiennent
exclusivement à la PR #94. Cette intervention n’utilise ni DDEV, ni Docker, ni
Drush, ni navigateur, ni serveur local, ni Mailpit, ni VPS.

## Audit des composants et décision d’includes

Le bloc CSS canonique complet se trouve dans `styles.css`, du marqueur
`UNISONGES_OFFER_CARD_COMPONENTS` à son marqueur de fin. Il fournit :

- `unisonges-page-intro` ;
- `unisonges-card-grid`, avec trois colonnes au-dessus de 900 px, deux colonnes
  jusqu’à 900 px, puis une colonne jusqu’à 640 px ;
- `unisonges-offer-card` et ses éléments `__title`, `__text`, `__meta` et
  `__cta` ;
- `unisonges-detail-section` ;
- les compléments de lisibilité applicables à `node-body` et aux composants.

Les classes historiques `card-grid`, `card`, `panel` et `actions-row` n’ont pas
de règle générale active dans cette feuille. La classe `btn` ne fournit qu’une
base générique. Aucun changement CSS ou de bibliothèque n’est effectué dans ce
périmètre.

Inventaire exhaustif des consommateurs Twig avant modification :

| Include | Consommateurs |
| --- | --- |
| `_card-grid.html.twig` | `templates/content/node--6.html.twig` uniquement |
| `_actions-row.html.twig` | `templates/content/node--7.html.twig`, `node--9.html.twig` et `node--10.html.twig` |

`_card-grid.html.twig` est donc modernisé sans changer son contrat : les
paramètres `cards`, `title`, `text`, `url` et `link_label` sont conservés. Les
classes canoniques sont ajoutées, et `card-grid`/`card` restent des classes de
compatibilité. Elles ne créent aucun contenu ni contrôle supplémentaire.

Modifier `_actions-row.html.twig` aurait aussi restylé Contact, hors périmètre.
Cet include reste strictement inchangé et continue à servir uniquement
`node--7.html.twig`. Les nœuds 9 et 10 utilisent le nouvel include étroit
`_public-hub-actions.html.twig`, qui conserve les données `actions`, accepte un
nom accessible facultatif et n’émet aucun `nav` si aucune action complète
n’existe.

## Stratégie de balisage

### Concerts — nœud 6

- Le hero existant reste inclus exactement une fois avec `title: label`; il
  demeure l’unique source de H1 du template.
- Les deux cartes deviennent des `article.unisonges-offer-card` dans un
  `div.unisonges-card-grid`.
- Chaque titre de carte est un H2. Le texte facultatif et le lien d’action
  reçoivent respectivement `unisonges-offer-card__text` et
  `unisonges-offer-card__cta`.
- Le corps éditorial est rendu une fois et n’est placé dans un
  `div.node-body.unisonges-detail-section` que si son résultat intermédiaire
  contient du texte, un média ou un placeholder Drupal à préserver.

Le CSS existant conserve trois pistes sur grand écran. Avec les deux cartes
actuelles, la troisième piste reste libre. Cette limite visuelle est documentée
pour le contrôle runtime ; elle ne justifie pas une modification du CSS global
dans cette PR.

Le défaut préexistant du CTA sous 640 px appartient exclusivement à la PR #110,
au head `5628a099aec611f21124001fcd37ef4d7556db48`. Cette PR conserve les classes
canoniques attendues sans ajouter de CSS ni de wrapper compensatoire ; le rendu
combiné reste à vérifier avec #110.

### Orchestre — nœud 9

- Le hero, `label`, l’introduction et le corps existants sont conservés.
- Le corps utilise le même panneau éditorial conditionnel.
- Les deux actions sont des liens directs `unisonges-offer-card__cta` dans un
  `nav.unisonges-detail-section` nommé par le titre courant de la page.

### D’Jam — nœud 10

- Le hero, `label`, l’introduction et le corps existants sont conservés.
- Le corps utilise le même panneau éditorial conditionnel.
- Les deux actions utilisent la même structure accessible que l’Orchestre.

Un `div` est utilisé pour le corps éditorial, car son contenu dynamique ne
garantit pas la présence d’un titre qui nommerait une `section`. Le groupe de
liens est un `nav` explicitement nommé. Aucun H1 supplémentaire n’est ajouté.

## Rendu conservateur du corps

Chaque template appelle `content.body|render` une seule fois, stocke ce résultat
et ne l’affiche qu’une fois. Le test intermédiaire supprime les balises et les
espaces insécables usuels, mais conserve explicitement les médias et
`<drupal-render-placeholder>` afin de ne pas écarter un contenu utile ou un lazy
builder. Il supprime le panneau pour les cas statiquement simples : champ
absent, chaîne vide, `<p><br></p>`, commentaires seuls ou espaces insécables.

Ce branchement est volontairement conservateur. Un wrapper ou un placeholder
ne prouve pas la sortie finale : un lazy builder conservé peut encore se
résoudre en contenu vide et un élément média sans ressource utile peut aussi
laisser un panneau visuellement vide. La PR ne tente ni de parser le HTML final,
ni de résoudre de force les lazy builders. De même, la présence du filtre
`render` et l’absence de second rendu sont établies par le source, mais une
simulation Twig isolée ne démontre ni le bubbling complet des métadonnées de
cache, ni les attachements, ni le résultat BigPipe. Ces points restent à
vérifier dans Drupal sous la propriété runtime de la PR #94.

## Routes et libellés conservés

| Page | Destination | Libellé public |
| --- | --- | --- |
| Concerts | `/contact` | `Contacter l’association` |
| Concerts | `/djam` | `Voir les jams` |
| Orchestre | `/contact` | `Rejoindre le collectif` |
| Orchestre | `/concerts` | `Voir les concerts` |
| D’Jam | `/concerts` | `Voir les concerts` |
| D’Jam | `/contact` | `Participer à une prochaine jam` |

Les textes existants des héros et des cartes sont inchangés. Aucun artiste,
partenaire, événement, date, horaire, lieu, prix, disponibilité, billetterie ou
règle de participation n’est ajouté.

## Validation statique

Les preuves existantes ont été relues sans réinstaller de dépendance ni créer
un nouveau parseur. Le harnais éphémère antérieur utilisait Twig `3.22.2`,
version verrouillée par `composer.lock`, avec un filtre `render` instrumenté.
Il s’agit de fixtures Twig isolées : elles vérifient le branchement et le markup
produit pour des chaînes déterministes, pas le rendu Drupal final.

Résultats déterministes :

| Contrôle | Résultat |
| --- | --- |
| Parse, compilation et chargement de chaque Twig modifié | 5/5 PASS |
| Fixtures de cartes : présentes, absentes, variable absente, texte absent, URL absente, cartes invalides | 6/6 PASS |
| Fixtures d’actions : présentes, absentes, variable absente, actions invalides, nom accessible absent | 5/5 PASS |
| Branchement simulé des trois corps : texte, média seul, placeholder Drupal, vide, balises sans texte, entités d’espace insécable, espace insécable UTF-8, champ absent, `content` absent | 27/27 PASS |
| Structure des fragments simulés | 38/38 PASS |

La simulation et l’audit de source établissent :

- aucune grille ou navigation pour les fixtures sans carte/action complète ;
- aucun panneau pour les fixtures simples absentes, vides, `<p><br></p>` ou
  composées uniquement d’espaces insécables ; texte, média et placeholder sont
  conservés une fois dans leurs fixtures respectives ;
- une seule occurrence du marqueur de corps et un seul appel au filtre
  `render` dans chaque template ;
- un include hero exact par template, aucun H1 littéral ajouté, et le retrait
  versionné des suggestions NID 6–10 hors mode `full` ;
- les H2 non vides après la source H1 du hero dans le markup simulé ;
- les six destinations exactes, sans lien externe, protocole relatif, nœud
  numérique ou produit numérique ;
- deux cartes ou deux actions seulement selon la page, sans duplication due
  aux classes de compatibilité ;
- le maintien exact des textes publics attendus ;
- l’UTF-8 strict et la normalisation Unicode NFC de tous les fichiers changés.

Ces résultats ne prouvent pas le DOM Drupal final. Un corps administré peut
contenir son propre H1, un placeholder peut se résoudre vide, et la
cacheabilité, les attachements et BigPipe nécessitent un rendu réel. Le source
ne montre aucune double évaluation ni suppression explicite de métadonnées,
mais ces propriétés restent dans la matrice runtime.

Les gardes finales comprennent aussi `git diff --check`, une liste exacte des
fichiers autorisés, une garde des fichiers interdits, l’inventaire des
consommateurs, le chevauchement des fichiers de toutes les PR ouvertes et un
scan de secrets/identifiants sur les lignes ajoutées.

## Audit d’intégration après rebase

Avant le rebase, le head #92 était
`28b13c0046062bd2b7193ff003e79fe0d4168df0`, son merge-base
`8cc82f9af6899aedc14490931c415293d0bdf0cb` et la base avait avancé à
`3e53bc5b3d1b3ded9a207bc2e16ec48aa84b9bdf`. GitHub avait signalé la PR non
fusionnable, mais son état relu était ensuite `CLEAN/MERGEABLE`. La simulation
`git merge-tree` a réussi sans conflit, l’intersection des chemins modifiés des
deux côtés était vide et le rebase réel du commit unique a réussi sans choix
`ours`/`theirs`. Aucun fichier responsable d’un conflit de contenu n’existe : le
signal était transitoire ou périmé pendant l’avancement de la base.

Les contrats voisins ont été lus à leurs heads GitHub identifiés, sans lire
leurs worktrees ni reprendre leurs patches :

- **PR #89**, head `f625bb2fbe58f16d8aca2f263771c01a3bb1c4a5` : ses quatre
  fichiers View, bloc, script et documentation ne chevauchent pas #92. Le
  contenu principal au poids `-3` précède son bloc `/concerts` au poids `50`.
  Le nœud 6 n’ajoute ni View, ni liste, ni titre dynamique. Une duplication
  provenant du corps Drupal administré reste à contrôler réellement.
- **PR #94**, head `3352898c0dc762dc8802519d63b11fc1fe32ca23` : les shells
  ferment `main` avant leur footer frère ; aucun footer n’entre dans ces
  templates. Son contrat confirme aussi qu’un wrapper ou placeholder ne permet
  pas de conclure sur la sortie Drupal finale.
- **PR #110**, head `5628a099aec611f21124001fcd37ef4d7556db48` : son changement
  de code est limité au CSS de `unisonges-offer-card__cta`. #92 garde les
  ancres canoniques attendues, n’utilise pas `btn--cta`, n’ajoute aucun CSS et
  ne compense pas le défaut par du markup artificiel.
- **PR #98 — mouvement BGFX** : sa fusion `8cc82f9` appartient à la base. Le
  conteneur BGFX demeure fixé au viewport et son mouvement autonome de 44
  secondes reste découplé du scroll. Aucun composant modifié par la PR #92 ne
  référence BGFX, son JavaScript, le scrollframe ou un mouvement piloté par le
  défilement.
- **PR #99 et PR #100 — compte et messages** : elles appartiennent à la base.
  Les exclusions de titre issues de la PR #84 laissent le hero comme seul H1
  structurel versionné dans chacun des trois templates ; ceux-ci n’émettent
  aucun message. Le bloc de messages versionné reste dans la région `content`,
  au poids `-8`, avant le bloc de contenu au poids `-3`. La PR #92 ne touche ni
  `unisonges_theme.theme`, ni `unisonges_theme.libraries.yml`, ni
  `auth-account.css`, ni les fichiers de messages ou de page-title.
- **PR #103 — Blog et accueil éditorial** : elle appartient désormais à la base
  et reste indépendante des six fichiers de #92.

Selon l’instruction de coordination actuelle, distincte des textes historiques
de ces PR, Terminal 3 / #94 possède seul DDEV et le checkout servant.

La garde GitHub complète compare aussi les six chemins avec toutes les autres
PR ouvertes contre `release/prod` et exclut uniquement la PR #92 elle-même. Au
7 septembre 2026, aucune intersection de nom de fichier n’est présente.

## Matrice runtime différée

Tous les points suivants restent à regrouper sous la propriété runtime de #94,
notamment avec les heads contrôlés de #89 et #110 :

- `/concerts` avec corps vide puis rempli ;
- `/concerts` avec la View des événements à venir vide puis remplie ;
- `/concerts` anonyme chauffé sans cookie, franchissement d’une fin sans
  mutation ni purge, contrôle de `X-Drupal-Cache` et retrait du Concert expiré ;
- `/djam` avec corps vide puis rempli ;
- `/orchestre-des-reveurs` avec corps vide puis rempli ;
- desktop, tablette et mobile ;
- reflow à 100 %, 150 % et 200 % ;
- hiérarchie des titres avec la PR #84 désormais fusionnée ;
- compte et unique chemin de messages après les PR #81, #99 et #100 fusionnées ;
- en-tête accentué de la PR #83 fusionnée ;
- View Concerts après la PR #89 ;
- footer après la PR #94 ;
- dimensions, retour à la ligne et absence de débordement des CTA avec #110 ;
- contraste et focus comme contrôles transversaux ;
- indépendance des fichiers Blog/accueil après la PR #103 ;
- accès clavier et états de focus ;
- destinations de tous les liens ;
- grilles à une, deux et trois colonnes lorsque le viewport le permet ;
- résolution finale des placeholders/lazy builders, panneau finalement vide,
  cacheabilité, attachements et BigPipe ;
- absence de débordement horizontal ;
- absence de duplication des cartes, actions et corps ;
- BGFX fusionné par la PR #98 toujours visible, fixé au viewport et indépendant
  du défilement du contenu ;
- absence d’avertissement PHP ;
- absence d’erreur dans la console du navigateur.

Cette matrice différée est la raison du maintien de la PR en brouillon.
