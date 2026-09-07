# Fondation du tableau de bord membre — 2026

## Statut et périmètre

Cette phase livre une fondation **statique, en lecture seule et réservée au
propriétaire**. Elle ajoute un résumé privé au profil utilisateur canonique
existant sans remplacer le profil Core, sans créer d’URL et sans modifier les
fichiers partagés du thème.

L’implémentation est portée par le module custom
`unisonges_member_dashboard` et par le helper de cycle de vie
`drupal/scripts/manage-member-dashboard-module.php`. Le module déclare comme
dépendances `comment`, `node`, `user`, `commerce_order`, `commerce_price`,
`unisonges_structure` et `webform`. Il ne contient ni configuration à
installer, ni schéma, ni entité persistante.

La source de conception a été lue sans écriture avec `git show` depuis le
commit exact `8d6b09fe4da8e34a79d0c32e468a996ba48e4db6` de la branche de la PR #105 :
`docs/design/member-dashboard-2026.md`,
`docs/prototypes/member-dashboard/index.html` et
`docs/prototypes/member-dashboard/prototype.css`. Aucun de ces trois fichiers
ni la branche `codex-design-member-dashboard` n’est modifié par cette
implémentation.

Cette note décrit le code présent dans la branche. Elle ne vaut pas validation
runtime : la matrice DDEV/Chromium reste explicitement différée. DDEV, Docker,
Drush, Chromium, Mailpit et le VPS n’ont pas été utilisés dans cette phase.

## Intégration exacte au profil Core

`unisonges_member_dashboard_user_view()` délègue à
`MemberDashboardAttachment` lors du rendu d’une entité utilisateur. Le service
n’attache le fragment que pour le mode de vue `full`, sur la route exacte
`entity.user.canonical`, après validation de toutes les gardes propriétaire et
d’accès décrites ci-dessous.

Le fragment est ajouté sous la clé de rendu
`unisonges_member_dashboard`, avec un poids `1000`, après le contenu réel du
profil. C’est un placeholder lazy-built par
`unisonges_member_dashboard.builder:build`; avant toute lecture privée, le
builder revérifie la route canonique, l’identité du propriétaire et l’accès
normal à l’utilisateur de route. L’entité effectivement rendue a déjà été
contrôlée par l’attachement. Ces deux frontières évitent qu’un placeholder
attaché dans un contexte autorisé soit reconstruit dans un contexte qui ne
l’est plus.

Le template du module :

- ne produit aucun `h1` : le titre de page fourni par le bloc Core reste
  l’unique H1 ;
- conserve au-dessus les champs de profil rendus par Core et ne recrée pas le
  formulaire de compte ;
- ne touche pas à la tâche locale d’édition ;
- fournit cinq sections principales en `h2` et une navigation interne ;
- ajoute seulement un repère contextuel « Mon compte » rappelant que les
  informations de profil restent gérées par Drupal ;
- attache uniquement la bibliothèque
  `unisonges_member_dashboard/dashboard`, définie par le module.

La présentation est une suite de sections plates, séparées par des traits fins,
avec statuts compacts, listes refluables et états vides factuels. Aucun fichier
du thème partagé, template de page ou template de messages n’est modifié ; la
présentation de compte existante, le `main` et l’unique chemin de messages
restent donc sous la responsabilité de l’intégration déjà en place.

## Contrat d’accès propriétaire

`DashboardAccessPolicy::allows()` ne renvoie vrai que si toutes ces conditions
sont simultanément satisfaites :

1. le nom de route est exactement `entity.user.canonical` ;
2. le format de réponse est exactement `html` et aucun paramètre d’enveloppe
   `_wrapper_format` n’est présent ;
3. la personne courante est authentifiée et son UID est strictement positif ;
4. l’UID du paramètre de route est l’UID courant ;
5. l’UID de l’entité utilisateur rendue est l’UID courant ;
6. l’accès normal `view` à cette entité est accordé.

L’attachement calcule l’accès de l’entité rendue. Le lazy builder recalcule
l’accès sur l’utilisateur de route juste avant ses requêtes. La matrice
fail-closed encodée dans le harness statique est la suivante :

| Contexte | Résultat attendu par le code | Garde déterminante |
| --- | --- | --- |
| Propriétaire authentifié, profil canonique, mode `full`, accès `view` accordé | Fragment autorisé | Toutes les gardes sont vraies |
| Personne anonyme | Aucun fragment | Authentification et UID positif refusés |
| Membre consultant le profil canonique d’un autre membre | Aucun fragment | UID route/rendu différent de l’UID courant |
| Administrateur consultant ou éditant un autre compte | Aucun fragment, même si l’accès entité est accordé | Égalité stricte des UID puis route canonique |
| Propriétaire sans accès normal à l’entité | Aucun fragment | Résultat d’accès entité refusé |
| Formulaire d’édition | Aucun fragment | Route différente de la canonique |
| Liste d’utilisateurs ou résultat de recherche | Aucun fragment | Route différente et/ou absence d’utilisateur de route exact |
| Teaser ou autre mode de vue | Aucun fragment | Mode différent de `full` |
| API, JSON:API, enveloppe Ajax Drupal ou sérialisation | Aucun fragment | Route, format de requête ou `_wrapper_format` refusé |
| Route canonique tentant de rendre une autre entité utilisateur | Aucun fragment | UID rendu différent de l’UID courant |

La réussite de cette matrice dans un vrai Drupal reste à confirmer dans la
validation runtime différée. Le harness statique vérifie séparément chaque
branche négative et l’unique branche positive.

## Données rendues

### Mes réservations

La source est exclusivement constituée des soumissions complètes du Webform
`cours_particuliers_reservation` dont `uid` est l’UID courant. La requête
d’entité utilise volontairement une frontière étroite avec
`accessCheck(FALSE)`, puis impose `webform_id`, `uid` et `in_draft = 0`. Chaque
entité chargée est à nouveau contrôlée : type WebformSubmission, propriétaire,
absence de brouillon et Webform exact. Aucune permission générique de résultats
Webform n’est accordée.

Les valeurs affichables sont :

- date de création de la soumission ;
- date et heure de réservation seulement si la valeur correspond exactement à
  `AAAA-MM-JJ HH:MM|drapeau`, forme une date possible et se parse dans le fuseau
  `Europe/Paris` ;
- instrument parmi `guimbarde` et `didgeridoo`, sinon le libellé neutre
  « Réservation » ;
- mode parmi `visio`, `studio` et `domicile` ;
- plateforme, seulement pour `visio`, parmi `zoom`, `google_meet`, `skype`,
  `whatsapp` et `autre`.

Le statut est volontairement conservateur :

| Signal source validé | Libellé public |
| --- | --- |
| Drapeau de réservation exactement égal à `1` | `Enregistrée` |
| Drapeau nul, valeur absente, invalide, impossible ou ambiguë | `Non active` |

Une valeur ambiguë n’est jamais présentée comme annulée, refusée, expirée,
remboursée ou confirmée humainement. Les clés Webform brutes et les identifiants
de soumission ne sont pas envoyés au template.

### Mes séances ou droits utilisables

Les deux origines restent visiblement séparées et ne constituent pas un faux
historique séance par séance.

Le solde payé provient uniquement de `user.field_seances_restantes`. Il est
affiché seulement si l’accès normal au champ est accordé et si la valeur est un
entier strictement positif. Si
`user.field_pack_expire_le` est absent ou vide, le solde peut être affiché sans
date. Si ce champ existe, son accès normal doit aussi être accordé. S’il est
renseigné, il doit être une date `AAAA-MM-JJ` valide, non expirée
au jour civil courant inclus dans le fuseau résolu pour la requête authentifiée,
comme la règle de consommation existante ; une date invalide ou passée masque
l’agrégat. Aucun ordre n’est artificiellement relié à ce solde.

Les droits à régler sur place proviennent de la table existante de
`unisonges_structure`,
`unisonges_structure_course_to_pay_right`. Le module du tableau de bord ne
possède pas cette table. La requête Drupal Database API est paramétrée par
`uid = UID courant`, `status = pending_payment` et
`remaining_to_pay_credits > 0`; aucun SQL brut n’est utilisé.

Chaque ligne candidate est omise sauf si toutes les vérifications suivantes
réussissent :

- identifiants d’ordre et d’article de commande valides, nombre restant
  exactement égal à l’unité créée par le schéma, et index de crédit positif
  borné par le bundle et la quantité de l’article ;
- UID et état de la ligne encore conformes aux filtres ;
- commande existante appartenant au même client, en état `completed`, non
  payée, associée au gateway manuel et visible par la personne courante ;
- article référencé réellement contenu dans cette commande, rattaché au même
  ordre et accessible en lecture ;
- variation et produit existants ;
- bundle réel du produit compris dans l’allowlist `cours_essai`,
  `cours_deb_inter`, `cours_avance`, `pack_4_deb_inter` et identique au bundle
  stocké dans la ligne ;
- marqueurs terminaux `consumed`, `paid` et `cancelled` tous strictement nuls ;
- référence de soumission strictement nulle. Lorsqu’un identifiant non nul est
  néanmoins présent, son existence, son propriétaire et son Webform sont
  d’abord contrôlés, puis la ligne est quand même exclue car elle représente
  déjà une réservation et non un droit encore libre.

Seule la somme des unités restantes des lignes intégralement vérifiées est
affichée, sous le libellé public « À régler sur place ». Les marqueurs internes,
identifiants techniques et relations rejetées ne sont jamais rendus.

### Mes commandes

La source est `commerce_order`. La requête d’entité active
`accessCheck(TRUE)`, exige le client propriétaire courant, exclut `draft` et
charge les plus récentes. Après chargement, le code revérifie le type d’entité,
le customer ID, l’état non brouillon, l’accès normal `view` et l’accès aux
champs effectivement lus.

Une commande retenue peut afficher uniquement son numéro public, sa date de
placement (ou à défaut de création), son total formaté dans sa devise et un
statut synthétique. Le lien utilise la route existante
`entity.commerce_order.user_view` avec l’UID courant et l’ID de la commande ;
il n’est rendu que si l’accès à cette URL est accordé. Aucun identifiant de
transaction ou PayPal, profil client, donnée de facturation ou état machine
brut n’est exposé.

La correspondance exacte est :

| Signaux vérifiés | Libellé public |
| --- | --- |
| État `canceled` ou `cancelled` | `Annulée` |
| État `completed`, total strictement positif et `isPaid() = TRUE` | `Payée` |
| État `completed`, non payée, gateway manuel et au moins un droit `pending_payment` vérifié pour cette commande | `À régler sur place` |
| Toute autre combinaison, notamment un état de paiement échoué non assimilable à une annulation | `En cours` |

### Mes propositions

La source est exclusivement constituée des soumissions complètes du Webform
`forum_blog_proposal` appartenant à l’UID courant. La même frontière propriétaire
que pour les réservations impose Webform exact, UID exact, `in_draft = 0`, puis
revérifie chaque entité chargée.

Une proposition n’est retenue que si son titre est non vide et si
`proposal_type` vaut exactement `idea`, `discussion_topic` ou `article_theme`.
Le titre est limité à 160 caractères. Seuls la date d’envoi, le type traduit,
le titre et le statut prudent `Reçue` sont affichés. Le tableau de bord ne
prétend pas qu’une proposition est en modération, acceptée, refusée ou publiée.

### Mes contributions

La source est constituée des commentaires dont l’auteur est l’UID courant,
publiés, attachés au champ `comment` d’un node. La recherche charge un ensemble
borné avec `accessCheck(FALSE)`, puis applique les contrôles explicites suivants
avant tout rendu :

- propriétaire et statut publié du commentaire ;
- accès normal `view` au commentaire ;
- si le commentaire est une réponse, publication et accès `view` de son
  commentaire parent direct ;
- parent de type node, publié, de bundle `article` ou `forum_topic` ;
- accès normal `view` au node parent ;
- accès normal aux champs de commentaire, date et titre effectivement lus ;
- accès à son URL canonique existante.

Le corps traité est converti en texte brut, ses espaces sont normalisés et
l’extrait est limité à 180 caractères. Le titre parent est limité à
160 caractères. Seuls la date, l’extrait sûr, le type/titre du parent et le lien
canonique accessible sont affichés. Un commentaire non publié, inaccessible ou
rattaché à un parent inaccessible est omis.

### Mon compte

Core reste l’unique source des champs de compte et de profil. Le module ajoute
un petit repère contextuel et la navigation vers les cinq résumés, sans
dupliquer ces champs ni ajouter une sixième section de données.

## Bornes et états vides

Aucun historique n’est illimité et aucun pager n’est créé dans cette phase :

- réservations : cinq soumissions au maximum ;
- commandes : vingt-cinq candidates au maximum, puis cinq entrées affichées ;
- propositions : vingt-cinq candidates au maximum, puis cinq entrées valides
  affichées ;
- contributions : vingt-cinq candidates au maximum, puis cinq entrées
  intégralement accessibles affichées ;
- droits à régler sur place : audit de cent lignes au maximum. Si une
  cent-unième candidate supplémentaire est détectée, l’agrégat complet est
  omis et un avertissement générique est journalisé.

Il n’existe donc aucun argument de pager ou de query string à faire varier, et
aucune section ne peut déplacer la fenêtre d’une autre. Toute pagination future
devra employer des clés de requête namespacées, ajouter leurs contextes de cache
et conserver les mêmes contrôles propriétaire.

Les cinq états vides restent visibles et factuels :

- « Aucune réservation affichable pour le moment. »
- « Aucun droit utilisable actuellement. »
- « Aucune commande à afficher. »
- « Aucune proposition envoyée. »
- « Aucune contribution publiée. »

Une erreur de source est interceptée, journalisée sans détail privé puis rendue
comme une liste vide ; l’absence de données n’est pas présentée comme une erreur
à la personne membre.

## Cache et confidentialité

La décision d’attachement varie sur `route`, `request_format`,
`url.query_args:_wrapper_format`, `user`, `user.permissions` et
`languages:language_interface`, y compris sur les branches qui refusent le
fragment. Cela empêche un rendu `user.full` construit hors du profil canonique
d’amorcer une entrée de cache réutilisable sur le profil propriétaire. Elle
dépend aussi de l’entité utilisateur et de son résultat d’accès.

Le lazy builder varie sur :

- `route` ;
- `request_format` ;
- `url.query_args:_wrapper_format` ;
- `user` ;
- `user.permissions` ;
- `languages:language_content` ;
- `languages:language_interface` ;
- `timezone`.

Il applique `max-age: 0`. Les soumissions privées et la table de droits custom
ne disposent pas encore d’un contrat d’invalidation exhaustif ; le fragment
reste donc propre à la requête, même si le placeholder est lazy-built. Le rendu
d’un propriétaire ne peut pas être réutilisé comme données d’un autre
propriétaire.

Les tags généraux `webform_submission_list`, `commerce_order_list`,
`comment_list` et `node_list`, les tags de configuration des deux Webforms,
ainsi que les dépendances cacheables des utilisateurs, accès, soumissions,
Webforms, commandes, articles de commande, produits, variations, commentaires,
nodes et URLs examinés sont collectés quand ils existent.

Une amélioration future pourra introduire une invalidation explicite sur chaque
mutation de soumission privée et de droit custom, puis réévaluer un max-age
positif. Elle devra conserver les contextes actuels, les tags d’entité et une
invalidation fiable de la table custom avant toute mise en cache partagée.

## Éléments volontairement absents

Le module :

- ne déclare aucune route publique et aucun fichier de permissions ;
- n’accorde aucune permission Webform, Commerce, utilisateur ou commentaire ;
- ne contient ni `.install`, ni `hook_schema()`, ni table, ni entité persistante ;
- ne fournit aucune configuration install/optional et ne réalise aucun import
  de configuration, complet ou partiel ;
- ne modifie ni le fichier versionné `core.extension.yml`, ni la configuration
  Commerce/Webform, ni la logique de réservation ;
- ne modifie aucun fichier partagé du thème, template de page ou template de
  messages ;
- ne lit et n’affiche aucun état Google Calendar, ligne de file, ID d’événement,
  état de retry ou erreur interne ;
- ne propose aucune annulation ou déplacement de réservation, consommation de
  droit, paiement/retry de commande, suppression de proposition, édition de
  commentaire ou synchronisation Google.

Les seules actions visibles sont des liens canoniques déjà existants et
contrôlés par accès : vue propriétaire d’une commande et contenu parent d’une
contribution.

## Helper ciblé d’activation et de retour arrière

Le helper livré se trouve à
`drupal/scripts/manage-member-dashboard-module.php`. Il bootstrappe directement
Drupal depuis PHP ; il n’appelle ni Drush, ni DDEV, ni Docker, ni SSH. Son
exécution est réservée à une future phase runtime approuvée, sur un checkout
déployable disposant de `vendor/`, d’un site configuré et d’une sauvegarde
récente.

Depuis la racine du dépôt, définir l’origine explicitement puis exécuter les
commandes suivantes. Le dry-run est le mode par défaut, mais l’option est écrite
ici pour rendre l’intention non ambiguë :

```bash
cd drupal
MEMBER_DASHBOARD_SITE_ORIGIN='https://site-approuve.example'

php scripts/manage-member-dashboard-module.php \
  --site-uri="$MEMBER_DASHBOARD_SITE_ORIGIN" --dry-run
```

Le dry-run imprime le plan exact et un `PLAN_TOKEN`. Après examen du plan et
confirmation d’une sauvegarde actuelle, l’activation future emploie le token
de ce même état :

```bash
MEMBER_DASHBOARD_PLAN_TOKEN='COLLER_ICI_LE_PLAN_TOKEN_SHA256'

php scripts/manage-member-dashboard-module.php \
  --site-uri="$MEMBER_DASHBOARD_SITE_ORIGIN" \
  --apply --backup-confirmed \
  --plan-token="$MEMBER_DASHBOARD_PLAN_TOKEN"
```

Le retour arrière commence lui aussi par son propre dry-run, car l’action et
l’état font partie du token :

```bash
php scripts/manage-member-dashboard-module.php \
  --site-uri="$MEMBER_DASHBOARD_SITE_ORIGIN" --rollback --dry-run

MEMBER_DASHBOARD_ROLLBACK_TOKEN='COLLER_ICI_LE_PLAN_TOKEN_SHA256'

php scripts/manage-member-dashboard-module.php \
  --site-uri="$MEMBER_DASHBOARD_SITE_ORIGIN" \
  --rollback --apply --backup-confirmed \
  --plan-token="$MEMBER_DASHBOARD_ROLLBACK_TOKEN"
```

Le helper :

- cible exactement `unisonges_member_dashboard` et vérifie son chemin et son
  manifeste de fichiers ;
- exige PHP 8.3 ou plus, conformément au projet verrouillé, et un Drupal 11
  installé par Composer ;
- exige que la liste exacte des dépendances soit déjà activée et cohérente dans
  `core.extension`, le module handler, le conteneur et `system.schema` ;
- refuse un état partiel ou inconnu du module, une dérive de dépendances, un
  chemin symbolique inattendu, une configuration active/dépendante, une
  configuration optionnelle susceptible d’être installée, une table préfixée
  par le module ou une définition persistante fournie par lui ;
- imprime `ENABLE ONLY unisonges_member_dashboard` ou
  `DISABLE/UNINSTALL ONLY unisonges_member_dashboard` avant toute écriture ;
- appelle respectivement
  `module_installer->install([unisonges_member_dashboard], FALSE)` ou
  `module_installer->uninstall([unisonges_member_dashboard], FALSE)`, sans
  activation automatique des dépendances ni désinstallation des dépendants ;
- ne réalise aucun import/export de configuration, migration de contenu ou
  création/lecture/écriture de données utilisateur ;
- revalide le code et l’état immédiatement avant l’unique appel de cycle de
  vie ;
- accepte après application uniquement le changement de la clé active
  `core.extension.module.unisonges_member_dashboard`, sans changement de
  tables, puis contrôle l’état final ;
- est idempotent : si l’état demandé est déjà atteint, il annonce `NO CHANGE`
  et n’appelle pas le module installer.

L’activation met à jour la configuration **active** par l’API d’installation de
modules Drupal ; elle ne modifie pas le fichier synchronisé
`config/sync/core.extension.yml` et n’importe aucune configuration.

## Validation statique exécutée

La validation finale a été exécutée depuis la racine du dépôt, sans DDEV,
Docker, Drush, Chromium, Mailpit ni accès au VPS. Elle ne constitue pas une
validation runtime Drupal.

| Contrôle | Résultat statique |
| --- | --- |
| Base distante | `HEAD` et `origin/release/prod` égaux à `8cc82f9af6899aedc14490931c415293d0bdf0cb` avant le commit |
| PHP | lint réussi sur les six fichiers PHP/module du module et sur le helper |
| Contrat fonctionnel/confidentialité | `Member dashboard contract PASS (258 assertions)` |
| YAML | parsing réussi des trois manifestes avec le composant Symfony verrouillé |
| Injection de dépendances | signatures et nombres d’arguments exacts pour les trois services custom ; service Commerce verrouillé trouvé |
| Twig/HTML | compilation avec Twig verrouillé, rendus stricts vide et alimenté, puis validation HTML réussis |
| CSS | parsing PostCSS 8.5.6 et validation CSS Tree 4.0.1 réussis |
| Helper | lint et `--help` réussis ; options incomplètes refusées avant bootstrap |
| Encodage | treize fichiers valides UTF-8, normalisés NFC, fins de ligne LF et newline finale |
| Secrets | Secretlint Quick Start 9.3.4 sans constat sur les treize fichiers |
| Diff | garde exacte de treize fichiers, aucun fichier non suivi/non indexé, `git diff --cached --check` réussi |
| Chevauchement | vingt PR ouvertes inspectées, aucun chemin commun ; les trois fichiers de la PR #105 restent distincts |

Le validateur HTML signale normalement `role="list"` comme redondant sur une
liste native. Cette seule règle a été désactivée pour le rendu de contrôle :
le rôle est volontairement conservé quand le CSS supprime les marqueurs de
liste, afin de préserver la sémantique annoncée par certaines combinaisons
navigateur/lecteur d’écran. Toutes les autres règles recommandées restent
actives.

Le harness exerce notamment :

- l’unique branche propriétaire positive et les branches négatives anonyme,
  membre tiers, administrateur consultant ou éditant un tiers, listing,
  recherche, teaser par la garde de mode, API/JSON, wrapper Ajax et accès entité
  refusé ;
- les Webforms exacts, leur configuration d’accès sans `view_any`/`view_own`,
  l’UID, les brouillons et l’allowlist exacte de six clés de données ;
- l’accès et la propriété Commerce, les champs lus, les liens, le total positif
  et les mappings prudents ;
- la forme complète des droits à régler sur place, leurs marqueurs terminaux,
  chaînes ordre/article/produit/soumission, capacité et relations cross-owner ;
- la publication et l’accès du commentaire, du commentaire parent, du contenu
  parent, de leurs champs et de l’URL ;
- les métadonnées de cache positives et négatives, l’absence de H1/main ajouté,
  les IDs/landmarks/listes, la cible tactile de 44 px, le focus, le reflow et
  les couleurs forcées ;
- l’absence de SQL brut, permission large, route, schéma, import de
  configuration, action membre, donnée Google Calendar ou identifiant interne.

Les revues indépendantes et sans écriture Drupal/confidentialité, Commerce,
Webform, réservation et accessibilité ne laissent aucun constat actionnable
dans ce périmètre. La revue accessibilité a relevé que le thème partagé masque
le scrollbar du scroller de compte ; ce fichier est explicitement hors
périmètre et le comportement est donc porté dans la matrice runtime ci-dessous.

L’environnement CLI local utilise PHP 8.2 alors que le projet Drupal verrouillé
exige PHP 8.3. Le helper laisse `--help` fonctionner, mais refuse correctement
son dry-run complet avant bootstrap sous PHP 8.2. Le dry-run Drupal, l’activation
et le rollback restent donc à exécuter dans la matrice runtime PHP 8.3 ; aucun
succès de cycle de vie n’est revendiqué ici.

## Matrice runtime explicitement différée

La PR doit rester en brouillon tant que la matrice DDEV/Chromium complète
n’a pas réussi. Cette phase ultérieure devra couvrir au minimum, avec des
fixtures temporaires puis un retour exact à zéro fixture :

### Identité, accès et cache

- personne anonyme ;
- propriétaire sur son profil canonique ;
- autre membre sur ce profil ;
- administrateur consultant et éditant un autre compte ;
- refus normal d’accès à une entité ;
- transition connexion/déconnexion et absence de fragment privé survivant dans
  le cache ;
- absence de fuite entre UID, onglets, navigation arrière/avant, listing,
  recherche, teaser et réponses API/sérialisées.

### Jeux de données

- aucun jeu de données ;
- chacune des cinq sections alimentée isolément ;
- données mixtes dans toutes les sections ;
- lignes malformées et relations cross-owner dans les droits ;
- commandes, commentaires, commentaires parents et contenus parents
  inaccessibles ;
- dates et statuts ambigus, expirés ou inconnus ;
- historiques couvrant plusieurs pages théoriques : cinq éléments au maximum,
  absence de pager dans cette phase et stabilité indépendante des bornes ;
- états vides exacts et absence d’identifiants ou de clés internes.

### Structure et accessibilité

- desktop, tablette, mobile et largeur 320 px ;
- clavier seul et focus visible ;
- visibilité du scrollbar et défilement clavier du scroller de compte
  `#unisonges-scrollframe` : le thème partagé masque actuellement le scrollbar,
  mais ses fichiers sont explicitement hors périmètre de cette PR ;
- reflow à 100 %, 150 % et 200 % ;
- un seul H1, un seul `main` et un seul chemin de messages ;
- ordre de lecture, landmarks, liens, libellés de statut, contraste, reflow et
  absence de débordement horizontal ;
- absence complète de données personnelles sur tout contexte négatif.

### Cycle de vie

- dry-run d’activation, token, application et second passage idempotent ;
- dry-run de rollback, token, désinstallation et second passage idempotent ;
- aucune configuration imprévue, aucune table et aucune donnée créée ;
- restauration/cleanup final avec zéro fixture et aucune modification résiduelle.
