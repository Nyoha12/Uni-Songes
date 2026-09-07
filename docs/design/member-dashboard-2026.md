# Extension du compte membre — contrat de données et plan 2026

- Date de l’audit : 2 septembre 2026
- Branche : `codex-design-member-dashboard`
- Base vérifiée : `origin/release/prod` au commit
  `8cc82f9af6899aedc14490931c415293d0bdf0cb`
- Héritage visuel audité : fusion de la PR #99 au commit
  `5b8e80c2e2ac266978ba2be0b8eee2c56a04605f`
- Portée : conception, contrat de données, prototype statique et planification
  uniquement

## Décision synthétique

L’extension doit rester sur la route Core propriétaire déjà existante
`entity.user.canonical` et être composée de fragments à accès propriétaire. Elle
ne doit créer aucune URL. Six sections seulement sont retenues :

1. Mon compte ;
2. Mes réservations ;
3. Mes séances ou droits utilisables ;
4. Mes commandes ;
5. Mes propositions ;
6. Mes contributions.

Le dépôt permet un résumé utile, mais pas un historique métier complet. Une
soumission Webform porte l’état courant d’un créneau, un compte utilisateur porte
un solde agrégé, Commerce porte les commandes, et deux tables internes portent
respectivement les droits à régler sur place et la synchronisation Google. Ces
sources ne doivent jamais être aplaties en un unique « statut » optimiste.

Le prototype associé utilise exclusivement des personnes, contenus, dates,
commandes et montants fictifs. Il ne démontre aucune capacité d’annulation, de
déplacement, de téléchargement de facture ou de suivi.

## Méthode et niveau de preuve

L’audit est statique. Il couvre le code, les schémas, la configuration exportée,
les scripts et les notes fonctionnelles du dépôt. Il n’interroge aucune base
active, aucun VPS et aucun service externe. DDEV, Docker, Drush et Chromium n’ont
pas été utilisés.

Les qualificatifs suivants s’appliquent à tout ce document :

- **vérifié dépôt** : démontré par une source versionnée sur la base auditée ;
- **propriétaire affichable** : donnée dont la restitution au compte propriétaire
  est légitime, après contrôle serveur et échappement ;
- **administration seulement** : donnée actuellement protégée par un accès
  administratif, ou trop sensible/technique pour le résumé membre ;
- **marqueur interne** : utile au traitement, jamais à rendre littéralement ;
- **ambigu/non fiable** : ne permet pas à lui seul le libellé métier envisagé ;
- **absent** : aucune source actuelle ne porte la donnée.

L’état actif peut diverger de `config/sync`. Toute future PR d’implémentation
devra refaire un audit contrôlé de la base cible avant activation.

## Héritage visuel de la PR #99

La PR #99 limite déjà la présentation propriétaire aux routes
`entity.user.canonical` et `entity.user.edit_form`, exige que l’ID du compte de la
route soit celui de la personne connectée, et varie le rendu par `route` et
`user` (`unisonges_theme.theme:243-268`, `:473-504`). Son commentaire réserve
explicitement les futurs résumés à un bloc lazy-built contrôlé par accès, hors du
render cache de l’entité utilisateur (`:502-504`).

La direction à prolonger vient de `css/auth-account.css:3-31` et `:43-116` :

- surface chaude opaque `#fffaf2`/`#fffdf8` ;
- texte `#102033`, accent `#0f766e`, survol `#075b55` ;
- hiérarchie plate, bordures discrètes, ombre faible ;
- police système, H1 mesuré et focus clavier de 3 px ;
- contrôles de 44 px minimum et reflow à 320 px ;
- traitement explicite des couleurs forcées.

L’extension élargira la surface, mais ne la transformera pas en grille de cartes
statistiques. Le nom de section, les données et l’action sûre associée doivent
rester le premier niveau de lecture.

## Modèle de données réellement disponible

### Compte utilisateur et profil client

| Source exacte                                | Sens                                        | Classement et décision d’affichage                                                                                                                                                                                                                                                                                                |
| -------------------------------------------- | ------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `user` : `uid`                               | Propriétaire technique                      | Marqueur interne. Comparer au compte courant ; ne pas afficher.                                                                                                                                                                                                                                                                   |
| `user` : `name`                              | Nom d’utilisateur Core                      | Propriétaire affichable. Ce n’est pas un prénom/nom civil.                                                                                                                                                                                                                                                                        |
| `user` : `mail`                              | Adresse de compte                           | Propriétaire affichable dans « Mon compte », jamais dans un cache partagé.                                                                                                                                                                                                                                                        |
| `user` : `created`                           | Date de création                            | Propriétaire affichable comme « Membre depuis ». Ce n’est pas un niveau d’adhésion.                                                                                                                                                                                                                                               |
| `user` : `timezone`                          | Fuseau préféré                              | Affichable si renseigné ; ne remplace pas le fuseau métier du créneau.                                                                                                                                                                                                                                                            |
| `user.user_picture`                          | Image facultative                           | Propriétaire affichable, avec texte alternatif généré par Drupal. Config : `field.field.user.user.user_picture.yml:13-37`.                                                                                                                                                                                                        |
| `user.field_seances_restantes`               | Solde agrégé de droits utilisables          | Affichable en lecture seule dans la section droits. Issu du flux payé, mais non réconcilié après remboursement ; ce n’est pas une preuve financière par unité. Entier cardinalité 1 (`field.storage.user.field_seances_restantes.yml:7-16`), minimum de formulaire 0 (`field.field.user.user.field_seances_restantes.yml:19-20`). |
| `user.field_pack_expire_le`                  | Date agrégée susceptible de borner le solde | Affichable avec le solde, jamais comme une échéance par crédit. Des crédits pack et hors pack partagent le même agrégat (`field.storage.user.field_pack_expire_le.yml:8-16`).                                                                                                                                                     |
| `user.field_essai_utilise`                   | Drapeau d’éligibilité à l’essai             | Propriétaire affichable en théorie, mais sémantique ambiguë : il peut être posé avant toute présence au cours. L’omettre du résumé initial et ne pas en faire un niveau.                                                                                                                                                          |
| `user.commerce_remote_id`                    | Identifiants distants de paiement           | Administration seulement ; le display utilisateur le masque (`core.entity_view_display.user.user.default.yml:65-68`).                                                                                                                                                                                                             |
| `profile` bundle `customer`, champ `address` | Profil d’adresse Commerce                   | Donnée propriétaire sensible, mais pas une adresse de compte canonique : le bundle autorise plusieurs profils et n’est pas utilisé à l’inscription (`profile.type.customer.yml:13-20`). La laisser au détail de commande existant.                                                                                                |

L’image est stockée sous `public://` : son champ est propriétaire, mais son URL
de fichier n’est pas confidentielle lorsqu’elle est connue
(`field.storage.user.user_picture.yml:19`). Le display utilisateur actuel rend
l’image, l’ancienneté et les trois champs de
droits, mais masque `commerce_remote_id` et `customer_profiles`
(`core.entity_view_display.user.user.default.yml:18-68`). Le hook d’accès interdit
déjà à un non-administrateur d’éditer les trois champs de droits
(`unisonges_structure.module:63-89`). Le futur tableau de bord doit préserver
cette séparation : lecture synthétique d’un côté, édition du vrai formulaire
Core de l’autre.

Le hook protège l’édition, pas la vue. Les rôles non administratifs exportés
n’ont actuellement pas `access user profiles`, mais le display par défaut porte
les champs de droits. Un futur changement de permission pourrait donc les
exposer sur un profil tiers. La garde propriétaire explicite et un test de
non-régression restent obligatoires, indépendamment du display de champ.

Valeurs absentes : prénom, nom civil, téléphone de profil, niveau de membre,
points de fidélité et préférence de communication. Le téléphone existe seulement
dans chaque réservation et ne doit pas être promu en téléphone de compte.

### Réservations de cours

La source fonctionnelle est l’entité `webform_submission` dont `webform_id` vaut
exactement `cours_particuliers_reservation` et dont `uid` est le propriétaire.
Les données d’élément sont dans le stockage Webform, notamment :

| Clé d’élément exacte             | Sens                                           | Affichage propriétaire                                                                                                                      |
| -------------------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `reservation`                    | Valeur `YYYY-MM-DD HH:MM\|N`                   | Parser côté serveur. Afficher date/heure et, si utile, le nombre de places. Ne jamais afficher la valeur brute.                             |
| `mode_cours`                     | `visio`, `studio` ou `domicile`                | Afficher le libellé d’option traduit, pas la clé.                                                                                           |
| `plateforme_visio`               | Plateforme choisie                             | Affichable seulement si le mode vaut `visio`.                                                                                               |
| `adresse_domicile`               | Adresse libre                                  | Propriétaire affichable mais sensible ; omise du résumé.                                                                                    |
| `code_postal_domicile`           | Code postal                                    | Propriétaire affichable mais sensible ; omis du résumé.                                                                                     |
| `telephone`                      | Téléphone de la réservation                    | Propriétaire affichable mais sensible ; omis du résumé.                                                                                     |
| `instrument`                     | Guimbarde ou didgeridoo                        | Afficher le libellé d’option.                                                                                                               |
| `didgeridoo_pret`                | Besoin de prêt                                 | Affichable si pertinent, pas nécessaire au résumé initial.                                                                                  |
| `niveau_cours`                   | Niveau choisi                                  | Afficher le libellé d’option.                                                                                                               |
| `notes_supplementaires`          | Notes libres                                   | Sensible ; détail éventuel seulement, jamais extrait non filtré.                                                                            |
| `unisonges_payment_choice`       | Marqueur ajouté par le tunnel sur place        | Interne, hors schéma d’éléments exporté. Ne pas afficher la clé/valeur.                                                                     |
| `unisonges_pay_on_site_order_id` | ID numérique de commande lié au flux sur place | Interne. Résoudre la commande, vérifier ses deux propriétaires, afficher son numéro métier.                                                 |
| `unisonges_course_label`         | Snapshot ajouté par le tunnel sur place        | Ambigu pour les autres entrants et absent du Webform exporté ; l’utiliser seulement après validation, sinon retomber sur instrument/niveau. |

Le Webform définit un créneau de 60 minutes, une place globale, une place maximum
par réservation et 30 jours visibles
(`webform.webform.cours_particuliers_reservation.yml:16-39`). Les détails exacts
sont déclarés aux lignes 40-140. Le tunnel normalise la valeur aux lignes
`ReservationFirstCourseTunnelForm.php:1622-1652`.

Les propriétés de base `sid`, UUID, adresse IP, `in_draft`, `sticky`, `locked` et
URI sont administration seulement ou techniques. `created`, `changed` et
`completed` sont des horodatages Webform ; `completed` ne signifie ni cours
effectué, ni paiement effectué, ni validation humaine. La configuration accepte
les soumissions complètes, n’a pas de brouillon, conserve les résultats sans
purge et stocke l’adresse distante (`:159-163`, `:239-269`). Ces deux dernières
données ne doivent pas être reproduites dans le compte.

#### Limite décisive du pseudo-statut de réservation

Le parseur considère `N = 0` comme annulé (`unisonges_structure.module:2549-2594`).
Cependant le même suffixe `|0` est écrit automatiquement après un conflit de
capacité, un verrou de droits indisponible ou l’absence de droit au moment de
l’insertion (`:2350-2421`), et peut aussi servir de repli après une mise à jour
conflictuelle (`:2436-2477`). La valeur seule conflue donc :

- une annulation réelle ;
- une réservation refusée après conflit ;
- une réservation invalidée faute de droit ;
- certains replis techniques.

Le compte ne doit pas traduire `|0` par « Annulée ». Le libellé prudent est
« Non active » ou la ligne est omise avec diagnostic administratif. Il n’existe
aucun champ durable indiquant qui a annulé, pourquoi, quand, ni le créneau
précédent. Il n’existe pas non plus d’état « effectuée », « absent », « confirmée
par l’équipe » ou « échouée ».

La valeur active (`N > 0`) prouve seulement qu’une soumission courante occupe le
créneau. Elle ne constitue pas un historique. Les logs de transition ne sont pas
une source de tableau de bord (`:2487-2528`).

### Commandes Commerce, lignes, paiements et propriétaires

Le type de commande `default` utilise le workflow Commerce `order_default` et le
reçu client (`commerce_order.commerce_order_type.default.yml:7-18`). Les sources
affichables sont :

Une « commande de cours » n’est ni un bundle de commande ni un drapeau. Elle se
reconnaît en parcourant `commerce_order.order_items`, puis
`commerce_order_item.purchased_entity` jusqu’au produit parent, dont le bundle
appartient à l’allowlist `cours_essai`, `cours_deb_inter`, `cours_avance` ou
`pack_4_deb_inter` (`unisonges_structure.module:919-989`). La vue propriétaire
existante montre, elle, toutes les commandes non draft, y compris les autres
types de produits ; « Mes commandes » conserve ce périmètre général tandis que
le calcul des droits reste strictement limité aux quatre bundles de cours.

| Entité/champ exact                              | Usage propriétaire                                                                                                                           |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `commerce_order.uid` / `getCustomerId()`        | Jointure propriétaire obligatoire. Elle doit égaler le compte courant, en plus de l’accès entité.                                            |
| `commerce_order.order_number`                   | Numéro métier affichable lorsqu’il existe. Une commande annulée avant placement peut ne pas en avoir ; ne jamais rendre `order_id` en repli. |
| `commerce_order.placed`                         | Date de commande affichable lorsqu’elle existe. Elle est nullable avant placement.                                                           |
| `commerce_order.created`                        | Repli affichable pour une commande jamais placée, avec le libellé exact « Créée le », jamais « Commandée le ».                               |
| `commerce_order.state`                          | État du workflow à mapper, jamais à afficher brut.                                                                                           |
| `commerce_order.total_price`                    | Total et devise affichables par le formateur monétaire Commerce.                                                                             |
| `commerce_order.total_paid` / `isPaid()`        | Signal agrégé utile au libellé de paiement ; ne pas assimiler à `state=completed`.                                                           |
| `commerce_order.payment_gateway`                | Résoudre seulement pour distinguer le paiement sur place. Afficher éventuellement le libellé humain, jamais l’ID du plugin.                  |
| `commerce_order.order_items`                    | Charger seulement après accès à la commande.                                                                                                 |
| `commerce_order_item.title`                     | Snapshot de titre affichable, préférable au titre actuel du produit.                                                                         |
| `commerce_order_item.quantity`                  | Quantité affichable.                                                                                                                         |
| `commerce_order_item.unit_price`, `total_price` | Prix affichables si le détail le demande.                                                                                                    |
| `commerce_order_item.purchased_entity`          | Référence technique ; lien produit seulement si l’entité est encore publiée et accessible.                                                   |
| `commerce_order.billing_profile`                | Adresse sensible, déjà prévue par le display de détail utilisateur ; ne pas la dupliquer dans le résumé.                                     |

La vue existante `commerce_user_orders` est déjà propriétaire : permission
`view own commerce_order`, argument `uid` validé par `commerce_current_user`,
exclusion de `draft`, 25 éléments et route existante `user/%user/orders`
(`views.view.commerce_user_orders.yml:357-463`, `:569-595`). Elle prouve qu’une
action « Voir la commande » est réelle. Le nouveau résumé peut réutiliser ce
contrat, avec un nombre plus compact, sans créer de route.

Dans le workflow versionné, `canceled` est atteint depuis `draft`. Une telle
commande peut donc avoir `order_number=NULL` et `placed=NULL`. Le composant doit
accepter les deux absences, utiliser éventuellement `created` avec son vrai
libellé et ne jamais exposer `order_id` comme faux numéro de commande.

Les entités `commerce_payment` ne sont pas une source directe du compte : la vue
de paiements exige `administer commerce_payment`
(`views.view.commerce_order_payments.yml:667-687`). Montant, remboursement et
état pourraient être compréhensibles, mais l’accès actuel ne les autorise pas au
membre. Les champs `remote_id`, code AVS, identifiant de passerelle, opérations,
horodatages d’autorisation et erreurs sont administration seulement. Le résumé
doit dériver un texte minimal de la commande, pas contourner l’accès aux
paiements.

La passerelle manuelle prouve le mode « paiement sur place », pas à elle seule
qu’un montant reste dû. Pour écrire « à régler sur place », il faut en plus des
lignes propriétaires cohérentes encore `pending_payment` ou `consumed`. Une
commande manuelle non payée dont les lignes sont déjà `paid` est compatible avec
un remboursement non réconcilié : afficher seulement « Règlement à vérifier »
et produire un diagnostic administratif.

Le profil client et la commande ont des liens de propriété différents. L’ordre
appartient par `commerce_order.uid`; son `billing_profile` est un snapshot/révision
attaché à cet ordre. Un profil `customer` partageant le même propriétaire ne
suffit jamais à autoriser une commande.

### Crédits payés et droits à régler sur place

Deux modèles coexistent.

#### Solde issu des commandes payées, agrégé et non réconcilié

`field_seances_restantes` est incrémenté uniquement pour une commande
`completed` et `isPaid()`, puis décrémenté à chaque réservation utilisant un
crédit payé (`unisonges_structure.module:1390-1510`, `:2023-2061`). Le pack
`pack_4_deb_inter` ajoute quatre crédits par quantité et prolonge
`field_pack_expire_le` de six mois. Les cours unitaires ajoutent une unité ; le
cours d’essai est plafonné à une unité.

Aucune logique inverse versionnée ne retire ces unités après remboursement ou
après perte ultérieure de `isPaid()`. Ce champ reste la source opérationnelle de
ce que le code autorise à réserver, mais ne prouve pas que chaque unité est
encore financièrement « réglée ». Le libellé membre initial doit donc être
« Séances disponibles », sans promesse de provenance de paiement.

Le compte peut afficher :

- le nombre utilisable, borné visuellement à zéro ;
- « Valable jusqu’au… » lorsque la date existe et n’est pas passée ;
- « Validité échue le… » et **zéro utilisable** lorsque la date est antérieure à
  aujourd’hui.

La logique actuelle considère la date elle-même encore valide (`expiry >= today`,
`:2032-2045`). Si un solde brut positif subsiste avec une date passée, il est
ambigu et ne doit pas être annoncé comme disponible. Le nombre brut devient un
diagnostic d’administration.

Il n’existe aucun ledger de crédit payé. Sont donc absents : commande source par
crédit, date d’acquisition par unité, date/raison de consommation, réservation
ayant consommé chaque unité et historique du solde. Un tableau « vos quatre
séances, dont trois consommées » serait inventé.

#### Ledger paiement sur place

La table `unisonges_structure_course_to_pay_right` est déclarée dans
`unisonges_structure.install:110-214`. Elle contient exactement :

- `id` : clé technique ;
- `order_id`, `source_order_item_id`, `credit_index` : provenance technique et
  clé d’unicité par unité ;
- `uid` : propriétaire ;
- `product_bundle` : snapshot technique du type de produit ;
- `remaining_to_pay_credits` : `1` uniquement tant que l’unité peut réserver ;
- `webform_submission_id` : réservation consommatrice éventuelle ;
- `status` : `pending_payment`, `consumed`, `paid` ou `cancelled` ;
- `created`, `changed`, `consumed`, `paid`, `cancelled` : horodatages d’audit.

Le libellé interne `COURS À PAYER` ne doit jamais atteindre le compte. Mappages :

| État réel                                                           | Sens vérifié                                                                                 | Libellé membre autorisé                                                                         |
| ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `pending_payment` + `remaining_to_pay_credits=1`                    | Une réservation peut encore consommer l’unité, paiement sur place non confirmé               | « 1 séance disponible · à régler sur place »                                                    |
| `consumed` + SID propriétaire                                       | L’unité porte déjà une réservation, paiement encore attendu                                  | Dans la réservation : « Réservée · à régler sur place ». Ne plus compter comme utilisable.      |
| `paid` + SID + commande source propriétaire actuellement `isPaid()` | L’unité utilisée a été payée ensuite et son paiement courant est encore cohérent             | Dans la réservation : « Réservée · réglée ».                                                    |
| `paid` + SID sans commande propriétaire actuellement payée          | Ligne potentiellement orpheline ou périmée après remboursement                               | Aucun qualificatif financier ; diagnostic administration.                                       |
| `paid` sans SID                                                     | L’unité a été transférée dans le solde agrégé, sans réconciliation future des remboursements | Masquer la ligne brute ; le solde utilisateur reste la source d’utilisabilité, pas de paiement. |
| `cancelled`                                                         | La commande sur place annulée a retiré une unité encore pending                              | « Droit annulé » seulement dans un futur détail métier ; ne pas compter.                        |
| état inconnu, propriétaire incohérent ou source item étrangère      | Donnée non prévue                                                                            | Aucun libellé optimiste ; diagnostic administration.                                            |

Les créations `pending_payment` viennent uniquement des commandes de cours
`completed`, manuelles et non payées (`unisonges_structure.module:1678-1747`).
La consommation choisit le plus ancien droit disponible, trié par `created`, puis
`id` (`:2064-2103`). Le paiement tardif transforme les lignes en `paid`, conserve
les SID consommés et transfère seulement les unités non consommées au solde
(`:1836-1985`).

Une sauvegarde ultérieure d’une commande qui n’est plus payée ne réinitialise ni
les lignes déjà `paid`, ni le solde utilisateur. Le croisement courant avec la
commande et `isPaid()` est donc obligatoire avant le mot « réglée » ; il ne
répare pas pour autant les unités déjà transférées au solde.

### États Google Calendar

`unisonges_structure_booking_gcal_sync` est une table interne, pas Drupal Queue
API (`unisonges_structure.install:12-108`). Une seule ligne est fusionnée par SID
et UUID. Les champs sont `id`, `sid`, `submission_uuid`, `google_event_id`,
`sync_status`, `sync_action`, `reservation_value`, `payload_json`, `last_error`,
`created`, `changed`, `last_synced` et `cancelled`.

Les actions admises sont `pending_create`, `pending_update` et `pending_cancel`.
Les statuts observés sont `pending`, `synced`, `skipped` et `error`
(`BookingCalendarSyncService.php:17-23`, `:260-273`, `:317-360`). Une erreur Google
ne change pas l’état de la réservation Drupal.

Tout ce magasin est administration seulement pendant les étapes 1 à 6 :

- `id`, SID, UUID, `google_event_id`, action et valeur brute sont techniques ;
- `payload_json` contient notamment nom d’affichage, téléphone, adresse/plateforme,
  notes et IDs Commerce : donnée personnelle sensible ;
- `last_error` peut contenir un diagnostic fournisseur ;
- `skipped` inclut le dry-run et ne signifie pas « synchronisé ».

Les défauts d’installation sont `enabled=false` et `dry_run=true`, sans export
actuel de l’objet de configuration (`unisonges_structure.install:237-255` et
`docs/dev/google-calendar-sync-plan.md:69-99`). Le moteur n’a ni lease, ni retry
automatique, ni compteur de tentatives. Aucun statut Google ne doit apparaître
au membre avant la phase 7. Après fiabilisation, seuls deux messages génériques
sont envisageables : « Mise à jour de l’agenda en attente » et « Agenda non mis à
jour ; votre réservation reste enregistrée ». Les IDs, erreurs brutes et noms de
queue restent interdits.

### Propositions Forum/Blog

La source est `webform_submission` avec
`webform_id=forum_blog_proposal` et `uid` propriétaire. Les seules valeurs métier
sont `proposal_type`, `title` et `description`
(`webform.webform.forum_blog_proposal.yml:15-39`). Le type appartient à l’allowlist
`idea`, `discussion_topic`, `article_theme`; le compte affiche les libellés
« Idée », « Sujet de discussion », « Thème d’article ».

La soumission reste privée, n’a aucun handler et ne crée aucun contenu
(`:145-176`, `:223`). L’adresse IP est désactivée (`:57-61`). L’accès actuel
`view_own`, `update_own` et `delete_own` est vide (`:177-222`) : un résumé futur
nécessite une frontière de lecture dédiée, pas l’ouverture des pages de résultats
Webform.

Une soumission existante autorise seulement « Proposition reçue ». Les timestamps
Webform ne signifient ni « acceptée », ni « refusée », ni « publiée ». Il n’existe
aucun statut de modération, message du réviseur, date de décision ou référence
vers un Article/sujet créé. Aucun lien ne doit être inféré par titre.

### Sujets et commentaires

Le type `node:forum_topic` est révisionné, créé non publié et décrit comme publié
par un administrateur après examen (`node.type.forum_topic.yml:5-11`,
`core.base_field_override.node.forum_topic.status.yml:7-21`). Les champs utiles
sont les propriétés Core `nid`, `uid`, `title`, `status`, `created`, `changed`,
plus `body` et `comment`.

Un non-administrateur ne peut jamais voir un sujet Forum non publié, même s’il en
est l’auteur (`unisonges_structure.module:31-60`). Les Views non administratives
retirent aussi tout sujet non publié et varient par permissions (`:92-116`). La
vue Forum publique filtre `type=forum_topic`, `status=1`, trie `created DESC`,
page par 10 et conserve les contextes de grants/permissions
(`views.view.forum_topics.yml:24-90`).

Le type `comment` cible les nodes. `comment_body` est obligatoire et limité à
`basic_html` (`field.field.comment.comment.comment_body.yml:13-26`). Les champs
utiles sont `cid`, `uid`, `status`, `created`, `changed`, `subject`,
`comment_body`, `entity_type`, `entity_id`, `field_name`, `pid` et `thread`. Les
deux champs commentaires Article et Forum sont ouverts, 50 par page, sans saisie
d’identité anonyme (`field.field.node.article.comment.yml:20-34`,
`field.field.node.forum_topic.comment.yml:18-32`). La configuration globale ne
journalise pas les IP de commentaire (`comment.settings.yml:1-3`).

Le rôle authentifié peut lire/poster et sauter l’approbation, mais ne possède
aucune permission d’édition, suppression ou administration de commentaire
(`user.role.authenticated.yml:25-38`). Le compte peut donc résumer uniquement :

- ses commentaires publiés, `comment.uid = current_user`, dont le parent est
  publié et accessible ;
- ses éventuels sujets Forum publiés, `node.uid = current_user`, après
  `node.access('view')`.

Il ne faut pas présenter un sujet créé depuis une proposition comme appartenant
à son auteur : aucun lien entre les deux n’existe et le workflow documenté fait
créer le node par un administrateur. Les commentaires non publiés, opérations,
révisions de sujet, fil technique, IP historiques éventuelles et notes de
modération sont administration seulement.

## Contrat des six sections

Chaque bloc est une lecture. Tous les paramètres propriétaire viennent du compte
courant authentifié, jamais d’un UID fourni par un query string. Sur la route
Core, l’UID de route doit aussi égaler l’UID courant. Toute entité chargée subit
son contrôle `view` normal, même après un premier filtre SQL.

### 1. Mon compte

| Propriété            | Contrat                                                                                                                                                                                    |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Sources              | Entité `user` courante : `name`, `mail`, `created`, `timezone`, `user_picture`. Les droits sont retirés de ce bloc et présentés dans la section 3.                                         |
| Accès propriétaire   | Utilisateur authentifié ; `route user.id === current_user.id`; `user.access('view')`. La bibliothèque de PR #99 ne s’attache jamais à un profil tiers.                                     |
| État vide            | L’image et le fuseau peuvent manquer ; ne pas créer d’avatar distant. `name`, `mail` et date Core restent la base. Si l’entité ne charge pas, afficher une erreur générique sans identité. |
| Ordre                | Image facultative, nom d’utilisateur, courriel, membre depuis, fuseau facultatif.                                                                                                          |
| Pagination           | Aucune : entité singleton.                                                                                                                                                                 |
| Cache                | Contexte `user`, `route`, `user.permissions`, langue d’interface ; tag `user:{uid}`. Ne jamais utiliser seulement `user.roles`.                                                            |
| Actions autorisées   | « Modifier mon profil » vers `entity.user.edit_form`, uniquement après `Url::access($account, TRUE)` et propagation de cet `AccessResult` ; tâches locales Core existantes.                |
| Actions interdites   | Édition inline, modification des droits, changement de rôle, affichage de l’UID/remote ID, adresse client agrégée.                                                                         |
| Frontière vie privée | Courriel, image et fuseau restent dans le fragment propriétaire ; pas de données d’un profil client ou d’un autre utilisateur.                                                             |

### 2. Mes réservations

| Propriété            | Contrat                                                                                                                                                                                                                                                                                                                                  |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sources              | `webform_submission` avec `webform_id=cours_particuliers_reservation`, `uid=current_user`, `in_draft=false`; données `reservation`, `mode_cours`, `plateforme_visio`, `instrument`, `didgeridoo_pret`, `niveau_cours`; jointure facultative du droit sur place par SID.                                                                  |
| Accès propriétaire   | Garde route/courant, filtre UID obligatoire, allowlist de Webform, puis service de résumé dédié. Ne pas accorder `view_own` Webform globalement ni exposer la route canonique d’une submission.                                                                                                                                          |
| État vide            | « Vous n’avez aucune réservation active. » avec lien vers la route de réservation existante seulement si son URL est accessible. Ajouter « Aucun historique complet n’est disponible dans ce compte. »                                                                                                                                   |
| Ordre                | Réservations actives futures par début ASC puis SID ASC. Une valeur encore active mais passée est exclue du résumé initial et diagnostiquée, jamais nommée « effectuée ». Les valeurs `\|0`/invalides ne forment pas une liste historique.                                                                                               |
| Pagination           | 10 réservations actives par page, pager Core `element=0`; le query arg partagé `page` et le contexte précis du pager doivent buller. À la première phase, une limite de 10 sans « voir tout » est acceptable si aucun pager fiable n’est encore branché.                                                                                 |
| Cache                | Contextes `user`, `route`, `user.permissions`, langue d’interface, `url.query_args:page`, et fuseau si le formateur le requiert. Tags `webform_submission:{sid}`, `webform_submission_list`, `config:webform.webform.cours_particuliers_reservation`. Tant que les jointures table custom n’invalident aucun tag, fragment `max-age: 0`. |
| Actions autorisées   | Voir le résumé ; démarrer une nouvelle réservation via la route existante et son contrôle d’accès.                                                                                                                                                                                                                                       |
| Actions interdites   | Annuler, déplacer, modifier, dupliquer, marquer effectuée, télécharger un rendez-vous, forcer une synchro Google.                                                                                                                                                                                                                        |
| Frontière vie privée | Omettre téléphone, adresse et notes du résumé ; ne charger aucun SID d’un autre UID ; ne jamais rendre SID, UUID, clé brute, queue/Google ID ou libellé interne.                                                                                                                                                                         |

Une réservation active couverte par un droit `consumed` est « Réservée · à
régler sur place ». Avec une ligne `paid` + SID, une source item cohérente et une
commande propriétaire actuellement `isPaid()`, elle est « Réservée · réglée ».
Sans ligne sur place, le code courant suppose un crédit payé après consommation,
mais les données historiques n’ont pas de lien durable : le sous-libellé
« Réglée avec une séance » doit être activé seulement après audit/backfill des
submissions existantes. Sinon afficher simplement « Réservation active ».

### 3. Mes séances ou droits utilisables

| Propriété            | Contrat                                                                                                                                                                                                                                                                                                                     |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sources              | `user.field_seances_restantes`, `user.field_pack_expire_le`; nombre des lignes `unisonges_structure_course_to_pay_right` où `uid=current_user`, `status=pending_payment`, `remaining_to_pay_credits>0`.                                                                                                                     |
| Accès propriétaire   | Même garde user/route. Requête table custom paramétrée par UID courant. Si une commande source est présentée, exiger aussi `commerce_order.uid=current_user`, `order.access('view')` et l’appartenance du source order item à cette commande.                                                                               |
| État vide            | « Aucune séance utilisable pour le moment. » Lien vers les cours existants si accessible. Ne pas afficher une jauge à zéro.                                                                                                                                                                                                 |
| Ordre                | Ligne « Séances disponibles », puis « Séances à régler sur place », puis message de validité. Aucun détail unitaire payé, car il n’existe pas.                                                                                                                                                                              |
| Pagination           | Aucune pour les deux agrégats. Un futur détail des droits sur place serait paginé à 10, `created DESC, id DESC`, mais n’appartient pas à la fondation.                                                                                                                                                                      |
| Cache                | Contextes `user`, `route`, `user.permissions`, langue et fuseau de date. Tag `user:{uid}` pour le solde. La table custom n’a aucun cache tag : `max-age: 0` jusqu’à l’ajout d’un tag applicatif invalidé sur chaque écriture. Un futur fragment user seul expire au prochain minuit pour recalculer `field_pack_expire_le`. |
| Actions autorisées   | « Réserver un créneau » seulement si le calcul serveur `_user_can_book` et l’accès URL le permettent ; « Voir les cours ».                                                                                                                                                                                                  |
| Actions interdites   | Ajouter/transférer/restaurer un crédit, prolonger une date, payer une ligne depuis ce bloc, modifier `field_essai_utilise`, prétendre montrer les consommations.                                                                                                                                                            |
| Frontière vie privée | Ne jamais afficher IDs de droit/commande/item, `product_bundle`, index, timestamps internes ou verrous. Les compteurs sont strictement ceux du propriétaire.                                                                                                                                                                |

### 4. Mes commandes

| Propriété            | Contrat                                                                                                                                                                                                                                                                       |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sources              | `commerce_order` non draft : `order_number` et `placed` nullables, `created`, `state`, `total_price`, signal `isPaid()`/`total_paid`, `payment_gateway`, `order_items`; items : `title`, `quantity`, prix.                                                                    |
| Accès propriétaire   | Permission existante `view own commerce_order`, validateur `commerce_current_user`, `commerce_order.uid=current_user`/`getCustomerId()`, puis `order.access('view')`. Les quatre doivent rester cohérents.                                                                    |
| État vide            | « Vous n’avez encore passé aucune commande. » Lien vers les cours/offres existants si accessible.                                                                                                                                                                             |
| Ordre                | `placed DESC` avec valeurs nulles à la fin, puis `created DESC`, puis `order_id DESC` comme stabilisateur interne. Les drafts restent exclus.                                                                                                                                 |
| Pagination           | 10 par page dans le résumé, pager Core `element=1`. La page Commerce existante garde son pager de 25 ; « Voir toutes mes commandes » peut la cibler après contrôle d’accès.                                                                                                   |
| Cache                | Contextes `user`, `route`, `user.permissions`, langues contenu/interface, fuseau et `url.query_args:page`. Tags `commerce_order:{id}`, `commerce_order_list`, `commerce_order_item:{id}` et dépendances de devise ; `commerce_payment_gateway:{id}` si son libellé est rendu. |
| Actions autorisées   | Voir une commande et la liste propriétaire par les routes Commerce existantes, uniquement lorsque les URL passent leur contrôle d’accès.                                                                                                                                      |
| Actions interdites   | Télécharger une facture, suivre un colis, annuler/rembourser, repayer, modifier la commande ou ouvrir les opérations de paiement sans contrat existant.                                                                                                                       |
| Frontière vie privée | Ne pas afficher mail de commande, adresse, commentaire client, IP, remote ID, AVS, ID PayPal, payload/webhook ou opérations admin dans la liste.                                                                                                                              |

### 5. Mes propositions

| Propriété            | Contrat                                                                                                                                                                                        |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sources              | `webform_submission` où `webform_id=forum_blog_proposal`, `uid=current_user`, `in_draft=false`; éléments `proposal_type`, `title`, `description`; timestamp `created`.                         |
| Accès propriétaire   | Frontière de résumé dédiée et allowlistée. Le formulaire actuel refuse `view_own`; ne pas élargir les résultats Webform ni produire un lien canonique de submission.                           |
| État vide            | « Vous n’avez envoyé aucune proposition. » Action réelle « Proposer un sujet ou une idée » vers le formulaire embarqué sur le Forum existant, si accessible.                                   |
| Ordre                | `created DESC`, puis SID DESC interne.                                                                                                                                                         |
| Pagination           | 10 par page, pager Core `element=2`; le query arg `page` et le contexte précis du pager doivent buller.                                                                                        |
| Cache                | Contextes `user`, `route`, `user.permissions`, langue, pager. Tags `webform_submission:{sid}`, `webform_submission_list`, `config:webform.webform.forum_blog_proposal`; fragment propriétaire. |
| Actions autorisées   | Voir type, titre, date et un extrait échappé ; envoyer une nouvelle proposition depuis le vrai formulaire.                                                                                     |
| Actions interdites   | Modifier/supprimer/retirer une proposition, annoncer acceptation/refus/publication, créer automatiquement un node, lier par ressemblance de titre.                                             |
| Frontière vie privée | Les propositions ne deviennent jamais publiques. Description complète facultative derrière une disclosure locale, sans URL de résultat et sans notes admin.                                    |

### 6. Mes contributions

| Propriété            | Contrat                                                                                                                                                                                                                                                                     |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sources              | Commentaires publiés : `comment.uid=current_user`, `status=1`, parent `node` accessible de bundle `forum_topic` ou `article`; cas exceptionnel d’un sujet propre : `node.type=forum_topic`, `node.uid=current_user`, `status=1`. Le rôle membre ne peut pas créer de sujet. |
| Accès propriétaire   | Authentifié, UID exact, `comment.access('view')`, puis accès `view` au parent; pour un sujet, `node.access('view')`. Le filtre publié reste obligatoire pour les non-admins.                                                                                                |
| État vide            | « Vous n’avez encore aucune contribution publiée. » Lien vers le Forum existant si accessible. Ne pas dire que des contributions non publiées n’existent pas.                                                                                                               |
| Ordre                | Flux unifié par `created DESC`, puis type (`comment` avant `node`) et ID DESC comme stabilisateurs. Le type est rendu en clair.                                                                                                                                             |
| Pagination           | 10 entrées par page, pager Core `element=3`. Si l’union sûre est trop complexe, deux listes bornées avec deux nouveaux IDs distincts plutôt qu’une jointure fragile.                                                                                                        |
| Cache                | Contextes `user`, `route`, `user.permissions`, `user.node_grants:view`, langues, pager. Tags de chaque `comment:{cid}`/`node:{nid}` et tags de liste `comment_list`, `node_list`; dépendances des parents.                                                                  |
| Actions autorisées   | Ouvrir le sujet/article public ou l’ancre du commentaire lorsque l’URL est accessible ; poster un nouveau commentaire uniquement depuis le formulaire réel d’un parent ouvert.                                                                                              |
| Actions interdites   | Éditer/supprimer/modérer depuis le compte, voir les sujets Forum non publiés, voir les commentaires non publiés, revendiquer un sujet issu d’une proposition.                                                                                                               |
| Frontière vie privée | Extrait rendu via le pipeline `basic_html`, pas par `strip_tags` improvisé ; aucune identité d’un autre commentateur ni donnée de modération.                                                                                                                               |

## Matrice d’accès consolidée

| Donnée                                 | Propriétaire                                        | Autre membre / anonyme                             | Administrateur                    | Garde obligatoire                                     |
| -------------------------------------- | --------------------------------------------------- | -------------------------------------------------- | --------------------------------- | ----------------------------------------------------- |
| Profil résumé                          | Lecture                                             | Aucune extension de visibilité                     | Selon Core                        | UID route = UID courant + accès entité                |
| Champs de droits user                  | Lecture synthétique                                 | Interdit                                           | Lecture/édition                   | UID courant ; édition toujours `administer users`     |
| Réservation Webform                    | Résumé allowlisté futur seulement                   | Interdit                                           | Résultats complets selon Webform  | Webform exact + `submission.uid` + garde propriétaire |
| Téléphone/adresse/notes de réservation | Omis du résumé ; détail futur possible              | Interdit                                           | Selon administration Webform      | Même propriétaire + minimisation                      |
| Droit sur place                        | Compteur/libellé métier                             | Interdit                                           | Ledger brut                       | `right.uid`; commande/item et submission recroisés    |
| Commande                               | Lecture via accès Commerce existant                 | Interdit                                           | Selon permission Commerce         | `commerce_order.uid` + `order.access('view')`         |
| Paiement Commerce                      | Pas d’accès direct ; état minimal dérivé de l’ordre | Interdit                                           | Vue `administer commerce_payment` | Ne jamais contourner l’accès payment                  |
| Proposition                            | Résumé allowlisté futur seulement                   | Interdit                                           | File Webform privée               | Webform exact + `submission.uid`                      |
| Sujet Forum publié                     | Lecture publique si node accessible                 | Lecture publique                                   | Tous selon administration         | `status=1` + node access pour non-admin               |
| Sujet Forum non publié/révision        | Interdit même auteur                                | Interdit                                           | Oui                               | Hook `node_access` existant                           |
| Commentaire publié propre              | Résumé + lien au parent accessible                  | Le commentaire public reste visible sur son parent | Oui                               | `comment.uid` + accès comment + parent                |
| Commentaire non publié                 | Non inclus                                          | Interdit                                           | Oui                               | Ne pas contourner la modération                       |
| Ligne Google                           | Aucun affichage étapes 1–6                          | Interdit                                           | Diagnostic seulement              | SID + UUID d’une submission strictement propriétaire  |

Un administrateur visitant le profil d’un membre ne doit pas recevoir la version
« propriétaire » par défaut : la garde PR #99 se base sur l’utilisateur courant.
Une éventuelle vue de support « voir comme le membre » serait une capacité
distincte, absente et non proposée ici.

## Matrice de présentation des états

### Réservation

| Signaux                                                                            | Texte propriétaire                                                                          | Fiabilité                                                                                       |
| ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `reservation` valide, places > 0, droit `consumed`                                 | Réservée · à régler sur place                                                               | Vérifié pour le flux sur place courant                                                          |
| Valeur active, droit `paid` avec même SID, source propriétaire et ordre `isPaid()` | Réservée · réglée                                                                           | Vérifié seulement après le croisement financier courant                                         |
| Droit `paid` sans ordre propriétaire actuellement payé                             | Réservation active, sans qualificatif financier                                             | La ligne peut être périmée après remboursement                                                  |
| Valeur active, aucune ligne sur place                                              | Réservation active; « réglée avec une séance » seulement après audit des données existantes | Le code courant consomme un crédit payé, mais aucun lien durable ne prouve les anciennes lignes |
| Date passée                                                                        | Date passée                                                                                 | Ne prouve ni présence ni cours effectué                                                         |
| Suffixe `\|0`                                                                      | Non active / masquée et signalée à l’administration                                         | Annulation, conflit et absence de droit sont confondus                                          |
| Valeur vide/invalide                                                               | Indisponible temporairement, sans détail technique                                          | Administration seulement                                                                        |
| Erreur Google                                                                      | Aucun changement du texte de réservation                                                    | La réservation Drupal reste source de vérité                                                    |

### Commande et paiement

| Signaux                                                                                          | Texte propriétaire                                              | Interdit                                                                            |
| ------------------------------------------------------------------------------------------------ | --------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| `state=completed` + `isPaid=true`                                                                | Commande finalisée · payée                                      | Ne pas dire que le cours est effectué                                               |
| `state=completed` + non payée + gateway manuelle + droits `pending_payment`/`consumed` cohérents | Commande enregistrée · à régler sur place                       | Ne pas appeler la commande « pending » si son workflow est completed                |
| `state=completed` + non payée + gateway manuelle + anciennes lignes `paid`                       | Règlement à vérifier                                            | Un remboursement n’est pas réconcilié dans le ledger de droits                      |
| `state=completed` + non payée + autre/inconnue                                                   | Paiement à confirmer                                            | Ne pas annoncer de crédit ou réservation                                            |
| `state=canceled`                                                                                 | Commande annulée; numéro/date de placement omis s’ils sont nuls | Ne pas substituer `order_id` ni appeler `created` « date de commande »              |
| `state=draft`                                                                                    | Hors liste                                                      | Ne pas l’appeler commande passée                                                    |
| Échec ou tentative de paiement fournisseur                                                       | Aucun statut membre fiable dans la phase initiale               | Aucun champ propriétaire audité ne porte un état `failed`; Payment reste admin-only |
| Webform handler `completed`                                                                      | Sans effet sur la commande                                      | Ce mot n’est pas un statut de paiement                                              |

### Droits

| Signaux                                  | Texte propriétaire                                                                                  |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------- |
| solde > 0, pas d’expiration              | N séances disponibles                                                                               |
| solde > 0, expiration aujourd’hui/future | N séances disponibles · valables jusqu’au…                                                          |
| expiration passée                        | Droits expirés · aucune séance utilisable, même si le compteur brut est encore positif              |
| `pending_payment` restant                | N séances disponibles · à régler sur place                                                          |
| `consumed`                               | Déjà utilisée par une réservation; état de paiement présenté sur cette réservation                  |
| `paid` sans SID                          | Transférée dans le solde agrégé; ligne masquée, paiement historique non garanti après remboursement |
| `cancelled`                              | Non utilisable                                                                                      |

### Propositions et contributions

- Une proposition stockée est « Reçue », rien de plus.
- Un sujet Forum visible est « Publié » seulement si `status=1` et accès accordé.
- Un commentaire du propriétaire est « Publié » seulement si `status=1` et son
  parent est accessible.
- « Refusée », « acceptée », « convertie en article » et « en modération » sont
  absents du modèle des propositions.

## Architecture de rendu future

Le point d’intégration recommandé est un unique bloc de coque sur la route
`entity.user.canonical`, rendu seulement pour le propriétaire. Chaque section de
données est un fragment lazy-built séparé afin qu’un cache désactivé pour une
table custom ne désactive pas le profil ou les listes entités.

Principes obligatoires :

1. l’UID est lu depuis `current_user`; le paramètre route sert uniquement à
   confirmer l’égalité ;
2. les repositories renvoient des DTO d’affichage allowlistés, jamais des entités
   ou lignes brutes aux templates ;
3. chaque URL est générée depuis une route existante puis testée avec
   `Url::access($account, TRUE)` ; l’`AccessResult` renvoyé est ajouté aux
   dépendances de cache du fragment ;
4. chaque texte utilisateur vient d’un mapping exhaustif avec repli neutre ;
5. les prix passent par le formateur Commerce, les dates par les services Drupal
   et le fuseau décidé ;
6. chaque fragment porte ses contextes/tags, y compris les résultats d’accès ;
7. les tables custom restent `max-age: 0` tant que tous leurs points d’écriture
   n’invalident pas un cache tag propriétaire ;
8. aucun template n’effectue de requête, de parsing de valeur brute ou de contrôle
   d’autorisation.

Les pagers ont une carte centrale sans collision : réservations `element=0`,
commandes `element=1`, propositions `element=2`, contributions `element=3`.
Core encode leurs positions dans le query arg `page`; le rendu du pager fait
buller son contexte précis `url.query_args.pagers:<element>`, tandis que
`url.query_args:page` reste une variation sûre du fragment englobant. Si
l’ergonomie de quatre pagers sur une page est mauvaise, le produit doit choisir
entre un aperçu borné et les pages existantes ; il ne faut pas créer de nouvelles
routes sans validation explicite.

## Prototype statique

Les fichiers `docs/prototypes/member-dashboard/index.html` et `prototype.css`
montrent :

- une surface principale chaude et plate ;
- un seul H1 ;
- une navigation par ancres vers les six sections ;
- un compte fictif et des exemples peu nombreux ;
- les deux types de droit réellement calculables ;
- une réservation réglée et une à régler sur place ;
- des commandes payée, sur place et annulée sans confondre leur workflow ;
- des propositions seulement « Reçues » ;
- des commentaires publiés sur un sujet Forum et un article Blog, sans inventer
  la propriété d’un sujet ;
- un état vide natif dans chaque section via `<details>` ;
- un exemple indépendant « Non active » au lieu d’une fausse annulation de
  réservation ;
- aucune donnée Google, ID technique, facture, tracking ou contrôle métier.

Les disclosures sont natives ; aucun JavaScript n’est nécessaire. Les liens de
prototype pointent vers une note locale, afin de présenter la hiérarchie sans
prétendre effectuer une action Drupal.

## Feuille de route en petites PR

### 1. Fondation propriétaire en lecture seule

- Réutiliser `entity.user.canonical`, sans route ni configuration publique.
- Ajouter une coque lazy-built avec garde UID courant/route et les six ancres,
  mais ne rendre que « Mon compte » au départ.
- Conserver le formulaire Core pour l’édition et les protections de PR #99.
- Tester profil propre/tiers/admin, cache dynamique, H1, 320 px et absence de
  fuite entre deux comptes.

### 2. Résumé des réservations

- Ajouter un repository strictement borné au Webform et à l’UID courant.
- Parser les créneaux avec le parseur partagé ; lister seulement les réservations
  actives, sans histoire inventée.
- Joindre le droit sur place par SID, recroiser commande/item/propriétaire et
  exiger `isPaid()` courant avant « réglée », avec `max-age: 0`.
- Mettre les valeurs `|0`/invalides en diagnostic admin, pas en « Annulée ».

### 3. Résumé des droits

- Lire le solde/date user et compter uniquement les droits
  `pending_payment` restants.
- Appliquer la règle d’expiration avant d’afficher un nombre utilisable.
- Nommer le solde « séances disponibles », car le flux actuel ne le réconcilie
  pas après remboursement.
- Ne créer aucun ledger rétrospectif de crédits payés.
- Ajouter un cache tag custom seulement avec invalidation exhaustive ; sinon
  conserver `max-age: 0`.

### 4. Résumé des commandes Commerce

- Réutiliser les accès et routes de `commerce_user_orders`.
- Mapper ensemble `state`, `isPaid()` et gateway manuelle ; ne jamais prendre
  `completed` pour « payée ».
- Présenter numéro et date de placement lorsqu’ils existent, lignes et total ;
  aucun accès direct à Payment.
- Tester paid, sur place non payé, remboursé, annulé sans numéro/date de
  placement, draft masqué et commande d’autrui.

### 5. Résumé des propositions et commentaires

- Ajouter la lecture allowlistée des propositions propres sans ouvrir les pages
  Webform ni `view_own` global.
- Ajouter les commentaires propres publiés avec contrôle du parent, puis les
  éventuels sujets Forum propres publiés.
- Ne jamais lier proposition et node sans futur champ durable.
- Garder édition, suppression et modération hors compte.

### 6. Actions propriétaire seulement après règles métier

- Ne considérer annulation/déplacement qu’après création d’un statut dédié, d’un
  journal, de règles de délai/remboursement/restauration de droit et de tests de
  concurrence.
- Ajouter chaque action séparément avec CSRF, accès, idempotence, messages et
  audit. Aucune action n’est incluse dans la conception actuelle.

### 7. Statut Google seulement après synchronisation fiable en production

- Fermer d’abord credentials, lease, retries/backoff, idempotence, requeue,
  monitoring et politique d’erreur.
- Prouver le fonctionnement sur calendrier de test puis via revue de production.
- Décider si le membre bénéficie réellement du statut. Si oui, n’afficher que le
  message générique, jamais queue ID, event ID, payload ou erreur brute.
- Maintenir Drupal/Webform comme source de vérité de la réservation.

## Validation statique exigée pour cette livraison

- diff limité exactement aux trois fichiers de conception/prototype ;
- aucun PHP, YAML Drupal, route, configuration ou fichier de production modifié ;
- HTML et CSS parsés/validés ; aucun asset ou script externe ;
- exactement un H1, régions/labels/listes/disclosures sémantiques ;
- aucune capacité fabriquée ni lien opérationnel dans le prototype ;
- matrice propriétaire/admin/autre compte explicite ;
- revue des contextes/tags, des tables sans invalidation et des données sensibles ;
- UTF-8 strict, fins de ligne LF et normalisation NFC ;
- règles CSS garantissant le reflow à 320 px, sans largeur minimale ni tableau
  horizontal ;
- `git diff --check origin/release/prod --` ;
- scan de secrets sur le diff, sans reproduire de valeur trouvée ailleurs ;
- revues indépendantes Drupal, Commerce, confidentialité, UX et accessibilité.

### Résultats du snapshot candidat

Les relecteurs indépendants ont travaillé en lecture seule et n’ont modifié
aucun fichier.

| Contrôle                            | Résultat                                                                                                                                                                                                             |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Drupal / contrat de données         | **PASS** après réalignement sur la base, suivi des trois fichiers, attribution des pagers `element=0..3` et propagation des `AccessResult`.                                                                          |
| Commerce                            | **PASS** après traitement des remboursements non réconciliés, du croisement `isPaid()`, des commandes annulées sans numéro/date et des caches dépendants.                                                            |
| Confidentialité intrinsèque au diff | **PASS** : frontières fail-closed, fixtures fictives, aucun secret littéral ajouté.                                                                                                                                  |
| Sécurité globale du dépôt           | **BLOCKED hors diff** : le credential PayPal sandbox public préexistant doit être révoqué/rotaté immédiatement et retiré de l’historique. Cette PR documentaire ne peut pas le corriger sans violer son périmètre.   |
| UX                                  | **PASS** : hiérarchie plate, statuts prudents, aucune capacité simulée et reflow prévu à 320 px.                                                                                                                     |
| Accessibilité statique              | **PASS** : un H1, ordre de titres, listes, descriptions, disclosures, focus, cibles de 44 px, mouvement réduit et couleurs forcées. Aucun test navigateur/technologie d’assistance n’a été exécuté dans cette phase. |

HTML-Validate 9.7.1, CSSTree Validator 4.0.1 et Prettier 3.6.2 passent.
Les assertions locales passent pour UTF-8/NFC/LF, les fragments, les six
sections, le H1 unique, l’absence de script/formulaire/table/URL externe, les
fixtures fictives et les garde-fous 320 px. Secretlint Quick Start 9.3.4 ne
détecte aucun secret dans les trois fichiers. Le scope exact, l’égalité de la
base et `git diff --cached --check origin/release/prod --` passent avant commit.

## Données manquantes ou non fiables

1. Aucun historique/version métier de réservation exploitable par le membre.
2. Aucun statut distinct annulation/refus/conflit, confirmation humaine,
   présence, séance effectuée ou échec.
3. Aucun lien durable d’une réservation vers le crédit payé consommé ou sa
   commande source.
4. Aucun ledger unitaire pour les crédits payés, aucune histoire du solde et
   aucune réconciliation démontrée après remboursement.
5. Aucun lien durable entre achat en ligne « reservation-first » et créneau ; la
   note de handoff existante reste une conception non implémentée.
6. Aucun statut/décision/commentaire de modération des propositions, ni lien vers
   un contenu créé.
7. Aucun droit membre actuel de consulter ses résultats Webform bruts.
8. Aucun accès direct membre aux entités Payment.
9. Aucun mécanisme fiable de retry/lease Google ni preuve de son état production.
10. Aucune facture documentaire téléchargeable, donnée d’expédition/tracking,
    statistique, progression, points ou niveau d’adhésion.

## Décisions requises du propriétaire

1. **Sécurité urgente** : le dépôt GitHub est public et l’export PayPal sandbox
   contient déjà un identifiant et un secret non vides aux lignes 18-19. Les
   valeurs ne sont pas reproduites ici. Elles doivent être révoquées/rotatées et
   retirées de l’historique selon une procédure dédiée, hors de cette PR de
   design.
2. Valider « Réservation active » comme terme tant qu’aucune confirmation humaine
   distincte n’existe.
3. Choisir si les valeurs `|0` sont simplement masquées ou montrées comme « Non
   active » ; elles ne peuvent pas être appelées « Annulées » sans migration.
4. Valider la frontière recommandée de résumé Webform allowlisté sans octroyer
   `view_own` global aux résultats.
5. Décider si un historique de réservation et un ledger de crédits justifient de
   nouveaux champs/tables avant toute UI historique.
6. Décider si les anciennes submissions et les remboursements doivent être
   audités puis réconciliés/backfillés avant tout libellé « réglée ».
7. Définir une vraie machine de modération et un lien proposition→contenu avant
   tout libellé autre que « Reçue ».
8. Confirmer que « Mes contributions » ne montre que les contenus publiés et
   accessibles, sans signaler les éléments modérés/non publiés.
9. Confirmer que les adresses client restent dans le détail Commerce et ne sont
   pas promues en profil principal.
10. Décider, après fiabilisation Google, si un statut agenda apporte une valeur
    au membre ou doit rester entièrement administratif.
11. Spécifier séparément les règles d’annulation, déplacement, remboursement et
    restauration de droit avant d’autoriser la moindre action propriétaire.
