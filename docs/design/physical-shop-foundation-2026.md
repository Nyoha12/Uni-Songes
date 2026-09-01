# Fondation d’une future boutique d’objets physiques — 2026

> **Statut : proposition de découverte et de conception, non déployable en
> l’état.** Ce document ne crée aucune URL publique, aucun produit, aucun prix,
> aucune configuration Drupal Commerce, aucun mode de livraison et aucune
> passerelle de paiement.

## 1. Décision proposée

Uni-Songes peut s’appuyer sur son socle Drupal Commerce pour les notions
génériques de produit, variation, prix, panier, commande, profil client et
paiement. En revanche, les objets physiques ne doivent pas réutiliser les
types, le panier, le workflow ou les automatismes de cours et de billetterie.

La cible recommandée est une verticale Commerce isolée :

- un type de produit physique et un type de variation physique dédiés ;
- un type de ligne, un type de commande, un panier et un checkout dédiés ;
- des états de paiement et de préparation/livraison distincts ;
- une décision explicite sur Commerce Shipping, Physical Fields et Commerce
  Stock avant tout ajout de dépendance ;
- aucun effet de bord vers les droits de cours, les réservations, les billets
  Stage/Concert ou Google Calendar ;
- une première direction graphique volontairement neutre, à remplacer ou à
  confirmer après le brief de la propriétaire.

Quatre gates bloquent toute implémentation ou essai de paiement :

1. révoquer/rotater le secret PayPal déjà identifié, retirer toute valeur
   sensible de Git et de l’export de configuration, puis choisir un stockage
   externe de secrets ;
2. obtenir les décisions propriétaire, juridiques et opérationnelles du
   [brief associé](physical-shop-owner-brief-2026.md) ;
3. choisir et éprouver les dépendances de livraison, mesures et stock avec
   Drupal 11 / Commerce 3.3 ;
4. valider l’isolation transactionnelle des commandes physiques.

## 2. Cadre et méthode de l’audit

Audit statique réalisé le 1er septembre 2026 depuis
`codex-design-physical-shop-foundation`, basé exactement sur
`origin/release/prod` au commit `48b9eb4bbc2eef8bde2c5b5244d5a21a4b620af8`.

Sources inspectées : export `drupal/config/sync`, `drupal/composer.json`,
`drupal/composer.lock`, module custom `unisonges_structure`, thème, scripts et
documentation versionnée. Les noms de fichiers des PR ouvertes ont été lus via
GitHub pour détecter les recouvrements. Le dépôt était public au moment de
l’audit.

Limites assumées :

- aucune connexion au VPS et aucune lecture de la base active ;
- aucun DDEV, Docker, Drush, navigateur Chromium ou Mailpit ;
- les entités de contenu, notamment les magasins Commerce, ne sont pas
  exportées comme configuration : leur état runtime reste à confirmer plus
  tard dans un environnement autorisé ;
- aucune valeur d’identifiant ou de secret n’a été lue, copiée ou reproduite ;
- aucune commande de test n’a écrit de donnée Drupal ou Commerce.

## 3. Capacités Commerce actuelles

### 3.1 Socle installé

Le dépôt verrouille Drupal Commerce `3.3.2` et Commerce PayPal `2.1.0`. Sont
activés : Commerce, Cart, Checkout, Order, Payment, PayPal, Price, Product,
Store, Address et Profile. Ce socle est une base technique, pas une capacité de
vente et d’expédition d’objets physiques complète.

### 3.2 Produits et variations

| Type produit exporté | Type variation homonyme | Association exportée | Usage à préserver |
| --- | --- | --- | --- |
| `default` | `default` | `variationTypes: [default]` | Type générique historique, à ne pas adopter par défaut |
| `cours_essai` | `cours_essai` | vide | Cours |
| `cours_deb_inter` | `cours_deb_inter` | vide | Cours |
| `cours_avance` | `cours_avance` | vide | Cours |
| `pack_4_deb_inter` | `pack_4_deb_inter` | vide | Pack de cours |
| `ticket_stage` | `ticket_stage` | vide | Billet Stage |
| `ticket_concert` | `ticket_concert` | vide | Billet Concert |

Tous les types de variation utilisent actuellement le type de ligne
`default`, qui pointe lui-même vers le type de commande `default`. Le SKU, le
prix et l’état de publication existent comme champs Commerce de base. Il
n’existe aucun attribut produit configuré. Les capacités miroir des billets ne
sont pas du stock physique.

Conséquence : les types actuels portent des contrats métier différents et ne
sont pas une base sûre pour des objets livrables. La future association entre
le type physique et sa variation devra être explicite dans l’export.

### 3.3 Commande, checkout et paiement

Une seule chaîne est configurée :

- ligne `default` → commande `default` ;
- workflow `order_default` ;
- numérotation `order_default` ;
- checkout `default` ;
- reçu Commerce activé.

L’audit de source déjà versionné relève pour ce workflow verrouillé les états
`draft`, `completed` et `canceled`. Il n’exprime pas les étapes physiques
« payée », « préparation » ou « expédiée ».

Trois flows existent :

| Flow | Pans principaux constatés | Limite pour les objets physiques |
| --- | --- | --- |
| `default` | connexion, contact, facturation, revue, confirmation, résumé | aucune adresse de livraison, aucun colis, aucun choix de livraison |
| `paypal_checkout` | revue, traitement PayPal, confirmation | flow express non conçu comme architecture d’expédition |
| `paypal_fastlane` | contact, information de paiement, confirmation | aucune capacité de livraison démontrée dans l’export |

Deux passerelles sont actives :

- `manual`, libellée « Paiement sur place », en mode test et limitée aux
  comptes authentifiés ; elle sert déjà un parcours de cours et ne doit pas être
  assimilée au retrait local ;
- `paypal`, sans condition de type de commande, intégrée au panier et configurée
  pour la capture. L’identifiant de webhook est vide, la journalisation des
  requêtes webhook est activée, et des clés de configuration sensibles sont
  renseignées. Leurs valeurs n’ont pas été consultées.

La configuration PayPal ne doit pas être testée ni réutilisée avant la gate de
sécurité. Le réglage exporté demande à PayPal une adresse déjà connue du compte,
alors que le flow ne collecte pas d’adresse de livraison : il ne faut pas la
considérer comme une adresse livrable faisant autorité. La future architecture
doit figer une seule source d’adresse avant paiement, traiter explicitement les
écarts et empêcher un callback d’écraser silencieusement le snapshot
d’expédition. Une préférence PayPal ne remplace ni zones, ni tarifs, ni colis,
ni workflow d’expédition.

### 3.4 Magasin et devises

- type de magasin exporté : `online` ;
- devises activées : EUR et USD ;
- aucun magasin concret n’est prouvable depuis Git, car un magasin Commerce est
  une entité de contenu ;
- la devise par défaut, les pays de facturation, l’inclusion des taxes,
  l’adresse et le statut du magasin actif restent donc à confirmer ;
- les scripts de fixtures locales ne constituent pas une preuve de production ;
- la synchronisation historique des billets cible un identifiant de magasin
  fixe. La future boutique devra résoudre un magasin explicitement configuré,
  jamais recopier cette hypothèse.

La devise commerciale de la boutique est une décision propriétaire/comptable.
La simple présence d’EUR et USD n’autorise pas une boutique multidevise.

### 3.5 Panier

Commerce Cart et les vues `commerce_cart_form` / `commerce_cart_block` sont
présents. Le panier actuel offre quantité modifiable, suppression et total. Le
formulaire produit générique ajoute une quantité initiale de 1, masque la
quantité et combine les lignes identiques. Aucun bloc panier placé dans le thème
n’est exporté.

Le module custom intercepte tous les formulaires panier/ajout/checkout, puis
applique des règles ciblées aux bundles de cours. Ce filtrage limite certains
effets, mais une ligne physique utilisant `default` pourrait partager le même
panier que des cours ou billets. La séparation par type de commande est donc
obligatoire. La PR ouverte #90 modifie précisément la vue panier et un template
de panier vide ; elle devra être intégrée ou arbitrée avant la PR de séparation.

### 3.6 Profils et adresses

Le profil `customer` existe, accepte plusieurs profils et possède une adresse
requise. Les champs postaux usuels sont disponibles ; la ligne d’adresse 3 est
masquée. `available_countries: {}` n’impose actuellement aucun pays.

Réutilisable : types Address/Profile, formatage postal et profil de facturation.

Manquant : profil/adresse de livraison distinct, liste de pays desservis,
validation de délivrabilité, copie explicite facturation ↔ livraison et règles
de minimisation/rétention. Une adresse de facturation ne doit jamais devenir
silencieusement une adresse de livraison.

### 3.7 Taxes, stock, livraison et médias

| Domaine | Constat statique |
| --- | --- |
| Taxes | `commerce_tax` non activé, aucun type ni règle de taxe exporté |
| Stock | aucun package/module, champ, emplacement, mouvement, réservation ou contrôle de concurrence |
| Livraison | aucun Commerce Shipping, type de colis/expédition, méthode, zone, tarif ou pane checkout |
| Mesures | aucun Physical Fields, poids ou dimensions |
| Images | File et Image activés ; styles `thumbnail`, `medium`, `large`, `wide` |
| Media | Media et Media Library non activés ; aucun champ image/galerie sur un produit Commerce |

File/Image est un socle réutilisable. Il ne constitue pas encore une politique
d’upload produit. Media est un module du cœur Drupal, tandis que la livraison,
les mesures et le stock nécessitent des dépendances contribuées ou une solution
custom explicitement décidée.

### 3.8 Compte et routes de commande

La vue `commerce_user_orders` expose déjà :

- `user/%user/orders`, onglet « Commandes » ;
- numéro de commande, date, total et état de commande ;
- détail `user/{uid}/orders/{order_id}` ;
- exclusion des brouillons ;
- permission `view own commerce_order` et validation
  `commerce_current_user`.

Cette isolation par propriétaire est une bonne base. Le détail actuel montre
facturation, e-mail, lignes, dates, état et totaux, mais pas l’état de paiement
distinct, la préparation, l’adresse de livraison, l’expédition, le suivi ni un
document de facture. Le message de liste vide est encore en anglais. Le guest
checkout est autorisé et `guest_order_assign` est désactivé : une commande
invitée n’est pas automatiquement retrouvée dans un compte créé plus tard.

### 3.9 Sitemap et menu actuels

Simple XML Sitemap est activé. L’export courant ne contient qu’un lien custom
`/` ; aucune politique sitemap pour des produits Commerce physiques n’existe.
La PR ouverte #82 modifie la politique sitemap/robots et devra précéder toute
intégration SEO de la boutique.

Le menu `main` est rendu dynamiquement par le thème et répliqué dans un drawer
mobile accessible. La documentation canonique versionnée décrit cinq racines :

1. Cours & Stages ;
2. Concerts & Événements ;
3. Projets collectifs ;
4. À propos ;
5. Contact.

Git ne permet pas de confirmer ici les entités de liens actives en base. Aucun
lien « Ressources » ou « Boutique » n’est ajouté par cette proposition.

## 4. Table des écarts

Les mentions de module sont des **candidats à évaluer**, pas des choix actés.

| Capacité | Déjà disponible | Réutilisable sûrement | Manquant | Module contribué possible | Décision propriétaire / légale | Processus opérationnel |
| --- | --- | --- | --- | --- | --- | --- |
| Catalogue Commerce | Entités produit/variation | SKU, prix, publication | bundles physiques et champs | non indispensable | catégories, produits, esprit | saisie et contrôle catalogue |
| Images produit | File/Image + styles | pipeline d’images Drupal | champ principal, galerie, règles | non ; Media est cœur | photos, droits, alt | prise de vue, recadrage, validation |
| Variantes | widget Commerce de base | attributs Commerce après besoin réel | aucun attribut configuré | non | vraies tailles/couleurs seulement | matrice SKU/photo/stock |
| Commande physique | entités Commande/Ligne | numérotation et totaux, sous réserve | types et workflow dédiés | Shipping pour les expéditions | vocabulaire de statut | préparation, contrôle, clôture |
| Panier | Cart + vues | composants génériques après tests | séparation par type de commande | non | comportement face à un panier mixte | support panier abandonné |
| Adresse | Address/Profile | formats postaux | livraison et pays autorisés | validation externe éventuelle | pays livrés, base légale | correction d’adresse |
| Livraison | aucune capacité métier | — | totalité de la chaîne | Commerce Shipping 3.x à évaluer | zones, transporteurs, tarifs | emballer, étiqueter, remettre |
| Poids/dimensions | aucune | — | champs et unités | Physical Fields 1.x à évaluer | données réelles par SKU | pesée/mesure et contrôle |
| Stock | aucune ; capacité billet ≠ stock | — | ledger, disponibilité, réservation | Commerce Stock 3.x à évaluer | propriétaire du stock | réception, comptage, correction |
| Taxes | structures de prix | ajustements Commerce | règles fiscales | Commerce Tax déjà livré avec Commerce mais non activé | statut TVA, pays, affichage | revue comptable et déclaration |
| Paiement | entités Payment + gateways | abstractions Commerce après remédiation | webhook sûr et scope physique | PayPal déjà installé | moyens proposés, capture/remboursement | rapprochement et exceptions |
| Retrait local | rien de dédié | profil client si nécessaire | méthode, consignes, preuve de retrait | Shipping peut le modéliser | lieu, horaires, éligibilité | stockage et remise en main propre |
| Suivi | aucun | — | expédition et donnée de suivi | connecteur transporteur éventuel | suivi ou non suivi | saisie/import, support colis |
| Facture/reçu | reçu Commerce activé | accès propriétaire aux commandes | document légal et règles | générateur PDF éventuel | obligations de facturation | numérotation, conservation, avoirs |
| Compte | liste/détail de commandes isolés | contrôle du propriétaire | états et données physiques | non nécessaire | accès invités, factures | support et correction d’identité |
| SEO | Simple Sitemap | infrastructure | produits/catégories, canonicals | non | indexation et wording | publication/dépublication |

Références de compatibilité à vérifier dans la PR de dépendances :
[Commerce Shipping](https://www.drupal.org/project/commerce_shipping),
[Physical Fields](https://www.drupal.org/project/physical) et
[Commerce Stock](https://www.drupal.org/project/commerce_stock).

## 5. Modèle proposé pour les objets physiques

Les identifiants ci-dessous sont provisoires et internes. Ils ne créent pas de
chemin public.

| Élément | Proposition | Raison d’isolation |
| --- | --- | --- |
| Type produit | `physical` | aucun héritage de cours ou billet |
| Type variation | `physical` | SKU, prix, mesures et stock par unité vendable |
| Type de ligne | `physical` | routage vers la commande physique uniquement |
| Type de commande | `physical` | panier, checkout, droits et workflow séparés |
| Checkout | `physical_shipping` | adresse et livraison sans modifier les cours |
| Expédition | `physical_default` si Shipping est retenu | préparation/suivi portés par une entité adaptée |
| Catégories | vocabulaire boutique dédié | catégories réelles fournies par la propriétaire |

### 5.1 Champs et responsabilités

| Donnée | Porteur recommandé | Règle |
| --- | --- | --- |
| Titre et publication | produit | publication produit et variation toutes deux requises |
| Description éditoriale | produit | contenu factuel fourni/validé par la propriétaire |
| Image principale | produit, Media Image si retenu | alt requis ; aucun visuel fictif en production |
| Galerie optionnelle | produit, multivaleur | seulement si des photos utiles existent |
| SKU | variation | unique, stable, obligatoire, non déduit du titre |
| Prix | variation | devise du magasin ; aucune valeur avant décision |
| Taille/couleur | attribut de variation | créer uniquement si un produit réel l’exige |
| Poids | variation | valeur et unité réelles, nécessaires aux tarifs au poids |
| Dimensions | variation | longueur/largeur/hauteur réelles, utiles à l’emballage/surdimensionné |
| Stock | variation + ledger de stock | ne pas stocker un compteur éditorial libre |
| Éligibilité livraison | variation/règles de méthode | explicite : expédiable, retrait uniquement ou non vendable |
| Retrait local | règles de méthode/variation si exception | option désactivée tant que lieu/processus non validés |
| Catégorie | produit | aucune catégorie inventée |
| État de préparation | expédition | étape `preparing` du workflow unique de l’expédition, jamais sur le catalogue |
| État de fulfilment | agrégat dérivé sur la commande | calculé depuis ses expéditions, pas un second champ mutable ni un état de paiement |

Les images communes vivent sur le produit. Une image par variation n’est
ajoutée que si une variation réelle change visuellement le produit. Le stock se
contrôle au niveau SKU. Une quantité exacte basse n’est pas exposée au public :
afficher seulement « Disponible » ou « Indisponible », sauf décision justifiée.

### 5.2 Invariants

- toute variation physique appartient au type produit physique autorisé ;
- toute ligne physique produit une commande `physical`, jamais `default` ;
- un panier physique n’accepte aucun cours ni billet et réciproquement ;
- le prix et le stock sont recalculés côté serveur au checkout ;
- une commande ne devient pas « payée » à partir d’un retour navigateur ;
- préparation et expédition ne commencent qu’après l’état de paiement requis ou
  une exception administrative auditée ;
- aucun champ de capacité Stage/Concert n’est réutilisé comme stock.

## 6. Architecture de livraison à décider

| Sujet | Question à trancher | Architecture attendue, sans choix anticipé |
| --- | --- | --- |
| Pays/zones | quels pays et territoires sont réellement servis ? | allowlist côté profil + zones de méthodes ; refus avant paiement |
| Transporteurs | qui prend réellement les colis ? | méthode manuelle ou connecteur seulement après choix et test du contrat |
| Suivi | quels services sont suivis/non suivis ? | champ de suivi facultatif ; lien affiché uniquement avec donnée réelle et URL validée |
| Tarification | forfait, poids, panier ou combinaison ? | règles déterministes testées aux bornes ; aucune valeur fictive |
| Franco | existe-t-il un seuil et sur quelle assiette ? | condition sur sous-total admissible, devise et taxes explicites |
| Retrait local | lieu, horaires, preuve, produits éligibles ? | méthode séparée sans faux transporteur ; adresse de livraison non exigée si inutile |
| Emballage | coût inclus ou ligne visible ? | politique transparente compatible avec le droit applicable |
| Surdimensionné | quelles limites réelles ? | dimensions/poids obligatoires, méthode dédiée ou blocage avant paiement |
| Délais | délai de préparation et délai transporteur ? | fourchettes factuelles ; aucune date promise sans calcul réel |
| Validation d’adresse | quel niveau et quel fournisseur ? | contraintes locales d’abord ; service externe seulement avec base légale et correction accessible |
| Colis perdu | délai de recherche, preuve, interlocuteur, issue ? | dossier support, historique, réclamation transporteur, remboursement/réexpédition autorisé |

Le panier doit calculer un colis à partir des mesures réelles. Si une donnée
requise manque, il faut bloquer l’achat avec un message actionnable plutôt que
proposer un tarif par défaut. Les emballages multiples, articles incompatibles
et produits surdimensionnés font partie des scénarios d’acceptation du futur
module de packing.

## 7. Cycle de commande

Le libellé présenté au membre peut agréger trois sources de vérité sans les
confondre : workflow de commande, **statut de paiement dérivé** et workflow
d’expédition.

| État demandé | État commande | Statut de paiement dérivé | Workflow d’expédition |
| --- | --- | --- | --- |
| Brouillon panier | `draft` | aucun | non commencé |
| Passée | `placed` | initialisé | non commencé |
| Paiement en attente | `placed` | `pending` | non commencé |
| Payée | `placed` | `paid` | à préparer |
| En préparation | `placed` | `paid` | `preparing` |
| Expédiée | `placed` | `paid` | `shipped` |
| Terminée | `completed` | `paid` ou état compatible | `completed` |
| Annulée | `cancelled` | annulé/void/remboursement selon cas | `cancelled` |
| Remboursée | commande conservée | `refunded` | état historique conservé |

`pending` doit provenir de preuves locales/passerelle réelles. `paid` est dérivé
côté serveur de paiements terminés couvrant le solde exigible, notamment via
`order->isPaid()`, jamais d’un retour navigateur. Le remboursement est dérivé
des montants remboursés sur un ou plusieurs paiements et distingue partiel de
total. « Remboursée » n’est ni une suppression ni forcément une annulation de
livraison. Retours et réexpéditions préservent l’historique. Les transitions
administratives exigent un motif et sont journalisées.

Une expédition porte le workflow mutable unique
`preparing` → `shipped` → `completed`. L’état affiché sur la commande est un
agrégat dérivé de toutes ses expéditions ; aucun second champ manuel de
« préparation » ou « fulfilment » ne doit diverger.
Si le retrait local est validé, sa branche de fulfilment est
`preparing` → `ready_for_pickup` → `completed/collected` ; elle ne passe jamais
artificiellement par « expédiée ».

Les transitions de fulfilment exigent une autorité et une preuve définies :

- seuls les rôles opérationnels explicitement habilités peuvent avancer une
  expédition ; un droit catalogue, paiement ou remboursement ne l’accorde pas
  implicitement ;
- `shipped` signifie une remise physique enregistrée au transporteur, par une
  preuve opérationnelle ou un événement transporteur fiable ; créer ou imprimer
  une étiquette ne suffit jamais ;
- pour un service suivi, `completed` repose sur une preuve de livraison jugée
  fiable ; pour un service non suivi, il suit uniquement la politique manuelle
  explicite et validée par la propriétaire, sans prétendre disposer d’une preuve
  de livraison absente ; un retrait devient `collected` sur remise enregistrée ;
- avec plusieurs colis, chaque expédition conserve son état. L’agrégat commande
  signale « partiel/en cours » dès que leurs états diffèrent et ne devient
  `completed` que lorsque tous les colis sont comptabilisés selon leur issue
  autorisée ;
- l’annulation n’est possible que jusqu’au point de non-retour opérationnel
  validé. Après remise, l’historique d’expédition reste immuable et l’exception
  passe par retour, perte, réexpédition ou remboursement, jamais par une fausse
  annulation rétroactive.

### 7.1 Barrières avec les autres métiers

Le module custom appelle actuellement le traitement des droits de cours à
chaque insertion et mise à jour de commande. La fonction ne crédite que les
bundles de cours, mais une commande physique payée peut encore traverser ce
traitement et recevoir le marqueur interne des droits. La future PR doit ajouter
un garde immédiat par type de commande et par présence de produit de cours.

Tests négatifs obligatoires pour une commande physique :

- aucun incrément de crédits ou droit de cours ;
- aucune ligne de droit « paiement sur place » ;
- aucune création/mise à jour de Webform de réservation ;
- aucune file ou entrée Google Calendar ;
- aucune synchronisation de produit/billet Stage ou Concert ;
- aucun message de confirmation invitant à réserver un cours ;
- aucun accès à la passerelle « Paiement sur place » sans décision distincte de
  retrait local.

## 8. Intégration future au compte

Le futur onglet doit être libellé « Mes commandes ». La liste réutilise le
contrôle `commerce_current_user` et affiche, pour chaque commande placée :

- numéro de commande ;
- date de commande ;
- état de paiement séparé ;
- état de préparation/livraison séparé ;
- total et devise ;
- nature « Boutique » distinguée des cours et billets.

Le détail ajoute :

- résumé minimisé de l’adresse de livraison ;
- colis et service réellement choisis ;
- référence/lien de suivi seulement si une donnée réelle et autorisée existe ;
- aucune date de livraison simulée ;
- reçu de paiement et facture uniquement lorsque leur qualification juridique,
  leur génération et leur contrôle d’accès sont validés ;
- historique utile des annulations/remboursements sans exposer les notes admin.

Une commande invitée nécessite soit un accès sécurisé à durée limitée, soit un
rattachement vérifié au compte ; le numéro de commande et une adresse e-mail ne
doivent pas suffire seuls à contourner l’isolation.

## 9. Fondation UX de la boutique

Les chemins sont une **architecture proposée**, pas des routes créées :

| Écran | Chemin conceptuel | Contenu minimum |
| --- | --- | --- |
| Landing | `/boutique` | introduction éditoriale, catégories réelles, sélection sobre |
| Catégorie | à définir sous `/boutique` | titre, description, filtre utile, grille modeste |
| Fiche produit | à définir sous `/boutique` | photos, texte, prix, disponibilité, variantes réelles, livraison |
| Panier | route Commerce existante à arbitrer | lignes physiques isolées, quantités, sous-total, livraison non inventée |
| Checkout | route Commerce dédiée | contact, livraison/retrait, facturation, paiement, revue |
| Confirmation | route Commerce dédiée | numéro réel, états réels, prochaines étapes |

Le prototype statique couvre : landing, catégorie, produit disponible, produit
indisponible, produit avec variations non définies, panier vide, panier rempli,
livraison, confirmation et aperçu mobile. Toutes ses données sont explicitement
fictives.

### 9.1 Direction visuelle provisoire

- composition éditoriale, non marketplace ;
- palette chaude Uni-Songes, surfaces crème et accent sarcelle sobre ;
- hiérarchie typographique forte avec polices système uniquement ;
- zone photo calme et généreuse ;
- cartes peu nombreuses, bord fin, ombre discrète ;
- prix lisible et état de stock textuel visible ;
- reflow réel à 320 px, contrôles tactiles et focus marqués.

Sont exclus : compte à rebours, fausse remise, rareté du type « plus que 2 »,
étoiles, compte d’avis, carrousel promotionnel géant, allégation écologique non
prouvée, suivi ou délai inventé.

## 10. Insertion future « Ressources » et « Boutique »

Ordre racine recommandé à soumettre à validation :

1. Cours & Stages ;
2. Concerts & Événements ;
3. Projets collectifs ;
4. Ressources ;
5. Boutique ;
6. À propos ;
7. Contact.

Raisonnement : les trois activités principales restent en tête ; Ressources
porte l’éditorial ; Boutique reste visible sans devenir l’entrée dominante ; À
propos et Contact terminent le parcours. « Boutique » ne doit pas devenir un
enfant de « Ressources » et aucun lien n’est publié avant que `/boutique` existe
et ait franchi les gates.

Impacts responsives : sept libellés français dépassent probablement la largeur
du header avant le breakpoint actuel. L’implémentation devra basculer plus tôt
vers le drawer, éviter une seconde ligne dans le header sticky, préserver le
même ordre DOM, conserver les boutons de disclosure adjacents aux parents et
tester 320 px, zoom 200 %, clavier, libellés longs et compte connecté. La
navigation ne doit pas devenir un carrousel horizontal.

### 10.1 Préparation juridique et opérationnelle

Les pages publiques actuelles de confidentialité et de mentions légales ne
décrivent pas encore un compte Commerce, un paiement, une livraison, un retour
ou un support de boutique fonctionnels. Elles devront être revues à partir des
décisions validées, sans rédiger de termes finaux dans une PR technique. Le
brief propriétaire couvre identité du vendeur, TVA, pays, délais, rétractation,
retours, échanges, dommages, remboursements, confidentialité, CGV,
facturation, stock, emballage, opérateur de fulfilment et contact support.

## 11. Sécurité et données personnelles

### Gate PayPal

Le dépôt est public et l’incident de secret déjà documenté reste bloquant.
Avant tout test ou déploiement paiement :

1. révoquer puis rotater hors Git le secret concerné ;
2. confirmer qu’aucun ancien secret n’est encore actif ;
3. retirer les valeurs de l’export et traiter l’historique selon une procédure
   coordonnée ;
4. stocker les secrets hors Git/config export, avec références par environnement ;
5. renseigner et vérifier un webhook dédié à chaque environnement ;
6. désactiver ou minimiser la journalisation des corps de webhook ;
7. valider les identifiants de plugin, méthode et mode effectifs après mise à
   niveau, sans afficher de valeur sensible ;
8. réaliser un scan de secrets avant activation.

La passerelle reste désactivée tant que toutes les gates ne sont pas prouvées.

### Paiement et webhook

- vérifier cryptographiquement la signature, la passerelle, l’environnement et
  le marchand/bénéficiaire attendus ;
- relier explicitement ordre distant, capture/paiement distant et commande
  locale, puis recharger toutes les entités côté serveur ;
- comparer devise, montant/solde exigible exacts et états locaux/distants
  autorisés avant toute finalisation ; le navigateur n’est jamais une preuve ;
- utiliser l’ID d’événement fournisseur comme clé anti-rejeu et l’identité de
  capture/paiement comme identité unique, tout en acceptant plusieurs événements
  légitimes `pending`, `completed` ou `refund` pour une même transaction ;
- finaliser paiement/commande/réservation de stock avec un finaliseur unique,
  idempotent et transactionnel ;
- refuser fermé, mettre en quarantaine et rapprocher manuellement tout écart de
  marchand, environnement, ordre, montant, devise ou état ;
- traiter les événements dupliqués, désordonnés, tardifs et les remboursements ;
- journaliser des identifiants techniques et résultats, pas des secrets ni des
  payloads personnels complets ;
- rapprocher périodiquement commandes, paiements et événements en exception.

### Autres contrôles

- minimiser adresse, téléphone et commentaires ; définir rétention et purge ;
- séparer permissions catalogue, stock, préparation, remboursement et config ;
- conserver `view own commerce_order` et refuser par défaut l’accès aux
  commandes, expéditions/suivis, fichiers facture/reçu et routes de téléchargement
  d’autrui ; tester aussi les contextes de cache ;
- ne donner au rôle éditeur générique aucune permission boutique implicite ;
  séparer catalogue, stock, fulfilment, remboursement et configuration paiement ;
- limiter mutations panier, création de checkout/paiement et récupération de
  commande invitée ; ne pas décider sur la seule IP, préserver les retries de
  webhooks vérifiés, définir signaux, rétention et autorité de revue manuelle ;
- uploads image : extensions/MIME autorisés, décodage réel, limites de poids et
  dimensions, noms générés/normalisés côté serveur, réencodage raster,
  métadonnées supprimées si nécessaire, SVG refusé initialement, texte alternatif
  requis, permission d’upload minimale et quarantaine antimalware selon le risque ;
- stock : le contrôle panier est indicatif ; avant une capture externe, réserver
  avec une mutation conditionnelle `disponible >= quantité` sous transaction ou
  verrou, une réservation unique par ligne et un TTL borné ; expiration,
  annulation et paiement passent par la même transition verrouillée/idempotente,
  rechargent le paiement sous verrou et donnent priorité au paiement confirmé ou
  à un état explicite `paid_needs_attention`, jamais à une survente silencieuse ;
  un remboursement ne remet rien en stock avant inspection et décision tracée ;
- toutes les actions admin sensibles portent auteur, date, motif et avant/après.

## 12. Roadmap en petites PR sûres

| PR | Périmètre unique | Critère de sortie |
| --- | --- | --- |
| 1. Sécurité et secrets | rotation/révocation PayPal, nettoyage config/historique coordonné, stockage externe, scan | aucun secret actif/versionné ; procédure de rotation prouvée |
| 2. Décision livraison/stock | spike comparatif Shipping/Physical/Stock, compatibilité, modèle de concurrence | ADR validé, aucune configuration métier de production |
| 3. Types physiques | produit, variation, ligne, commande, workflow, résolution du magasin/devise et ledger/réservation de stock retenus en PR 2 | export minimal sans produit, mutation de stock atomique testée, aucun effet sur les bundles existants |
| 4. Catalogue et médias | catégories validées, champs image/galerie/mesures, displays | fixtures de test uniquement, upload sûr, aucun vrai produit sans brief |
| 5. Séparation panier/régressions | types physiques dédiés, séparation CartProvider par type/magasin vérifiée, resolver custom seulement si un test prouve un manque | paniers mixtes refusés/séparés, tests droits/réservation/calendrier et compatibilité PR #90 |
| 6. Checkout/livraison | magasin/devise actifs, catégories/règles/inclusion de taxe, adresse, zones, packing, méthodes et retrait validés | matrices taxes/pays/poids/surdimensionné testées, aucun tarif inventé |
| 7. Paiement/webhooks | gateway scopée, signature, idempotence, finalisation avec réservation de stock déjà disponible | doublons/retards/échecs/courses paiement-stock testés ; gate sécurité verte |
| 8. Compte/commandes | « Mes commandes », états, adresse, suivi réel, documents autorisés | isolation propriétaire et commandes invitées testées |
| 9. E-mails | placé, payé, expédié, annulé/remboursé selon données réelles | contenus/expéditeurs validés, aucun faux délai/suivi |
| 10. SEO/sitemap | routes validées, métadonnées, canonicals, produits/catégories | dépendance PR #82 résolue, brouillons exclus |
| 11. Déploiement production | répétition staging, sauvegarde, runbook, monitoring, rollback | go/no-go signé, stock/tarifs/taxes/support prêts |

Chaque PR doit documenter prérequis, commandes exactes de staging, sauvegarde,
rollback, tests et dépendances sur les PR ouvertes. Aucune PR ne doit modifier
DNS, routage ou URL publique sans validation dédiée.

## 13. Plan d’acceptation futur

Commerce : types et paniers isolés, calcul serveur, taxes/ajustements exacts,
régressions cours/billets. Sécurité : contrôle d’accès, webhook, idempotence,
secrets, uploads et fraude. Fulfilment : stock concurrent, packing, zones,
surdimensionné, pickup, suivi et exceptions ; tester notamment étiquette sans
remise, colis multiples dans des états mixtes, preuve suivie, politique non
suivie, retrait enregistré et refus d’annulation après remise. UX : clavier,
focus, erreurs, mobile, indisponibilité, absence de dark pattern. Exploitation :
préparation, retour, perte, rapprochement, support, rollback et monitoring.

## Annexe A — PR ouvertes et fichiers au 1er septembre 2026

Inventaire destiné à la coordination ; aucune de ces branches n’est intégrée à
la baseline de cet audit.

| PR | Fichiers |
| --- | --- |
| #99 | `docs/functional/auth-account-experience-implementation-2026.md`; `drupal/web/themes/custom/unisonges_theme/css/auth-account.css`; `drupal/web/themes/custom/unisonges_theme/unisonges_theme.libraries.yml`; `drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme` |
| #98 | `docs/functional/background-motion-2026.md`; `drupal/web/themes/custom/unisonges_theme/js/bgfx-scroll-11.js` |
| #97 | `docs/design/home-editorial-blog-2026.md`; `docs/prototypes/home-editorial-blog/index.html`; `docs/prototypes/home-editorial-blog/prototype.css` |
| #96 | `docs/design/auth-account-experience-2026.md`; `docs/prototypes/auth-account-experience/index.html`; `docs/prototypes/auth-account-experience/prototype.css`; `docs/prototypes/auth-account-experience/prototype.js` |
| #95 | `docs/functional/interactive-text-contrast-2026.md`; `drupal/web/themes/custom/unisonges_theme/css/styles.css` |
| #94 | `docs/functional/public-footer-foundation-2026.md`; `drupal/web/themes/custom/unisonges_theme/templates/includes/_footer.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/page--front.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/page.html.twig` |
| #92 | `docs/functional/public-hub-components-2026.md`; `drupal/web/themes/custom/unisonges_theme/templates/content/node--10.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/content/node--6.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/content/node--9.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/includes/_card-grid.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/includes/_public-hub-actions.html.twig` |
| #90 | `docs/functional/cart-ux-integration-2026.md`; `drupal/config/sync/views.view.commerce_cart_form.yml`; `drupal/scripts/apply-cart-ux-2026.sh`; `drupal/web/themes/custom/unisonges_theme/templates/commerce/commerce-cart-empty-page.html.twig` |
| #89 | `docs/functional/concert-hub-upcoming-events-2026.md`; `drupal/config/sync/block.block.unisonges_hub_concerts_posts.yml`; `drupal/config/sync/views.view.hub_concerts_posts.yml`; `drupal/scripts/apply-concert-hub-upcoming-events-2026.sh` |
| #88 | `README.md`; `docs/functional/legacy-cloudflare-pages-retirement-2026.md`; `public/_headers`; `public/_redirects`; `public/robots.txt`; `public/sitemap.xml` |
| #87 | `docs/functional/content-architecture-2026.md`; `drupal/scripts/apply-content-architecture-2026.sh` |
| #86 | `docs/functional/reservation-entry-cleanup-2026.md`; `drupal/web/themes/custom/unisonges_theme/templates/content/node--8.html.twig` |
| #85 | `docs/functional/contact-form-mvp-2026.md`; `drupal/config/sync/block.block.unisonges_contact_form.yml`; `drupal/config/sync/webform.webform.contact.yml`; `drupal/scripts/apply-contact-form-mvp-2026.sh`; `drupal/scripts/contact-form-mvp-config.php` |
| #82 | `docs/functional/sitemap-robots-policy-2026.md`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.article.yml`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.concert.yml`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.forum_topic.yml`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.page.yml`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.stage.yml`; `drupal/config/sync/simple_sitemap.custom_links.default.yml`; `drupal/config/sync/simple_sitemap.settings.yml`; `drupal/config/sync/simple_sitemap.type.default_hreflang.yml`; `drupal/scripts/apply-sitemap-policy-2026.sh`; `drupal/web/robots.txt` |

Recouvrements prioritaires : #90 avant le panier physique, #99/#96 avant le
compte, #82 avant le SEO, et #95/#92 avant toute déclinaison du prototype dans
le thème. Les chemins créés par cette PR de design sont distincts de tous les
fichiers ouverts ci-dessus.

## Annexe B — preuves principales du dépôt

- modules : `drupal/config/sync/core.extension.yml` ;
- dépendances : `drupal/composer.json`, `drupal/composer.lock` ;
- types : `commerce_product.commerce_product_type.*.yml` et
  `commerce_product.commerce_product_variation_type.*.yml` ;
- commande/checkout : `commerce_order.*.yml`,
  `commerce_checkout.commerce_checkout_flow.*.yml` ;
- passerelles : fichiers `commerce_payment_gateway.*.yml`, valeurs sensibles
  volontairement exclues de l’audit ;
- adresse : `profile.type.customer.yml` et
  `field.field.profile.customer.address.yml` ;
- panier/compte : `views.view.commerce_cart_form.yml` et
  `views.view.commerce_user_orders.yml` ;
- séparation métier :
  `drupal/web/modules/custom/unisonges_structure/unisonges_structure.module` ;
- navigation : `docs/functional/content-architecture-2026.md` et
  `docs/functional/navigation-submenus-2026.md` ;
- incident paiement : `docs/functional/online-payment-slot-handoff-2026.md`.
