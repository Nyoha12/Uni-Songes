# Composants des hubs publics — 2026

## Statut et périmètre

Cette phase est uniquement statique. Elle converge les templates des pages
Concerts, Orchestre et D’Jam vers les composants visuels 2026 déjà présents
dans le thème, sans modifier les routes, les entités de contenu, le menu, le
CSS global ni la logique métier.

Base vérifiée : `origin/release/prod` au commit
`8cc82f9af6899aedc14490931c415293d0bdf0cb`, fusion de la PR #98.

La PR reste en brouillon tant que les ressources runtime appartiennent
exclusivement à la PR #95. Cette intervention n’utilise ni DDEV, ni Docker, ni
Drush, ni Chromium, ni Playwright, ni Mailpit, ni navigateur, ni VPS.

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
  `div.node-body.unisonges-detail-section` que si son contenu rendu est
  réellement significatif.

Le CSS existant conserve trois pistes sur grand écran. Avec les deux cartes
actuelles, la troisième piste reste libre. Cette limite visuelle est documentée
pour le contrôle runtime ; elle ne justifie pas une modification du CSS global
dans cette PR.

Une seconde limite préexistante du composant canonique doit être contrôlée au
runtime : sous 640 px, `unisonges-offer-card__cta` reçoit `width: 100%` tout en
gardant son padding horizontal et sa bordure, sans `box-sizing: border-box`
applicable. Un lien CTA peut donc dépasser la boîte de contenu de son parent,
même si l’overflow global masque un éventuel débordement du viewport. La plus
petite correction serait un suivi CSS limité à ce sélecteur ; elle est différée
afin de préserver ici la propriété du CSS et le périmètre exact de six fichiers.

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

## Rendu sûr du corps

Chaque template évalue `content.body|render` une seule fois. Le résultat est
stocké, débarrassé de ses balises pour le test, normalisé pour les espaces
insécables HTML usuels, puis affiché une seule fois si du texte significatif
ou un élément média éditorial subsiste. Cette approche préserve notamment un
corps composé uniquement d’une image et les placeholders Drupal destinés à un
rendu différé, tout en évitant les panneaux vides pour un champ absent, une
chaîne vide, `<p><br></p>`, des commentaires seuls ou des espaces insécables.

Le filtre Drupal `render` reste le point de rendu du render array : les
métadonnées de cache peuvent ainsi remonter dans le contexte de rendu Twig dans
la limite de ce que permet une capture de template. La cacheabilité complète
reste à confirmer dans le runtime Drupal lorsque la PR #95 libérera ces
ressources ; aucune validation runtime n’est revendiquée ici.

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

Un harnais éphémère utilise exactement Twig `3.22.2`, version verrouillée par
`composer.lock`, avec auto-échappement HTML et un filtre `render` instrumenté.
Les sorties sont ensuite contrôlées par `HTML::Parser` et `HTML::TreeBuilder`,
sans navigateur.

Résultats déterministes :

| Contrôle | Résultat |
| --- | --- |
| Parse, compilation et chargement de chaque Twig modifié | 5/5 PASS |
| Fixtures de cartes : présentes, absentes, variable absente, texte absent, URL absente, cartes invalides | 6/6 PASS |
| Fixtures d’actions : présentes, absentes, variable absente, actions invalides, nom accessible absent | 5/5 PASS |
| Corps des trois pages : texte, média seul, placeholder Drupal, vide, balises sans texte, entités d’espace insécable, espace insécable UTF-8, champ absent, `content` absent | 27/27 PASS |
| Fragments HTML équilibrés et contrôles structurels | 38/38 PASS |

Le harnais prouve également :

- aucune grille de cartes, navigation d’actions ou panneau de corps vide ;
- une seule occurrence du marqueur de corps et un seul appel au filtre
  `render` par rendu de page ;
- un include hero exact et un H1 rendu par page, sans H1 littéral ajouté ;
- les H2 de cartes non vides et placés après le H1 ;
- les six destinations exactes, sans lien externe, protocole relatif, nœud
  numérique ou produit numérique ;
- deux cartes ou deux actions seulement selon la page, sans duplication due
  aux classes de compatibilité ;
- le maintien exact des textes publics attendus ;
- l’UTF-8 strict et la normalisation Unicode NFC de tous les fichiers changés.

Les gardes finales comprennent aussi `git diff --check`, une liste exacte des
fichiers autorisés, une garde des fichiers interdits, l’inventaire des
consommateurs, le chevauchement des fichiers de toutes les PR ouvertes et un
scan de secrets/identifiants sur les lignes ajoutées.

## Audit d’intégration après rebase

La branche a été rebasée sans conflit sur la tête de `origin/release/prod`, qui
contient désormais les PR #84, #91, #93, #98, #99 et #100. Les frontières
suivantes ont été relues statiquement :

- **PR #89 — View Concerts dynamique** : ses quatre fichiers View, bloc, script
  et documentation ne chevauchent pas cette PR. Le bloc de contenu principal a
  le poids `-3` et contient intégralement le template du nœud 6 ; le bloc View
  Concerts garde le poids `50`. Le hero, les cartes statiques et le corps
  éditorial précèdent donc le bloc dynamique. Le code du nœud 6 n’inclut ni
  View, ni liste d’événements codée en dur, ni titre de View : #92 conserve un
  hero H1 et #89 fournit séparément le libellé visible de son bloc dynamique,
  sans duplication structurelle entre les deux PR. Le corps éditorial actif
  restant arbitraire, l’absence de titre ou liste redondante dans celui-ci doit
  encore être confirmée au runtime.
- **PR #94 — pied de page futur** : son footer est ajouté par les shells
  `page.html.twig` et `page--front.html.twig` après la fermeture de `main`, dans
  le flux complet du scrollframe. Aucun markup de footer, include de footer ou
  changement de shell n’entre dans les templates de nœud de la PR #92.
- **PR #95 — contraste et ressources runtime** : la PR #92 n’ajoute et ne
  modifie aucune classe `btn--cta`. Ses liens utilisent
  `unisonges-offer-card__cta`, avec `btn` comme seule classe de compatibilité
  pour les actions. Ce sélecteur de carte reste indépendant des changements
  `btn--cta` et Webform de la PR #95.
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
- **PR #103 — Blog et accueil éditorial** : ses fichiers de documentation,
  configuration, script, module, CSS et template propres au Blog/à l’accueil ne
  chevauchent aucun des six fichiers de cette PR. Son bloc est limité à
  `/accueil` et il ne partage avec #92 ni shell, ni CSS, ni bibliothèque.

La garde GitHub complète compare aussi les six chemins avec toutes les autres
PR ouvertes contre `release/prod` et exclut uniquement la PR #92 elle-même. Au
2 septembre 2026, aucune intersection de nom de fichier n’est présente.

## Matrice runtime différée

Tous les points suivants restent à exécuter par le propriétaire runtime après
que la PR #95 libérera ces ressources :

- `/concerts` avec corps vide puis rempli ;
- `/concerts` avec la View des événements à venir vide puis remplie ;
- `/djam` avec corps vide puis rempli ;
- `/orchestre-des-reveurs` avec corps vide puis rempli ;
- desktop, tablette et mobile ;
- reflow à 100 %, 150 % et 200 % ;
- hiérarchie des titres avec la PR #84 désormais fusionnée ;
- compte et unique chemin de messages après les PR #81, #99 et #100 fusionnées ;
- en-tête accentué de la PR #83 fusionnée ;
- View Concerts après la PR #89 ;
- footer après la PR #94 ;
- contraste des interactions après la PR #95 ;
- indépendance des fichiers Blog/accueil après la PR #103 ;
- accès clavier et états de focus ;
- destinations de tous les liens ;
- grilles à une, deux et trois colonnes lorsque le viewport le permet ;
- largeur réelle des CTA sous 640 px, notamment le débordement potentiel dû au
  modèle de boîte `content-box` documenté ci-dessus ;
- absence de débordement horizontal ;
- absence de duplication des cartes, actions et corps ;
- BGFX fusionné par la PR #98 toujours visible, fixé au viewport et indépendant
  du défilement du contenu ;
- absence d’avertissement PHP ;
- absence d’erreur dans la console du navigateur.

Cette matrice différée est la raison du maintien de la PR en brouillon.
