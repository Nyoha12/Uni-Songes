# Nettoyage de l’entrée de réservation — 2026

## Résultat attendu

`/reserver` reste une URL publique de compatibilité, sans redirection. La page
présente `/reservation-cours` comme parcours principal pour tous les visiteurs.
L’ancien formulaire n’est rendu que pour un compte connecté que l’état serveur
autorise déjà à l’utiliser.

Cette modification est strictement statique. Elle ne change ni route, ni PHP,
ni Webform, ni produit Commerce, ni logique de créneau, de commande, de
notification, de file Google ou de consommation des droits.

## Périmètre Git et rebase

- À la reprise du 7 septembre 2026, la branche locale et
  `origin/codex-simplify-reservation-entry` pointaient toutes deux sur
  `74b8d0f62c3a6f5a68a662d88ca3f9feb835aa11`, sans modification locale.
- Après `git fetch origin`, la branche, alors 24 commits derrière, a été
  rebasée sans conflit sur la vraie `origin/release/prod` à
  `9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c`, merge de la PR #104.
- Le blob fonctionnel du template est resté identique pendant le rebase
  (`31749213c280df0b87f4d814cf3bf2809a7415a8`) ; seule cette documentation
  est actualisée ensuite.
- Fichiers du changement :
  - `drupal/web/themes/custom/unisonges_theme/templates/content/node--8.html.twig` ;
  - `docs/functional/reservation-entry-cleanup-2026.md`.
- Le corps éditorial générique du nœud n’est plus inséré dans cette page. Le
  composant de compatibilité devient ainsi l’unique source de son contenu et ne
  peut plus être concurrencé par des explications ou actions historiques.

## Audit des PR ouvertes après rebase

Les noms de fichiers de toutes les PR ouvertes ont été relus sur GitHub après le
rebase. En excluant cette PR #86, aucune ne modifie l’un de ses deux chemins.

Terminal 3 / PR #94 conserve l’usage exclusif de DDEV et du checkout servant.
Aucun autre worktree n’a été consulté ou modifié. Le sujet d’alias de la PR
#113 reste hors périmètre.

## Intégration de la base fusionnée

- La PR #99 (merge `5b8e80c`) présente les routes de connexion, inscription
  et compte sans les remplacer. Ses liens secondaires réutilisent le service
  Drupal `redirect.destination` ; les liens de cette page lui transmettent
  toujours exactement `destination=/reservation-cours`.
- La PR #100 (merge `48b9eb4`) conserve un seul rendu des messages système en
  flux dans `main`. Ce template de nœud n’ajoute aucun message ni second
  conteneur et laisse ce chemin de page inchangé.
- La PR #103 (merge `36b023c`) ajoute l’accueil éditorial du Blog sans toucher
  ce template, ses producteurs serveur, les messages ou les classes du portail.
- La PR #95 (merge `2ffa253`) conserve le texte blanc du CTA `.btn--cta` au
  survol et à l’activation, et renforce le focus du submit Webform historique.
  Les classes utilisées ici restent toutes définies dans la feuille fusionnée.
- La PR #84 (merge `a673a07`) exclut déjà `/reserver` et `/node/8` du bloc
  global de titre. Le `h1` du héros de cette page reste donc l’unique `h1`
  du DOM intégré.
- La PR #83 (merge `fe1e915`) fournit « Réserver » et « Créer un compte »,
  avec leurs accents, dans le header ordinateur et mobile. La copie de cette
  page reste cohérente avec ces libellés.

Le fichier `unisonges_theme.theme`, le template de messages, la configuration
du titre, le header et les styles restent identiques à
`origin/release/prod`.

## Contrats ouverts #90 et #114

La PR #90, ouverte et en brouillon, ne modifie ni `/reserver`, ni
`/reservation-cours`, ni ce template. Son panier vide guide vers
`/reservation-cours`, mais le panier classique ne reçoit aucun état de
créneau et ne réserve rien. La PR #86 ne dépend donc pas de la fusion de #90.

La PR #114 reste une proposition documentaire : claim atomique, commande
dédiée, finalizer et handoff PayPal sont des mécanismes futurs, inactifs et
soumis à des décisions encore ouvertes. Aucun n’est repris ou annoncé ici. La
phrase « Pour un paiement en ligne, le créneau choisi n’est pas réservé. » reste
la description prudente du comportement actuel.

## Sources serveur auditées

### Prétraitement de `/reserver`

`unisonges_structure_preprocess_node()` agit seulement sur le nœud `8`. Il
construit systématiquement :

- `unisonges_reservation_portal` avec
  `_unisonges_structure_build_reservation_portal_context()` ;
- `unisonges_reservation_form` avec
  `_unisonges_structure_build_course_reservation_form()` ;
- les contextes de cache `user` sur le nœud et son contenu.

Le formulaire reste donc construit côté serveur sans modification PHP, mais le
template contrôle maintenant strictement s’il entre ou non dans le HTML.

### Sens exact des variables disponibles

| Valeur | Sens prouvé dans l’implémentation | Usage après nettoyage |
|---|---|---|
| `portal.is_anonymous` | Vaut `true` par défaut et tant que `currentUser()->isAnonymous()` est vrai. Passe à `false` avant le chargement de l’entité du compte connecté. | Affiche uniquement le rappel de compte et les actions connexion/inscription. Une valeur absente échoue de façon fermée vers l’état anonyme. |
| `portal.can_book` | Résultat exact de `_unisonges_structure_user_can_book()`. Ce n’est pas synonyme de crédit payé : voir la preuve ci-dessous. | Avec `not portal.is_anonymous`, constitue la condition serveur prouvée d’accès à l’ancien formulaire. Une valeur absente vaut `false`. |
| `portal.remaining_label` | Pour un compte connecté chargé, concatène le compteur brut `field_seances_restantes`, borné à zéro pour l’affichage, et le nombre de droits `pending_payment`. Le compteur brut peut encore être positif alors que sa date est échue ou illisible. | Non affiché, car il ne permet pas d’isoler la quantité réellement utilisable dans tous les états mixtes. |
| `portal.expiry_label` | Formate `field_pack_expire_le` en « Valable jusqu’au… » ou « Validité échue… ». Vaut une chaîne vide si la valeur manque ou ne peut pas être lue. Il n’est pas rattaché au type de droit qui rend `can_book` vrai. | Non affiché, car son applicabilité au droit utilisable n’est pas prouvable dans le template. |
| `portal.purchase_url` | Valeur fixe `/cours`, accompagnée de `purchase_label = Voir les cours`. | Supprimé du rendu : aucun achat n’est proposé avant le choix du cours et du créneau. |
| `portal.tunnel_url` | URL de la route `unisonges_structure.reservation_course_tunnel`, avec repli exact `/reservation-cours`. | Destination de l’unique CTA principal, dans tous les états. |
| `portal.login_url` | Route `user.login` avec `destination=/reservation-cours`, ou repli équivalent. | Action secondaire réservée à l’état anonyme. |
| `portal.register_url` | Route `user.register` avec `destination=/reservation-cours`, ou repli équivalent. | Action secondaire réservée à l’état anonyme. |
| `unisonges_reservation_form` | Tableau de rendu du Webform `cours_particuliers_reservation`. Si le module ou le formulaire manque, ou pour une autre exception capturée, le constructeur renvoie un message contrôlé ; les exceptions Ajax et de réponse imposée sont relancées. | Le tableau original est rendu sans altération seulement si le compte est éligible et si le tableau n’est pas vide. La section et son titre sont absents sinon. |

### Preuve de la signification de `portal.can_book`

`_unisonges_structure_user_can_book()` renvoie `false` si le compte ne possède
pas `field_seances_restantes`. Sinon, il renvoie `true` si au moins une des deux
conditions suivantes est satisfaite :

1. `field_seances_restantes > 0` et `field_pack_expire_le` est vide, ou sa date
   est lisible et supérieure ou égale à la date du jour ;
2. la table `unisonges_structure_course_to_pay_right` contient pour ce compte
   au moins une ligne avec `status = pending_payment` et
   `remaining_to_pay_credits > 0`.

Un droit de paiement sur place disponible suffit donc à rendre `can_book` vrai,
y compris lorsqu’un ancien compteur payé est expiré ou que sa date est
illisible. La condition ne signifie ni « achat requis », ni « crédit payé ».

Cette même fonction protège l’ancien formulaire dans
`unisonges_structure_form_alter()` et dans
`unisonges_structure_booking_form_validate()`. Le template reprend sa valeur
sans recalcul métier et échoue de façon fermée si le contexte manque :

```twig
{% set is_anonymous = portal.is_anonymous is defined ? portal.is_anonymous : true %}
{% set can_use_existing_right = (not is_anonymous) and (portal.can_book|default(false)) %}
```

réutilise donc exactement la décision serveur déjà appliquée à la soumission ;
elle n’invente pas une nouvelle règle métier.

### Modèles de droits et consommation

- Une séance payée est stockée dans `field_seances_restantes`. Elle est
  utilisable seulement si son compteur est positif et si la date de pack est
  absente ou encore valide.
- Un paiement sur place crée un droit durable dans
  `unisonges_structure_course_to_pay_right`, avec l’état `pending_payment` et
  `remaining_to_pay_credits > 0`. Sa consommation le passe à `consumed`, le lie
  à la soumission et marque la réservation « COURS À PAYER » dans les sorties
  internes prévues.
- Lors d’une soumission historique sans commande de paiement sur place imposée,
  un crédit payé valide est consommé en priorité ; sinon le plus ancien droit
  `pending_payment` est consommé.

Le libellé public retenu, « Utiliser un droit déjà disponible », couvre donc les
deux modèles sans les présenter tous deux comme des crédits.

### Accès actuel et limites du parcours principal

La route `/reservation-cours` demande seulement la permission Drupal
`access content` :

- un visiteur anonyme voit une demande de connexion ou de création de compte ;
  ces deux liens conservent `/reservation-cours` comme destination ;
- un compte connecté peut choisir cours, créneau et détails sans appel à
  `_unisonges_structure_user_can_book()` ;
- le paiement sur place revalide le créneau, crée la commande non payée et le
  droit associé, puis crée la soumission dont l’insertion consomme ce droit
  avant de confirmer la réservation ;
- le paiement en ligne redirige encore vers le parcours d’achat classique. Il
  ne rattache pas le créneau au panier ou à la commande et ne réserve pas le
  créneau sélectionné.

La page de compatibilité mentionne explicitement cette dernière limite. Elle ne
promet donc ni disponibilité, ni succès, ni réservation automatique après un
paiement en ligne.

## Chemins de rendu après modification

### Commun à tous les états

Le héros fournit l’unique `h1` du template et l’unique description concise de
la séquence compte, cours, créneau, détails, paiement, confirmation. Il est
suivi d’un `h2`, de la limite du paiement en ligne et de l’unique CTA principal
« Commencer ma réservation » vers `portal.tunnel_url` ou son repli exact
`/reservation-cours`.

### Visiteur anonyme

- le CTA principal reste visible en premier ;
- une section `h2` explique qu’un compte est nécessaire pour confirmer ;
- connexion et inscription sont des actions secondaires avec
  `destination=/reservation-cours` ;
- `can_use_existing_right` est faux, donc ni section secondaire ni formulaire
  historique ne sont rendus ;
- aucun lien d’achat n’est rendu.

### Compte connecté sans droit utilisable

- le CTA principal `/reservation-cours` reste visible en premier ;
- la section de compte anonyme est absente ;
- `portal.can_book` est faux, donc ni titre vide, ni conteneur vide, ni ancien
  formulaire ne sont rendus ;
- `portal.purchase_url` n’est jamais utilisé.

### Compte connecté avec droit utilisable

- le CTA principal `/reservation-cours` reste visible en premier ;
- une section secondaire intitulée « Utiliser un droit déjà disponible » est
  rendue après le parcours principal ;
- le tableau de rendu original `unisonges_reservation_form` est conservé sans
  modification ;
- si ce tableau est absent ou vide, toute la section secondaire, titre compris,
  est omise ;
- quantité et échéance ne sont pas affichées, car les variables actuelles ne
  prouvent pas leur applicabilité dans les états mixtes.

Aucun relais PHP n’est requis pour distinguer les trois chemins de rendu :
`portal.can_book` est le garde-fou exact déjà disponible. Si un futur besoin
impose d’afficher les quantités, le plus petit relais PHP serait d’exposer
séparément le nombre de séances payées réellement valides, le nombre de droits
`pending_payment`, et une échéance limitée aux seules séances payées valides.

## Validation statique

Les contrôles de cette PR restent hors DDEV, Docker, Drush, Chromium, Mailpit et
VPS. Ils couvrent :

- syntaxe et délimiteurs Twig ;
- structure HTML des cinq rendus contrôlés ;
- UTF-8 strict et normalisation Unicode NFC ;
- analyse exhaustive des chemins conditionnels ;
- absence du formulaire pour les anonymes et les comptes sans droit ;
- présence du formulaire uniquement pour le compte éligible avec un tableau de
  rendu non vide ;
- CTA principal `/reservation-cours` dans chaque état ;
- absence de CTA d’achat et de vocabulaire produit, pack ou crédit dans la copie
  publique ;
- valeurs Twig absentes traitées sans ouverture accidentelle du formulaire ;
- un seul `h1` dans le template et hiérarchie `h1` puis `h2` ;
- identifiants de titres uniques et références `aria-labelledby` résolues ;
- chemins de routes inchangés ;
- `git diff --check`, garde exacte de deux fichiers et garde de chevauchement
  avec les PR ouvertes ;
- intégration ciblée des PR fusionnées #99, #100, #103 et #95, et frontières
  documentaires des PR ouvertes #90 et #114 ;
- absence de modification de logique métier ;
- revues indépendantes du flux de réservation et de l’accessibilité.

Le blob du template après rebase,
`31749213c280df0b87f4d814cf3bf2809a7415a8`, est identique à celui compilé et
rendu précédemment avec Twig PHP 3.22.2, version toujours verrouillée par le
dépôt. Les preuves précédentes de compilation et de validation HTML des cinq
états restent donc applicables ; elles ne sont pas présentées comme une nouvelle
compilation PHP.

La reprise a aussi rendu les cinq états avec Twig.js 3.0.0,
html-validate 9.7.1 et JSDOM 26.1.0 déjà présents dans le cache local, sans
installation ni téléchargement. Les chemins anonyme, connecté sans droit,
connecté avec droit, formulaire vide et contexte absent passent leurs
assertions de liens, titres, formulaire, ARIA, HTML et copie. Les gardes
ciblées, le contrôle des intégrations et les revues indépendantes se concluent
également sans blocage.

## Matrice runtime différée

Terminal 3 / PR #94 possède exclusivement l’environnement DDEV et le checkout
servant. Aucun DDEV, Docker, Drush, Chromium, Mailpit ou VPS n’a été utilisé
ici. Les vérifications suivantes ne doivent pas être déclarées réussies dans
cette PR statique et ne démarrent pas automatiquement à la libération des
ressources.

### Anonyme

- `/reserver` charge ;
- le CTA principal atteint `/reservation-cours` ;
- connexion et inscription conservent la destination de réservation ;
- l’ancien formulaire est absent ;
- aucun CTA d’achat préalable n’est présent.

### Compte connecté sans droit

- le CTA de réservation guidée fonctionne ;
- l’ancien formulaire est absent ;
- aucune section secondaire vide n’est présente ;
- aucun achat préalable ne bloque le parcours.

### Compte connecté avec un droit valide existant

- le CTA guidé reste principal ;
- la section secondaire de droit existant est présente ;
- l’ancien formulaire fonctionne ;
- quantité et échéance ne sont pas présentées à partir de valeurs ambiguës ;
- la réservation reste compatible avec le droit existant.

### Intégration

- affichage ordinateur et mobile ;
- navigation au clavier ;
- présentation #99 des pages connexion/inscription et conservation de
  `destination=/reservation-cours` ;
- erreurs et messages #100 rendus une seule fois, dans le flux de `main` ;
- un seul `h1` avec la configuration fusionnée de la PR #84 ;
- libellés accentués « Réserver » et « Créer un compte » de la PR #83 dans le
  header ordinateur et mobile ;
- contraste des CTA et focus du formulaire fusionnés par la PR #95 ;
- absence d’effet du scope éditorial de la PR #103 sur `/reserver` ;
- aucune barre de défilement horizontale ;
- aucun formulaire dupliqué ;
- aucun avertissement PHP ;
- aucune erreur dans la console du navigateur ;
- tunnel `/reservation-cours` inchangé.

### Coordination avec la PR #90

- suivre `/reserver` → `/reservation-cours`, puis le lien du panier vide vers
  `/reservation-cours`, en anonyme et en authentifié ;
- conserver les destinations d’authentification, le `h1` et le chemin de
  messages uniques sur ces transitions ;
- vérifier le panier vide français et son lien exact, sans boucle ni CTA
  produit/crédit prioritaire ;
- ne jamais présenter l’ajout au panier ou le paiement comme une réservation du
  créneau, et ne pas attendre les mécanismes futurs de la PR #114 ;
- conserver l’absence du formulaire historique sans droit et le parcours sans
  achat préalable avec un droit utilisable.
