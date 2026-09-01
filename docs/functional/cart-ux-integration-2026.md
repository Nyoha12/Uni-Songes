# Intégration UX du panier Commerce — 2026

Statut : implémentation statique terminée et rebasée sur `origin/release/prod`
`5b8e80c2e2ac266978ba2be0b8eee2c56a04605f`, qui contient notamment les PR #84,
#93, #100 puis #99. La PR #98 détient exclusivement les ressources runtime :
aucun DDEV, Docker, Drush, Chromium ou Mailpit n'est utilisé ici. La validation
Drupal/HTTP/navigateur reste donc différée et cette PR doit rester en brouillon.

## Résultat et périmètre

`/cart` conserve son titre de page « Panier » et son comportement Commerce. Le
seul libellé public anglais de la table passe de `Item` à « Article ». Quand le
panier est vide, la page explique où commence une réservation de cours et
propose trois chemins de découverte explicites :

- « Choisir un cours et un créneau » vers `/reservation-cours` ;
- « Découvrir les stages » vers `/stages` ;
- « Découvrir les concerts » vers `/concerts`.

Cette intégration ne crée aucune URL publique. Elle ne modifie ni produit, ni
variation, ni prix, ni panier actif, ni commande, ni ligne de commande, ni
client, ni livraison ou expédition, ni workflow, ni checkout, ni paiement. Elle
n'affiche donc aucun prix ou délai de livraison fictif et ne prétend pas qu'une
livraison existe.

## Intégration de la base finale

Le rebase conserve le commit fonctionnel de cette PR comme unique commit au-dessus
de la dernière base. Les contrats fusionnés qui touchent le shell public sont
relus ensemble :

| Merge | Contrat conservé sur `/cart` |
| --- | --- |
| PR #84 | Le bloc Core de titre reste la source du seul H1 « Panier » ; `/cart` n'appartient pas aux dix chemins hero exclus. |
| PR #93 | `unisonges-layout` reste l'unique propriétaire de ses quatre CSS et cinq JavaScript, avec des attaches dédupliquées. |
| PR #100 | Le bloc de messages reste dans `content/-8` et la suggestion inline fournit l'unique destination `[data-drupal-messages]` dans `main`. |
| PR #99 | La bibliothèque `auth-account` reste limitée aux routes auth et au compte propriétaire ; `/cart` est hors de cette allowlist. |

### Scope auth/compte et messages

`_unisonges_theme_auth_account_scope()` autorise exactement `user.login`,
`user.register`, `user.pass`, `user.reset.form`, puis les seules routes
`entity.user.canonical` et `entity.user.edit_form` lorsque le compte consulté
appartient à l'utilisateur courant. La route de `/cart` ne correspond à aucune
de ces branches : elle ne reçoit ni classe `auth-account-page`, ni bibliothèque
`unisonges_theme/auth-account`, ni gestionnaire de fermeture propre à ce scope.

Le vrai panier vide reste un contenu de page Commerce, pas un message système :
son override ne porte ni `data-drupal-messages`, ni rôle d'alerte, et commence par
un H2 sous le titre de page. `CartController::cartPage()` choisit ce hook lorsque
le panier ne contient aucun article sans ajouter un second message au messenger.
Un éventuel message Drupal préexistant continue donc à emprunter une seule fois
le chemin fusionné par la PR #100, distinct du texte explicatif du panier vide.

### H1 et graphe final des bibliothèques

La condition négative du bloc de titre fusionnée par la PR #84 ne contient pas
`/cart`. Le bloc global produit donc le seul H1 ; le template vide fournit un H2
et la View remplie conserve uniquement ses en-têtes de colonnes.

Le graphe exécutable final est :

```text
unisonges_theme.info.yml + page.html.twig/page--front.html.twig
└── unisonges_theme/unisonges-layout (4 CSS, 5 JavaScript)

node--7.html.twig
└── unisonges_theme/contact (aucun actif direct)
    └── unisonges_theme/unisonges-layout

routes auth/compte autorisées seulement
└── unisonges_theme/auth-account (1 CSS)
    └── unisonges_theme/unisonges-layout
```

Sur `/cart`, les attaches globale et Twig de `unisonges-layout` convergent vers
la même bibliothèque dédupliquée ; aucune bibliothèque panier ou auth n'est
ajoutée. Le template et le code de l'en-tête restent inchangés par cette PR.

## Audit de rendu prouvé

L'audit porte sur les versions verrouillées par `composer.lock` : Drupal
Commerce 3.3.2, Drupal core 11.3.3 et Bootstrap Barrio 5.5.20. Les sources
officielles correspondantes ont été inspectées statiquement ; aucun nom de
template n'a été déduit sans preuve.

### Panier vide

`Drupal\commerce_cart\Controller\CartController::cartPage()` filtre les paniers
du visiteur avec `hasItems()`. Quand il n'en reste aucun, son render array est
exactement :

```php
[
  '#theme' => 'commerce_cart_empty_page',
]
```

`CommerceCartThemeHooks` déclare ce hook et Commerce fournit le template
`commerce-cart-empty-page.html.twig`. Le seul override ajouté porte donc ce nom
exact. Il ne remplace ni page, ni formulaire, ni View.

Copie finale rendue :

> Votre panier est vide.
>
> Pour réserver un cours, commencez par choisir le cours et le créneau.
>
> Lorsqu’un billet est disponible, il est proposé depuis la page du stage ou du
> concert concerné.

La première phrase utilise la chaîne Commerce source
`Your shopping cart is empty.` avec le filtre `|t`. Le PO français officiel de
Commerce 3.3.2 fournit déjà « Votre panier est vide. » ; elle n'est donc pas
dupliquée en dur.

### Panier non vide et formulaire

Pour chaque panier non vide du visiteur courant, le contrôleur produit un render
array Views embarqué :

```php
[
  '#prefix' => '<div class="cart cart-form">',
  '#suffix' => '</div>',
  '#type' => 'view',
  '#name' => $cart_form_view,
  '#arguments' => [$cart->id()],
  '#embed' => TRUE,
]
```

Les contextes de cache incluent `user` et `session`, avec les dépendances du
panier. Le type de commande synchronisé ne choisit pas une autre View ; le
fallback Commerce est donc `commerce_cart_form`, display `default`.

Core construit les suggestions Views à partir du display, de l'ID et du tag.
Après déduplication, la liste exacte de ce display est :

- `views-view--commerce-cart-form--default.html.twig` ;
- `views-view--default.html.twig` ;
- `views-view--commerce-cart-form.html.twig` ;
- `views-view.html.twig`.

La liste exacte du style table est :

- `views-view-table--commerce-cart-form--default.html.twig` ;
- `views-view-table--default.html.twig` ;
- `views-view-table--commerce-cart-form.html.twig` ;
- `views-view-table.html.twig`.

Aucun override de ces suggestions n'est ajouté. Views/Form API conserve le
formulaire réel, ses substitutions de champs, `form_build_id`, `form_id`, le
jeton CSRF pour les utilisateurs authentifiés, les handlers et les messages
serveur.

Il n'existe ni hook ni template `commerce-cart-form.html.twig`. Core utilise le
wrapper générique `form` / `form.html.twig`. Pour ce display, l'ID de base est
`views_form_commerce_cart_form_default` et l'ID complet est
`views_form_commerce_cart_form_default_{cart_id}`. Inventer un template Commerce
spécifique contournerait donc le rendu réellement utilisé.

Les champs publics restent, dans leur ordre existant :

| Champ Views | Plugin/formatter conservé | Libellé public |
| --- | --- | --- |
| `purchased_entity` | `field` / `entity_reference_entity_view`, mode `cart` | Article |
| `unit_price__number` | `field` / `commerce_price_default` | Prix |
| `edit_quantity` | `commerce_order_item_edit_quantity` | Quantité |
| `remove_button` | `commerce_order_item_remove_button` | Retirer |
| `total_price__number` | `field` / `commerce_price_default` | Total |

La quantité reste un vrai champ numérique Form API, obligatoire, avec
validation native et label accessible. Le retrait, la mise à jour et le
checkout restent de vrais submits. Le code métier existant qui contrôle les
quantités de cours d'essai dans le formulaire panier reste donc sur son chemin
d'exécution normal.

Les deux contrôles de ligne sont construits par leurs plugins sous forme de
render arrays Form API. La quantité conserve notamment `#type = number`, le
title traduit `Quantity` avec `#title_display = invisible`, `#min = 0`, le step
Commerce, `#required = TRUE` et la classe `quantity-edit-input`. Le retrait
conserve :

```php
[
  '#type' => 'submit',
  '#value' => $this->t('Remove'),
  '#name' => 'delete-order-item-' . $row_index,
  '#remove_order_item' => TRUE,
  '#row_index' => $row_index,
  '#attributes' => ['class' => ['delete-order-item']],
]
```

### Produits, variations, ajout et messages

Le display produit `commerce_product.default.default` garde le formatter
`commerce_add_to_cart` avec `combine = true`, `default_quantity = 1` et
`show_quantity = false`. Le form mode ligne `add_to_cart` garde le widget de
variation et masque la quantité, les prix et ajustements internes. Commerce
construit « Add to cart » comme un vrai submit puis utilise `CartProvider` et
`CartManager` ; aucun formulaire d'ajout n'est surchargé.

Le subscriber Commerce n'émet son message d'ajout que si le réglage existant du
type de commande l'autorise. Ce réglage, les messages de mise à jour/retrait et
le rendu Drupal des messages restent tous inchangés.

Le seul display variation `cart` explicite synchronisé appartient au bundle
`default`. Les variations `ticket_stage` et `ticket_concert` ont chacune un
display bundle `default`, utilisé comme fallback. Les bundles cours n'ont pas de
display bundle `cart` ou `default` synchronisé ; Core construit alors un display
runtime. La représentation exacte de ces variations doit être vérifiée dans la
matrice différée, sans ajouter ici de configuration produit/variation interdite.

### Ligne, variation et total

La table du panier interroge les lignes de commande, mais ne rend pas une entité
`commerce_order_item` avec `commerce-order-item.html.twig`. Le champ
`purchased_entity` rend la variation achetée dans le view mode `cart`. Ajouter
un template de ligne de commande n'aurait donc aucun effet sur `/cart` et aucun
n'est ajouté.

Le formatter de référence construit la variation avec les clés utiles
suivantes :

```php
[
  '#commerce_product_variation' => $variation,
  '#view_mode' => 'cart',
  '#cache' => $cache,
  '#theme' => 'commerce_product_variation',
]
```

Commerce ajoute exactement, dans cet ordre, les suggestions variation :

- `commerce-product-variation--cart.html.twig` ;
- `commerce-product-variation--{bundle}.html.twig` ;
- `commerce-product-variation--{bundle}--cart.html.twig` ;
- `commerce-product-variation--{id}.html.twig` ;
- `commerce-product-variation--{id}--cart.html.twig` ;
- puis le fallback `commerce-product-variation.html.twig`.

Si une ligne de commande était rendue comme entité ailleurs, les suggestions
prouvées seraient, dans le même ordre :

- `commerce-order-item--{view-mode}.html.twig` ;
- `commerce-order-item--{bundle}.html.twig` ;
- `commerce-order-item--{bundle}--{view-mode}.html.twig` ;
- `commerce-order-item--{id}.html.twig` ;
- `commerce-order-item--{id}--{view-mode}.html.twig` ;
- puis le fallback `commerce-order-item.html.twig`.

Elles sont auditées, pas surchargées.

Le footer Views `commerce_order_total` recharge la commande et rend son champ
`total_price` avec :

```php
$order->get('total_price')->view([
  'label' => 'hidden',
  'type' => 'commerce_order_total_summary',
  'weight' => $position,
]);
```

Le formatter produit ensuite :

```php
[
  '#theme' => 'commerce_order_total_summary',
  '#order_entity' => $order,
  '#totals' => $order_total_summary->buildTotals($order),
]
```

Son template prouvé est `commerce-order-total-summary.html.twig`. Il reste
inchangé.

### Action checkout

Il n'existe pas de template checkout propre au panier. Commerce injecte ce
render array Form API :

```php
$form['actions']['checkout'] = [
  '#type' => 'submit',
  '#value' => $this->t('Checkout'),
  '#weight' => 5,
  '#access' => $this->currentUser->hasPermission('access checkout'),
  '#submit' => array_merge($form['#submit'], [
    [static::class, 'orderItemViewsFormSubmit'],
  ]),
  '#order_id' => $form['#arguments'][0],
  '#update_cart' => TRUE,
  '#show_update_message' => FALSE,
];
```

Son submit met d'abord le panier à jour puis redirige vers la route existante
`commerce_checkout.form`. Le PO officiel traduit déjà le label en « Passer la
commande ».

Ce submit n'est ni remplacé ni transformé en lien. Les flows `default`,
`paypal_checkout` et `paypal_fastlane`, leurs panes, leur destination, les
passerelles et PayPal restent inchangés.

## Views Commerce inspectées

Toutes les Views synchronisées de panier et de lignes de commande ont été
inspectées :

- `commerce_cart_form/default` : panier public éditable ;
- `commerce_cart_block/default` : bloc résumé, non placé dans le thème actuel ;
- `commerce_carts/default` et `commerce_carts/page_1` : administration ;
- `commerce_order_item_table/default` : table réutilisable de lignes ;
- `commerce_order_item_table_admin/default` : table d'administration ;
- `commerce_checkout_order_summary/default` : résumé du checkout.

Seul `commerce_cart_form/default` présentait le libellé public anglais `Item`.
Les labels internes d'administration, IDs de plugins et IDs machine ne sont pas
traduits. `commerce_cart_block` reste identique octet pour octet.

Avant cette modification, le thème personnalisé ne contenait aucun template
Commerce. La région vide de `commerce_cart_form` est elle-même vide ; elle ne
sert pas au vrai panier vide, puisque le contrôleur prend auparavant le chemin
`commerce_cart_empty_page`.

## Traductions Commerce existantes

Le catalogue français officiel Commerce 3.3.2 fournit déjà notamment :

| Chaîne source | Traduction existante |
| --- | --- |
| `Shopping cart` | Panier |
| `Your shopping cart is empty.` | Votre panier est vide. |
| `Add to cart` | Ajouter au panier |
| `Remove` | Retirer |
| `Update cart` | Mettre à jour le panier |
| `Checkout` | Passer la commande |
| `Quantity` | Quantité |
| `Subtotal` | Sous-total |
| `Unit price` | Prix unitaire |
| `Total price` | Prix total |

La chaîne exacte `Item` n'est pas fournie par ce catalogue. Le libellé possédé
par la View est donc corrigé en « Article ».

## Relation avec les réservations et billets

`/reservation-cours` reste le point de départ normal d'un cours : le visiteur
choisit d'abord le cours et le créneau. `/reserver` reste une route de
compatibilité possédée par la PR dédiée. Aucune de ces routes, leur contenu ou
`node--8.html.twig` n'est modifié.

Le tunnel existant peut orienter le choix « en ligne » vers un produit canonique
avec `reservation-first=1`, mais il indique déjà que le créneau choisi n'est pas
réservé. Le panier ne reçoit aucun état de réservation et ne crée aucune
revendication de créneau. Le handoff complet
créneau → panier → paiement en ligne reste une implémentation future distincte.

Les droits, crédits, cours d'essai et règles de paiement sur place restent
inchangés. Pour les Stages, le CTA existant n'est affiché que lorsqu'un produit
ticket et une variation publiés et accessibles existent ; sinon la page indique
que la billetterie viendra prochainement. L'état vide dit donc seulement
« lorsqu'un billet est disponible » et ne promet jamais un billet pour chaque
Stage ou Concert.

`CartProvider` conserve le filtrage par propriétaire courant, flag panier, état
brouillon et boutique publiée. Les paniers anonymes restent associés à la
session ; les paniers authentifiés restent associés à leur UID. Les contextes
de cache `user` et `session`, les contrôles d'accès et la persistance ne sont pas
remplacés.

## En-tête et accessibilité

Le thème expose `header`, `primary_menu`, `content` et `footer`. L'en-tête et le
drawer mobile n'intègrent actuellement aucun panier. Ils restent hors périmètre :
aucun lien, icône, compteur, CSS ou JavaScript d'en-tête n'est ajouté. Un
indicateur panier persistant pourra être étudié ultérieurement, séparément. La
page `/cart` reste utilisable directement sans cet indicateur.

Le H1 « Panier » reste fourni par le page-title. L'état vide commence par un H2,
les liens ont des libellés visibles complets et la navigation est nommée. Le
panier non vide garde ses vrais en-têtes de table et ses labels de formulaire.
Bootstrap Barrio 5.5.20 enveloppe déjà la table Views dans
`.table-responsive.col` et ajoute `scope="col"` aux `<th>`. Les classes d'actions
existantes autorisent le retour à la ligne et donnent des boutons pleine largeur
sous 460 px ; aucun CSS global n'est nécessaire. Les messages Drupal et la
validation native restent présents.

## Déploiement ciblé de la configuration

Le seul objet actif autorisé en écriture est :

```text
views.view.commerce_cart_form
```

`drupal/scripts/apply-cart-ux-2026.sh` est dry-run par défaut. Il n'utilise ni
import complet, ni import partiel, ni SQL brut, ni écriture d'entité. Il :

1. vérifie le chemin, la source suivie et identique à `HEAD`, son SHA-256, son
   UUID, ses dépendances, ses champs, plugins et deux états complets revus, puis
   prouve que le `CachedStorage` Core enveloppe directement le
   `DatabaseStorage` transactionnel du site ;
2. snapshotte et empreinte toutes les configurations actives de toutes les
   collections avant toute écriture ;
3. refuse un objet cible absent ou différent des états exacts `Item`/`Article` ;
4. imprime une empreinte de plan liée au snapshot complet en dry-run, sans
   verrou ni écriture active ;
5. exige cette empreinte pour `--apply`, répète le preflight sous deux verrous
   persistants d'une heure, renouvelle leur durée immédiatement avant
   l'écriture, refuse une transaction externe, puis écrit le seul objet
   allowlisté dans une transaction ;
6. compare le snapshot brut complet au résultat exact attendu avant le commit,
   rollback et vérifie l'empreinte antérieure si l'isolation ou la cible diffère,
   puis finalise explicitement cette transaction déjà vérifiée ;
7. traite un second apply comme un no-op idempotent et sait reconstruire l'état
   antérieur exact avec `--rollback`.

Les deux verrous sérialisent les imports et les exécutions coopératives du
helper ; ils ne peuvent pas empêcher un processus d'écriture arbitraire qui
ignorerait ces verrous pendant ou après l'opération. Le second dry-run
obligatoire reprend donc un nouveau snapshot brut complet et rend immédiatement
visible toute dérive ultérieure, sans tenter d'écraser cet état tiers.

Commandes à exécuter plus tard sur le staging validé, depuis `drupal/`, avec le
vendor verrouillé installé :

```bash
./scripts/apply-cart-ux-2026.sh --dry-run
./scripts/apply-cart-ux-2026.sh --apply \
  --expect-fingerprint=<PLAN_FINGERPRINT_du_dry-run>
./scripts/apply-cart-ux-2026.sh --dry-run

# Preuve explicite d'idempotence : le second apply doit répondre NOOP.
./scripts/apply-cart-ux-2026.sh --apply \
  --expect-fingerprint=<PLAN_FINGERPRINT_du_second_dry-run>

# Rollback contrôlé.
./scripts/apply-cart-ux-2026.sh --rollback --dry-run
./scripts/apply-cart-ux-2026.sh --rollback --apply \
  --expect-fingerprint=<PLAN_FINGERPRINT_du_rollback_dry-run>
./scripts/apply-cart-ux-2026.sh --rollback --dry-run
```

Si le checkout de staging validé se trouve sous `/var/www`, ajouter
`--allow-vps` à chacune de ces commandes. Ce flag n'autorise pas une exécution
en production.

Conserver les empreintes avant apply et après rollback afin de prouver leur
égalité. Le template est déployé comme code du thème ; le cache du thème devra
être reconstruit dans la fenêtre runtime possédée par la PR #98. Ces commandes
ne sont pas exécutées dans cette phase statique.

## Validation statique

Les contrôles sans runtime couvrent : parsing YAML strict, sémantique complète
de la View, hashes canoniques exacts des états actif avant/cible, Unicode NFC,
destinations exactes, absence de destination produit, préservation des cinq
champs et des plugins Form API, syntaxe Twig, Bash, ShellCheck, PHP embarqué,
whitespace, UUID/dépendances, allowlist de quatre fichiers, absence d'import ou
de SQL brut, graphe final des bibliothèques et scan de secrets du diff. Trois
revues indépendantes couvrent séparément Commerce, accessibilité et opérations.

Aucun DDEV, Docker, Drush, Chromium, Mailpit ou VPS n'est utilisé pour cette
validation.

Résultats après rebase :

- les 486 YAML suivis sont parsés strictement avec Symfony YAML 7.4.6, version
  verrouillée ;
- les UUID, dépendances, champs ordonnés et plugins de la View satisfont toutes
  les assertions Commerce, et la transition complète diffère uniquement sur
  `Item` → `Article` ;
- les empreintes canoniques du seul objet de configuration revu sont
  `d9f0e95f095afd0212e185841144055bf1c501cec8d1c9312d249c272c452741`
  avant et `b73daaebb4a44634c4bb19b5d0a4354d72b13b9d545e8c8d24dbc7f0488c8b8f`
  cible ; l'empreinte de toutes les collections actives exige le runtime et
  reste dans la matrice différée ;
- les 23 templates personnalisés compilent avec Twig 3.22.2, version
  verrouillée ;
- `bash -n`, ShellCheck 0.9.0 et le lint PHP 8.2.33 du programme embarqué passent ;
- le graphe final des trois bibliothèques est résolu et acyclique ;
- les gardes quatre fichiers, liens exacts, H1/H2, UUID unique, dépendances,
  absence d'import/SQL brut, UTF-8/NFC, mode exécutable et marqueurs de conflit
  passent ;
- les sources fusionnées des PR #84, #99 et #100 ainsi que l'en-tête sont
  byte-identiques à `origin/release/prod` ;
- `git diff --check` et le scan de secrets à forte confiance passent ;
- les revues indépendantes Commerce, accessibilité/intégration et opérations
  concluent toutes `PASS`, sans finding bloquant.

## Matrice runtime différée

Cette matrice appartient à la PR #98 et doit être exécutée avant de sortir la PR
#90 du brouillon. Elle n'est pas lancée depuis ce worktree.

### Panier vide

- visiteur anonyme avec panier vide ;
- utilisateur authentifié avec panier vide ;
- H1 « Panier » et état vide français correct ;
- liens exacts vers réservation, stages et concerts ;
- aucun doublon entre le texte vide et les messages système Drupal ;
- aucun CTA cours orienté produit/crédit ;
- aucune promesse de livraison ou d'expédition ;
- aucun débordement horizontal.

### Panier non vide

- panier anonyme avec un article ;
- panier authentifié avec un article ;
- plusieurs articles ;
- produit billet de Stage ;
- autre produit publié et achetable ;
- mise à jour de quantité ;
- retrait ;
- prix unitaire ;
- prix total de ligne et total de commande ;
- submit checkout et destination réelle ;
- persistance après rafraîchissement ;
- messages serveur après mise à jour et retrait.

### Sécurité et règles métier

- un autre utilisateur ne peut pas accéder au panier ;
- aucun produit non publié n'est divulgué ;
- aucun état de commande ne change avant le checkout ;
- aucune affirmation ou création de réservation de créneau ;
- aucune mutation paiement sur place, droit ou crédit ;
- aucune passerelle de paiement modifiée ;
- aucun calcul de taxe, prix ou devise modifié.

### Navigateur et accessibilité

- desktop, tablette et mobile ;
- clavier seul ;
- reflow à 100 %, 150 % et 200 % ;
- aucun overflow et contrôles de table accessibles ;
- aucun H1 dupliqué après intégration de la PR #84 (page-title) ;
- un seul chemin de messages après intégration de la PR #100 ;
- `/cart` sans classe ni bibliothèque auth/compte après intégration de la PR #99 ;
- graphe `unisonges-layout`/`contact`/`auth-account` final sans actif dupliqué ;
- en-tête inchangé et sans lien, icône ou compteur panier ;
- aucun warning PHP ni erreur console.

### Opérations

- dry-run ciblé ;
- apply ;
- second dry-run ;
- second apply idempotent sans écriture ;
- rollback ;
- restauration de l'empreinte complète de configuration active ;
- suppression ou rollback de tous les paniers, commandes et lignes de test ;
- zéro résidu de fixture.
