# Expérience d’authentification et de compte — proposition 2026

## Statut et périmètre

Ce document accompagne un prototype statique de conception. Il ne modifie ni
Drupal, ni le thème, ni la configuration, ni une URL publique. La proposition
porte sur les pages et états liés à :

- `/user/login` ;
- `/user/register` ;
- `/user/password` ;
- `/user`, qui redirige vers `/user/{uid}` pour une personne connectée ;
- le lien à usage unique partagé par la vérification initiale et la
  réinitialisation du mot de passe ;
- le formulaire de compte utilisé pour choisir un mot de passe après ce lien.

Le prototype se trouve dans
`docs/prototypes/auth-account-experience/`. Il est autonome : aucun CDN, aucune
police externe, aucune image et aucune dépendance ne sont chargés. Les valeurs
de démonstration y sont explicitement signalées comme fictives.

Base auditée le 1er septembre 2026 :
`origin/release/prod` à
`894f054f6c1ffe6a75d43dc04889fbaeea0a157d`, Drupal 11.3.3 et Bootstrap
Barrio 5.5.20. L’audit a été conduit sans DDEV, Docker, Drush, navigateur ou
accès au VPS, conformément à l’exclusivité de la PR #93.

## Résumé de la direction

L’expérience proposée est une surface centrale chaude et presque opaque,
posée dans le cadre public existant. Elle laisse le fond autonome visible
autour du contenu sans jamais placer du texte directement sur celui-ci. Le
langage reste calme : texte bleu-gris foncé, accent turquoise existant,
bordure fine, ombre retenue et rayons compacts.

Chaque page garde une seule tâche dominante :

- se connecter ;
- créer un compte ;
- demander des instructions de réinitialisation ;
- agir sur son compte.

Le titre reste à une échelle de lecture, les libellés sont persistants au-dessus
des champs, l’action principale est évidente et les destinations secondaires
sont plus discrètes. Les messages se placent dans le flux, immédiatement avant
la carte concernée. Ils ne flottent pas au-dessus de l’interface et ne
disparaissent pas automatiquement.

La proposition ne promet ni disponibilité, ni historique de réservation, ni
statistique, ni avantage non prouvé. Elle n’ajoute ni connexion sociale, ni
lien magique distinct, ni newsletter, ni consentement marketing, ni champ de
profil.

## Audit de l’existant

### Coque publique

`drupal/web/themes/custom/unisonges_theme/templates/page.html.twig` attache la
bibliothèque `unisonges_theme/unisonges-layout`, rend le fond BGFX, l’en-tête
compact et `page.content` une seule fois dans `main#main-content` et le cadre
défilant. La variante d’accueil conserve le même contrat.

La PR #94, encore ouverte pendant cet audit, déplace le futur pied de page dans
le même cadre défilant, après `main`. Une implémentation ultérieure ne doit donc
pas recopier ou remplacer les gabarits de page : elle doit hériter de la coque
après intégration de cette PR.

Le fond est fixé, décoratif et inerte. Les routes `/user/*` reçoivent la classe
de section `section-user` et utilisent aujourd’hui le fond public par défaut,
aucun fond spécifique au compte n’étant défini. Le prototype représente ce
contrat par un fond abstrait purement CSS, non par un nouvel actif à intégrer.

À 320 px, le cadre de production mesure environ 288 px avant son espacement
interne actuel. Le futur CSS devra donc réduire son espacement horizontal sur
les routes concernées, imposer `min-width: 0` aux descendants et n’introduire
aucune largeur minimale fixe ni second conteneur défilant.

### En-tête et pied de page

L’en-tête réel fournit déjà le contexte de marque Uni-Songes et les destinations
utiles : `/reserver`, `user.page`, `user.logout`, `user.login` et
`user.register`. Il n’est pas nécessaire de réintroduire un logo ou une
navigation dans la carte d’authentification.

Le prototype reprend une version textuelle compacte du contexte de marque et
les liens de pied de page proposés par la PR #94. Ces éléments servent
uniquement à vérifier la compatibilité de composition ; ils ne proposent pas
de nouvelle coque Drupal.

### Typographie et couleurs fusionnées

Le thème définit déjà la pile de copie
`system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif` et une pile de
titre équivalente. Les jetons pertinents incluent le texte très sombre
`#0b1220`, le texte secondaire `#475569` et l’accent turquoise `#0f766e`.

Le prototype emploie la pile requise `system-ui / Segoe UI / Arial`, un texte
principal `#102033`, le turquoise `#0f766e`, des surfaces `#fffaf2` et
`#fffdf8`, et des bordures assez foncées pour rester perceptibles. Le bouton
turquoise conserve un texte blanc au survol ; il ne reprend pas le survol ambre
générique qui réduirait son contraste.

### Messages système et H1

La région `content` contient une seule instance du bloc de messages, de poids
`-8`, suivie du bloc de titre de page, de poids `-7`, puis des onglets et du
contenu. Les audits d’exécution fusionnés ont confirmé un seul chemin de
messages et un seul H1 visible sur `/user/login`, `/user/register`, `/user` et
`/user/password`.

Conséquences pour l’implémentation :

- conserver ce bloc de titre comme H1 unique ;
- ne jamais ajouter de H1 dans un gabarit de formulaire ou d’entité ;
- conserver un seul bloc de messages ;
- positionner visuellement ce bloc dans le flux de la composition compte ;
- ajouter la copie contextuelle autour des enfants du formulaire, sans les
  reconstruire.

Le prototype autonome possède son propre H1 dans chaque état, car il ne charge
pas la région Drupal. Un seul état est rendu à la fois.

### Marquage Bootstrap Barrio actuel

Le gabarit Barrio `form.html.twig` rend un `<form>` ordinaire et tous ses
`children`. Les éléments de formulaire utilisent notamment :

- `.js-form-item`, `.js-form-type-*`, `.form-type-*` et `.mb-3` ;
- un vrai `<label>` placé avant le contrôle ;
- `.form-required` pour un champ obligatoire ;
- `.form-control` sur les champs de saisie ;
- `.btn.btn-primary` sur l’action principale selon le réglage actuel ;
- un `<small class="description text-muted">` relié par
  `aria-describedby` ;
- `.error`, `.is-invalid` et `aria-invalid="true"` sur un contrôle invalide.

Core Inline Form Errors n’est toutefois pas activé. Core envoie le texte des
erreurs au Messenger et le prétraitement par défaut remet la variable d’erreur
locale à `NULL`. Le contrôle invalide est donc identifiable, mais le texte
adjacent prévu par Barrio n’est pas alimenté aujourd’hui. Les erreurs en ligne
du prototype expriment la cible accessible, pas l’état livré. Leur réalisation
demande une décision explicite décrite plus bas.

La configuration synchronisée de Barrio mentionne des toasts, tandis qu’un
audit d’exécution fusionné a observé des alertes dans le flux. Cette divergence
doit être revérifiée après la PR #93. Pour les formulaires d’accès, une erreur
ne doit en aucun cas être fixée au viewport ou disparaître après dix secondes.

### CSS compte actuel

Le thème enfant ne contient aucun sélecteur dédié aux formulaires de connexion,
d’inscription, de mot de passe ou au compte. Le CSS générique de Barrio limite
certains contenus utilisateur à 400 px et présente les champs de compte sous
forme de grands pavés arrondis, sans couvrir de façon cohérente tous les états.
Les règles de formulaire de réservation du thème montrent déjà des libellés
au-dessus des champs et un focus turquoise, mais elles sont correctement
scopées et ne doivent pas être élargies au compte.

## Politique et inventaire Drupal réels

### Politique d’inscription

`drupal/config/sync/user.settings.yml` fixe :

- `register: visitors` : l’inscription anonyme est autorisée ;
- `verify_mail: true` : aucun mot de passe n’est demandé à l’inscription ;
- la notification `register_no_approval_required` : le compte actif reçoit un
  lien à usage unique ;
- `password_reset_timeout: 86400` : un lien de réinitialisation ordinaire dure
  un jour.

L’audit d’exécution existant dans
`docs/functional/account-registration-2026.md` confirme que l’inscription laisse
la session anonyme, qu’un seul message est envoyé, puis que le lien permet de
choisir le mot de passe. Il confirme également que les trois champs métier de
crédit, d’expiration et d’essai ne sont pas rendus dans l’inscription ou
l’édition de profil. Le hook
`unisonges_structure_entity_field_access()` les interdit en édition à toute
personne ne disposant pas de `administer users`. La simple absence d’un display
`register` aurait provoqué un repli vers le display par défaut, mais ce hook
d’accès s’applique ensuite : l’observation d’exécution tranche donc en faveur de
leur absence.

### Routes, formulaires, champs et actions

| État réel | Route et chemin | Formulaire | Champs et actions réellement rendus |
|---|---|---|---|
| Connexion | `user.login`, `/user/login` | `user_login_form` | `name` obligatoire, `pass` obligatoire, action de connexion. `name` accepte uniquement le nom d’utilisateur. |
| Inscription | `user.register`, `/user/register` | `user_register_form` | Adresse email obligatoire, nom d’utilisateur obligatoire, widget `managed_file` d’image facultative avec sélection et action de téléversement de repli, action de création. Aucun mot de passe. |
| Demande de réinitialisation | `user.pass`, `/user/password` | `user_pass` | Pour une personne anonyme : nom d’utilisateur ou adresse email obligatoire, explication Core et une action d’envoi. Pour une personne connectée, l’identifiant devient une valeur cachée. |
| Validation du lien | `user.reset`, `/user/reset/{uid}/{timestamp}/{hash}` | aucun | Valide et mémorise les paramètres opaques, puis redirige. |
| Entrée à usage unique | `user.reset.form`, `/user/reset/{uid}` | `user_pass_reset` | Texte explicatif et un seul bouton de connexion à usage unique. Le titre Core distingue la définition initiale du mot de passe d’une réinitialisation ultérieure. |
| Soumission du lien | `user.reset.login`, `/user/reset/{uid}/{timestamp}/{hash}/login` | action POST | Connecte la personne puis redirige vers l’édition du compte avec un jeton temporaire. |
| Choix du mot de passe | `entity.user.edit_form`, `/user/{uid}/edit` | `user_form` | Paire mot de passe/confirmation obligatoire lorsque le jeton est valide, sans mot de passe actuel ; email et autres widgets autorisés du profil restent issus du vrai formulaire. |
| Entrée compte | `user.page`, `/user` | aucun | Redirige vers le profil canonique de la personne connectée. |
| Compte | `entity.user.canonical`, `/user/{uid}` | aucun | H1 égal au nom affiché, image éventuelle, durée d’ancienneté et valeurs métier réelles lorsqu’elles existent. |
| Commandes | vue `commerce_user_orders`, `/user/{uid}/orders` | vue | Onglet « Commandes » accessible à la personne propriétaire ; ce n’est pas un historique de réservations. |

Champs techniques ou administratifs non rendus à l’inscription publique :
mot de passe sous la politique actuelle, rôles, statut, notification, langue,
fuseau horaire, alias de chemin et les trois champs métier protégés
`field_seances_restantes`, `field_pack_expire_le`, `field_essai_utilise`.

L’image facultative est un champ réel provenant du display par défaut. Son
widget Drupal n’est pas un simple `<input type="file">` : il fournit une action
« Upload » de repli, masquée lorsque l’envoi automatique JavaScript fonctionne,
et une action de retrait lorsqu’un fichier est déjà attaché. Le prototype
illustre seulement son état vide et conserve le champ, tout en laissant sa
présence comme décision de produit. L’implémentation ne devra jamais
sélectionner manuellement une liste de champs ou reconstruire ce widget : elle
doit rendre les enfants Drupal afin de rester compatible avec ses actions,
erreurs et progression réelles.

### Contrainte de connexion : nom d’utilisateur seulement

La demande initiale mentionne « email/username » pour la connexion. Or
`UserLoginForm` transmet `name` et `UserAuthentication` recherche strictement la
propriété `name`. Aucun module ou décorateur du dépôt n’ajoute la connexion par
email.

Le prototype utilise donc le libellé honnête « Nom d’utilisateur ». Le champ de
réinitialisation conserve « Nom d’utilisateur ou adresse email », fonctionnalité
réellement fournie. Permettre l’email à la connexion serait une évolution
fonctionnelle et de sécurité distincte, soumise à l’approbation du propriétaire.

### Vérification et réinitialisation

Il n’existe ni route `/user/verify`, ni méthode « lien magique » séparée. La
création initiale du mot de passe et la réinitialisation ultérieure partagent
les routes de lien à usage unique :

```text
inscription
→ message système sur la destination locale ou l’accueil
→ email contenant /user/reset/{uid}/{timestamp}/{hash}
→ /user/reset/{uid} et bouton à usage unique
→ connexion technique
→ /user/{uid}/edit?pass-reset-token=…
→ choix et confirmation du mot de passe
```

Le lien d’inscription ne conserve pas la destination de réservation. Le texte
du prototype l’indique sans suggérer que l’utilisateur est déjà connecté.

Après une demande de mot de passe, Core produit la même confirmation pour un
identifiant connu, inconnu ou inactif et redirige vers l’accueil. La cible
reprend cette non-divulgation : elle ne répète jamais l’adresse saisie et ne
confirme pas l’existence d’un compte.

Les panneaux « confirmation » du prototype sont des vues de l’état Messenger
après redirection, et non de nouvelles routes. La conception ne demande donc
aucune URL publique supplémentaire.

### Compte authentifié

Le compte actuel n’est pas un tableau de bord : `/user` redirige vers le profil
canonique et son H1 est le nom affiché. Le display peut montrer l’image, la
durée d’ancienneté, les séances restantes, la date d’expiration du pack et
l’état de l’essai lorsque ces valeurs sont réellement renseignées.

La cible met l’identité en premier, puis des actions réelles. Le prototype ne
montre aucune valeur métier, car une valeur fictive pourrait être prise pour
une donnée Uni-Songes. L’implémentation pourra rendre les valeurs existantes,
avec leurs libellés approuvés, uniquement lorsqu’elles sont présentes. Elle ne
doit pas créer de statistiques ni de bloc vide.

Les actions prouvées sont l’édition du compte, l’onglet Commerce « Commandes »,
la réservation et la déconnexion. Le prototype montre l’édition, la réservation
et la déconnexion ; l’exposition de « Commandes » dans la hiérarchie principale
reste une décision de produit, car ce n’est pas un historique de réservation.
L’utilisateur ordinaire ne dispose pas de l’annulation de compte : aucune action
correspondante n’est proposée.

## Spécification visuelle

### Structure commune

```text
en-tête fixe existant
└── cadre défilant unique
    ├── main unique
    │   └── colonne de lecture, 36 à 44 rem maximum selon l’état
    │       ├── message système unique, si présent
    │       └── surface compte opaque
    │           ├── contexte compact
    │           ├── H1 global de taille mesurée
    │           ├── introduction courte
    │           ├── formulaire ou identité
    │           └── destination secondaire
    └── futur pied de page
```

Le sélecteur d’états et l’étiquette de route du prototype sont des annotations
de démonstration. Ils ne doivent pas être implémentés dans Drupal.

### Jetons de la proposition

| Usage | Valeur de prototype | Intention d’implémentation |
|---|---|---|
| Copie | `system-ui, -apple-system, "Segoe UI", Arial, sans-serif` | Réutiliser la pile fusionnée du thème. |
| Surface | `#fffaf2`, forte `#fffdf8` | Chaude et suffisamment opaque face au fond. |
| Texte | `#102033` | Bleu-gris sombre et lisible. |
| Texte secondaire | `#475569` au minimum | Aucun gris pâle. |
| Accent | `#0f766e` | Réutiliser le turquoise Uni-Songes. |
| Focus | contour `3px`, décalé | Visible sur clavier, indépendant de la couleur de bordure. |
| Bordure | `#a8b5b0` ou plus sombre selon le contrôle | Délimitation subtile mais perceptible. |
| Rayon | 6 à 10 px | Compact, sans accumulation de pilules. |
| Ombre | une ombre douce par surface principale | Profondeur retenue, sans glassmorphism. |
| Cible | 44 px minimum | Liens d’action, boutons et résumé du sélecteur. |

Aucun dégradé, filtre de flou, illustration générique ou animation décorative
n’est ajouté.

### État par état

#### Connexion anonyme et erreur

- H1 « Se connecter » et introduction d’une phrase.
- Nom d’utilisateur réel et mot de passe, avec labels persistants.
- « Mot de passe oublié ? » comme lien calme avant l’action principale.
- Bouton principal turquoise, puis « Créer un compte » comme destination
  secondaire distincte.
- Note factuelle : un compte est nécessaire pour confirmer une réservation,
  mais la connexion ne garantit pas la disponibilité.
- En erreur, résumé relié au champ, `aria-invalid`, texte adjacent et mot de
  passe volontairement vide.

#### Inscription et erreur de validation

- H1 « Créer un compte ».
- Explication concise du lien à usage unique, de la vérification et du choix du
  mot de passe.
- Tous les contrôles réellement rendus : email, nom d’utilisateur, image
  facultative.
- Aucun mot de passe prématuré et aucun consentement marketing.
- Résumé d’erreurs et messages adjacents dans la cible accessible.

#### Confirmation après inscription

- Statut positif explicite par symbole et texte, pas uniquement par couleur.
- H1 « Vérifiez votre adresse email ».
- Trois étapes courtes et absence de bouton de renvoi non installé.
- Mention claire du maintien de la session anonyme et de la destination non
  conservée par le lien email.

#### Mot de passe et confirmation

- H1 « Réinitialiser le mot de passe ».
- Un champ réel, une explication et une action évidente.
- Après soumission, formulation conditionnelle identique pour toute saisie et
  aucune répétition de l’identifiant.
- Le bouton du lien à usage unique et le choix effectif du nouveau mot de passe
  sont inventoriés dans le parcours réel, même si les huit vues du sélecteur se
  concentrent sur la demande et sa confirmation.

#### Compte

- H1 de prototype « Mon compte », sous réserve de la décision sur le titre réel.
- Zone d’identité lisible avec un emplacement dynamique explicitement fourni
  par Drupal, sans avatar ou donnée personnelle fictive.
- Actions prouvées, sans fausse donnée de réservation.
- Point d’extension uniquement sous forme de commentaire de conception : aucun
  panneau vide n’apparaît.

## Accessibilité et adaptation

Le prototype comporte un en-tête, un `main` et un pied de page sémantiques, un
lien d’évitement, une navigation nommée pour le sélecteur et exactement un H1
dans chaque état. Le JavaScript cache et rend inerte tout état inactif, met à
jour le titre de document, place `aria-current` sur la vue active et déplace le
focus vers son H1 lors d’un changement volontaire.

Les formulaires utilisent des labels réels, `required`, les attributs
d’autocomplétion adaptés, `aria-describedby` pour les descriptions et
`aria-invalid` pour les erreurs. Les statuts emploient une icône textuelle, un
titre et un libellé. Aucun placeholder ne remplace un label.

Le focus est visible sur tous les liens, boutons, champs et résumés. Les cibles
d’action atteignent au moins 44 px. La navigation clavier suit l’ordre du DOM ;
aucun piège de focus, carrousel ou temporisation n’est introduit. Les
soumissions du prototype sont neutralisées et annoncées dans une région live.

À 320 px :

- le contenu utilise `min-width: 0` et `width: 100%` ;
- les espacements latéraux descendent à 8 px ;
- les boutons et actions deviennent empilés et pleine largeur ;
- les chaînes de route et valeurs longues reviennent à la ligne ;
- la zone d’identité passe sur une colonne ;
- seul le cadre principal défile et aucun débordement horizontal n’est requis.

Aux zooms/reflows de 100 %, 150 % et 200 %, les tailles fluides, les retours à la
ligne et l’absence de hauteur fixe sur le contenu préservent l’ordre. Le fond
reste autonome derrière la surface. Aucune animation n’est nécessaire ; la
feuille contient tout de même une garde `prefers-reduced-motion`.

## Plus petite implémentation Drupal ultérieure

Cette section décrit un transfert, pas des changements à réaliser dans cette
PR de conception.

### Ordre et fichiers proposés

Après fusion et rebase de la PR #93 :

1. ajouter une seule feuille
   `drupal/web/themes/custom/unisonges_theme/css/auth-account.css` ;
2. déclarer une bibliothèque `auth-account` dans
   `unisonges_theme.libraries.yml`, dépendante de
   `unisonges_theme/unisonges-layout` ;
3. attacher cette bibliothèque uniquement sur les routes et contextes du
   tableau suivant ;
4. ajouter le minimum de copie et de classes par des alters de formulaire ;
5. n’ajouter des gabarits Twig étroits que si les alters ne suffisent pas.

La PR #93 remplace la référence inexistante `unisonges_theme/global` par
`unisonges_theme/unisonges-layout`. L’implémentation compte ne devra jamais
restaurer `global` ni recopier les actifs de layout.

### Portée de routes exacte

| Route | Portée de l’attachement |
|---|---|
| `user.login` | Toujours. |
| `user.register` | Toujours lorsque l’accès public est autorisé. |
| `user.pass` | Toujours, en conservant la variante connectée de Core. |
| `user.reset.form` | Toujours ; ne jamais mettre en cache le contexte du lien. |
| `entity.user.edit_form` | Uniquement pour le propriétaire courant et/ou lorsque le jeton de reset valide est présent. Ne pas redessiner l’administration d’un autre compte. |
| `entity.user.canonical` | Uniquement lorsque l’entité affichée est l’utilisateur courant. Ne pas étendre la cible aux profils publics ou administratifs. |
| `view.commerce_user_orders.order_page` | Optionnel, seulement si le propriétaire approuve l’inclusion de « Commandes ». |

Ne pas utiliser uniquement `.section-user` : cette classe couvre aussi des
profils, erreurs et points d’entrée de reset qui ne partagent pas tous le même
contexte. Ajouter une classe de body explicite et conserver les classes de
formulaire réelles comme second niveau de scope.

### Alters et suggestions exactes

Alters de formulaire recommandés :

```text
unisonges_theme_form_user_login_form_alter()
unisonges_theme_form_user_register_form_alter()
unisonges_theme_form_user_pass_alter()
unisonges_theme_form_user_pass_reset_alter()
unisonges_theme_form_user_form_alter()
```

Le dernier doit vérifier la route, le propriétaire courant et le contexte de
jeton. Les copies contextuelles et liens secondaires doivent être des render
arrays utilisant des noms de route. Aucun alter ne doit recopier, filtrer ou
réordonner sans garde la liste des champs contribués.

Barrio ne fournit pas automatiquement de suggestion de formulaire basée sur
ces IDs. Si un wrapper s’avère nécessaire, ajouter d’abord un
`hook_theme_suggestions_form_alter()` très ciblé, puis utiliser exactement :

```text
form--user-login-form.html.twig
form--user-register-form.html.twig
form--user-pass.html.twig
form--user-pass-reset.html.twig
form--user-form.html.twig
```

Chaque wrapper doit rendre `{{ children }}` exactement une fois, conserver
`{{ attributes }}` et ne contenir aucun H1.

Pour le compte, `user--full.html.twig` est une suggestion Barrio prouvée mais
trop large si d’autres profils complets sont affichés. Préférer une suggestion
enfant explicite, par exemple `user__auth_account`, gardée à la route canonique
du propriétaire courant, puis `user--auth-account.html.twig`. Le gabarit doit
rendre le contenu réel et conserver ses métadonnées de cache. Il ne doit pas
imprimer d’UID dans la copie ou dans un lien fabriqué.

Les candidats `page--user--login.html.twig`,
`page--user--register.html.twig`, `page--user--password.html.twig` et
`page--user--reset.html.twig` existent dans la cascade, mais ne sont pas
recommandés : les recopier risquerait de rompre l’en-tête fixe, le main unique,
le cadre défilant, le fond et le futur pied de page.

Si l’état toast est confirmé, créer une suggestion étroite du thème
`status_messages`, par exemple `status_messages__auth_account`, puis un
`status-messages--auth-account.html.twig` dérivé du composant d’alerte Barrio.
Le bloc Drupal existant reste l’unique source ; seul son rendu en flux et sans
autohide change sur la portée autorisée.

### Titres, messages et erreurs

Les titres français demandés doivent provenir du bloc global de titre. Un alter
de titre par nom de route peut produire « Se connecter », « Créer un compte »
et « Réinitialiser le mot de passe », avec le contexte de cache `route.name`.
Le compte nécessite la décision propriétaire entre le nom d’utilisateur actuel
et « Mon compte ».

Pour obtenir les erreurs adjacentes de la cible, deux options sont possibles :

1. activer Core Inline Form Errors après analyse de son impact global ;
2. fournir un équivalent très étroit pour les cinq formulaires concernés, tout
   en laissant le récapitulatif au bloc Messenger unique.

La deuxième option minimise la portée visuelle mais demande plus de code à
maintenir. Dans les deux cas, l’erreur doit être reliée au contrôle,
`aria-invalid` doit rester présent et le résumé doit pointer vers le premier
champ invalide. Ne jamais dupliquer le même message dans un second bloc système.

Les onglets locaux Core « Voir » et « Modifier » sont aujourd’hui rendus par la
région de contenu. Leur maintien, leur déplacement visuel ou leur remplacement
par les actions de la carte est une décision ; ne pas laisser deux séries
d’actions équivalentes.

### Cache

- conserver le `no_cache: TRUE` de toutes les routes de reset ;
- préserver le cache tag `user.settings` du formulaire de compte ;
- préserver `url.query_args` et en particulier `destination` sur les demandes
  où Core le fait ;
- ajouter `route.name` aux variations de titre, bibliothèque et message ;
- utiliser un contexte de route plus précis lorsque les paramètres comptent ;
- varier la structure propre au compte par `user` et les contrôles d’accès par
  `user.permissions` ;
- varier la copie traduite par la langue d’interface ;
- préserver les cache tags de l’entité utilisateur ;
- ne jamais mettre un UID, hash, timestamp ou jeton de reset dans un rendu
  partagé, une fixture, un log ou une clé de cache contrôlée par le thème.

### Déploiement et retour arrière ultérieurs

Prérequis : PR #93 fusionnée, branche rebasée sur `release/prod`, DDEV et
Chromium rendus disponibles par leur propriétaire, dépendances Composer
installées et copie de base locale reproductible.

Commandes de staging proposées pour la future PR d’implémentation :

```bash
git fetch origin release/prod
git rebase origin/release/prod
cd drupal
ddev start
ddev composer install
ddev exec vendor/bin/drush cr
```

Aucun import de configuration n’est requis pour une implémentation purement
thème. Si le propriétaire choisit Inline Form Errors ou modifie un display,
l’export, l’import et la revue de configuration deviennent un lot distinct.

Retour arrière : revert de la future PR contenant la feuille étroite, l’entrée
de bibliothèque, les alters et éventuels wrappers, redéploiement du thème puis
`ddev exec vendor/bin/drush cr` en staging. Aucun schéma ni donnée utilisateur
ne doit être modifié par cette couche, de sorte que le retour arrière reste un
revert de code. Les commandes DDEV/Drush ci-dessus sont un plan futur ; elles
n’ont pas été exécutées pendant cette phase.

### Matrice DDEV/Chromium différée

À exécuter seulement lorsque la PR #93 libère ces ressources :

| Cas | Vérifications |
|---|---|
| `/user/login` anonyme | H1 unique, ordre label/champ, destination conservée, nom d’utilisateur fonctionnel, email seul refusé honnêtement, liens secondaires, 44 px. |
| Erreur de connexion | Un seul bloc de messages, pas de toast/autohide, erreur annoncée et reliée, mot de passe non réaffiché, focus utile. |
| `/user/register` | Email, nom et image facultative présents ; aucun mot de passe ni champ métier protégé ; action et texte de vérification exacts. |
| Validation inscription | Résumé, focus, liens vers champs, textes adjacents, doublons nom/email selon comportement Core, aucune donnée réelle dans les captures. |
| Inscription réussie | Redirection réelle avec et sans `destination`, session toujours anonyme, message unique, email local unique et aucune promesse de connexion. |
| Lien initial | Chaîne `/user/reset/...` → bouton à usage unique → édition → mot de passe/confirmation ; absence de fuite des paramètres ; destination non conservée explicitée. |
| `/user/password` | Variante anonyme et connectée, nom ou email, action unique, validation de syntaxe et limites de flood. |
| Demande connue/inconnue/inactive | Même confirmation, aucun identifiant répété, redirection actuelle conservée. |
| Lien reset valide/expiré/utilisé | Titre approprié, délai 86 400 s pour reset ordinaire, erreur unique, aucun cache partagé, 403/redirect Core conservé. |
| `/user` et `/user/{uid}` propriétaire | Redirection, H1 décidé, identité réelle, champs présents uniquement avec valeur, édition/commandes selon accès, aucune statistique inventée. |
| Accès autre compte/admin | Aucun style ou wrapper propriétaire appliqué par erreur, permissions et cache corrects. |
| Coque | En-tête fixe, fond autonome, un seul scrollframe, un main, futur footer atteignable au clavier et au défilement. |
| Reflow | 320 px et largeurs usuelles à 100 %, 150 % et 200 % ; aucun défilement horizontal ni recouvrement. |
| Entrées | Clavier seul, ordre de tabulation, focus visible, lien d’évitement, lecteur d’écran sur labels/descriptions/erreurs/statuts. |
| Préférences | `prefers-reduced-motion`, contraste forcé, zoom texte, longues traductions françaises. |
| Cache et actifs | Cache froid/chaud, agrégation CSS/JS activée/désactivée, personne anonyme/connectée, changements de permissions et de langue. |

## Chevauchement avec les PR ouvertes

Inventaire GitHub effectué le 1er septembre 2026. Aucun fichier de la présente
proposition ne chevauche une PR ouverte.

- PR #95 : `docs/functional/interactive-text-contrast-2026.md`,
  `drupal/web/themes/custom/unisonges_theme/css/styles.css`.
- PR #94 : `docs/functional/public-footer-foundation-2026.md`,
  `drupal/web/themes/custom/unisonges_theme/templates/includes/_footer.html.twig`,
  `drupal/web/themes/custom/unisonges_theme/templates/page--front.html.twig`,
  `drupal/web/themes/custom/unisonges_theme/templates/page.html.twig`.
- PR #93 : `docs/functional/theme-library-integrity-2026.md`,
  `drupal/web/themes/custom/unisonges_theme/unisonges_theme.info.yml`,
  `drupal/web/themes/custom/unisonges_theme/unisonges_theme.libraries.yml`.
- PR #92 : `docs/functional/public-hub-components-2026.md`,
  `drupal/web/themes/custom/unisonges_theme/templates/content/node--10.html.twig`,
  `drupal/web/themes/custom/unisonges_theme/templates/content/node--6.html.twig`,
  `drupal/web/themes/custom/unisonges_theme/templates/content/node--9.html.twig`,
  `drupal/web/themes/custom/unisonges_theme/templates/includes/_card-grid.html.twig`,
  `drupal/web/themes/custom/unisonges_theme/templates/includes/_public-hub-actions.html.twig`.
- PR #90 : `docs/functional/cart-ux-integration-2026.md`,
  `drupal/config/sync/views.view.commerce_cart_form.yml`,
  `drupal/scripts/apply-cart-ux-2026.sh`,
  `drupal/web/themes/custom/unisonges_theme/templates/commerce/commerce-cart-empty-page.html.twig`.
- PR #89 : `docs/functional/concert-hub-upcoming-events-2026.md`,
  `drupal/config/sync/block.block.unisonges_hub_concerts_posts.yml`,
  `drupal/config/sync/views.view.hub_concerts_posts.yml`,
  `drupal/scripts/apply-concert-hub-upcoming-events-2026.sh`.
- PR #88 : `README.md`,
  `docs/functional/legacy-cloudflare-pages-retirement-2026.md`,
  `public/_headers`, `public/_redirects`, `public/robots.txt`,
  `public/sitemap.xml`.
- PR #87 : `docs/functional/content-architecture-2026.md`,
  `drupal/scripts/apply-content-architecture-2026.sh`.
- PR #86 : `docs/functional/reservation-entry-cleanup-2026.md`,
  `drupal/web/themes/custom/unisonges_theme/templates/content/node--8.html.twig`.
- PR #85 : `docs/functional/contact-form-mvp-2026.md`,
  `drupal/config/sync/block.block.unisonges_contact_form.yml`,
  `drupal/config/sync/webform.webform.contact.yml`,
  `drupal/scripts/apply-contact-form-mvp-2026.sh`,
  `drupal/scripts/contact-form-mvp-config.php`.
- PR #82 : `docs/functional/sitemap-robots-policy-2026.md`,
  `drupal/config/sync/simple_sitemap.bundle_settings.default.node.article.yml`,
  `drupal/config/sync/simple_sitemap.bundle_settings.default.node.concert.yml`,
  `drupal/config/sync/simple_sitemap.bundle_settings.default.node.forum_topic.yml`,
  `drupal/config/sync/simple_sitemap.bundle_settings.default.node.page.yml`,
  `drupal/config/sync/simple_sitemap.bundle_settings.default.node.stage.yml`,
  `drupal/config/sync/simple_sitemap.custom_links.default.yml`,
  `drupal/config/sync/simple_sitemap.settings.yml`,
  `drupal/config/sync/simple_sitemap.type.default_hreflang.yml`,
  `drupal/scripts/apply-sitemap-policy-2026.sh`, `drupal/web/robots.txt`.

Points de coordination futurs : la bibliothèque dépend de la PR #93, la coque
et le footer de la PR #94, et `styles.css` appartient actuellement à la PR #95.
La future implémentation doit donc utiliser sa propre feuille et se rebaser
après ces intégrations.

## Checklist visuelle réutilisable pour les formulaires

- **Échelle du titre** : un H1 lisible, jamais monumental, distinct du texte
  sans repousser le formulaire sous la ligne de flottaison.
- **Contraste du texte** : texte principal sombre, texte secondaire au moins au
  niveau de `#475569` sur surface claire, aucun état communiqué par la seule
  couleur.
- **Espacement des champs** : label collé à son contrôle, description et erreur
  associées, puis séparation généreuse avant le champ suivant.
- **Visibilité du focus** : contour de 3 px perceptible sur liens, champs,
  boutons et contrôles composites, y compris en erreur.
- **Hiérarchie des boutons** : une action principale pleine, une destination
  secondaire bordée ou textuelle, aucun groupe d’actions concurrentes.
- **Placement des erreurs** : résumé avant le formulaire et texte adjacent au
  champ, liens et associations programmatiques, aucune alerte flottante.
- **Largeur mobile** : aucune largeur minimale fixe, `min-width: 0`, boutons
  empilés, retour des chaînes longues et contrôle à 320 px.
- **États vides** : ne rien afficher si aucune information utile n’existe ;
  éviter les cadres vides et statistiques à zéro.
- **Cohérence de langue** : français naturel, même vocabulaire dans H1, action,
  email et message Core ; pas d’anglais résiduel.
- **Lisibilité du fond** : surface suffisamment opaque derrière tout texte,
  décor visible uniquement en périphérie, aucun flou décoratif nécessaire.

## Décisions nécessitant l’accord du propriétaire

1. Conserver la connexion honnête par nom d’utilisateur seulement, ou lancer un
   lot fonctionnel séparé pour la connexion par email.
2. Conserver l’image facultative sur l’inscription publique, ou modifier le
   display/configuration dans une PR fonctionnelle distincte.
3. Conserver le nom d’utilisateur comme H1 du compte, ou adopter « Mon compte »
   et déplacer l’identité sous le titre.
4. Afficher les valeurs réelles de séances/expiration/essai lorsqu’elles
   existent, et valider leurs libellés publics.
5. Ajouter « Commandes » aux actions principales du compte, en assumant qu’il
   s’agit de commandes Commerce et non d’un historique de réservations.
6. Conserver les confirmations Messenger sur l’accueil ou la destination, ou
   demander ultérieurement de nouvelles routes/redirections publiques.
7. Activer Core Inline Form Errors globalement, ou financer une intégration
   étroite propre aux formulaires compte.
8. Résoudre l’écart entre la configuration toast et l’alerte en flux observée,
   puis interdire l’autohide pour les erreurs d’authentification.
9. Conserver les onglets locaux « Voir/Modifier » ou les intégrer visuellement
   aux actions de la carte sans duplication.
10. Confirmer la traduction française exacte des libellés Core, des messages
    email et des étapes « définir/réinitialiser le mot de passe ».

## Validation statique de cette proposition

Tous les contrôles ci-dessous réussissent sur les quatre fichiers finaux :

| Contrôle | Commande ou méthode | Résultat |
|---|---|---|
| HTML | `npx --yes html-validate@9.7.1 docs/prototypes/auth-account-experience/index.html` | Valide, aucune erreur. |
| CSS | `postcss@8.5.6` avec `postcss-cli@11.0.1`, sortie vers `/dev/null` | Analyse syntaxique valide. |
| Format CSS et second parseur | `npx --yes prettier@3.6.2 --check docs/prototypes/auth-account-experience/prototype.css` | Valide. |
| JavaScript | `node --check docs/prototypes/auth-account-experience/prototype.js` | Valide. |
| Structure du prototype | Contrôle Node en mémoire | Huit états exacts, un H1 par état, un main, un header, un footer, 60 IDs uniques, labels et références ARIA résolus. |
| Ressources et fonctions | Contrôle Node et recherche de motifs | Aucun URL externe, `url()`, `@import`, police, image, dépendance, méthode de connexion ou fonction produit inventée. |
| Routes | Contrôle Node et inventaire des `href` | Routes Drupal/fallback connues uniquement ; aucun nouveau chemin public. |
| Encodage | Lecture UTF-8 et comparaison `normalize('NFC')` | Quatre fichiers UTF-8/NFC, aucun caractère de remplacement. |
| Focus et reflow | Contrôle des règles et revue statique | Focus visible, garde reduced-motion, `min-inline-size: 0`, breakpoint 320 px et actions empilées présents. |
| Secrets | `npx --yes @secretlint/quick-start@13.0.5` limité aux quatre fichiers | Aucun secret détecté. |
| Fichiers | Garde `git status --porcelain -z --untracked-files=all` | Exactement les quatre chemins autorisés. |
| Chevauchement | GitHub CLI, 11 PR ouvertes et 49 chemins comparés | Aucun chevauchement. |
| Diff | `git diff --check` sur l’index final | Valide. |

Trois revues statiques indépendantes ont été conduites sans navigateur :

- **UX** : les huit tâches/états, la vérité fonctionnelle, le focus après
  changement d’état, la hiérarchie des confirmations, le feedback de soumission
  et le reflow ont été revus ; aucun bloqueur ne subsiste ;
- **design visuel** : l’échelle, les contrastes, l’espacement, les ombres, la
  retenue du fond et le maintien des actions à 320 px ont été revus ; aucun
  bloqueur ne subsiste ;
- **accessibilité et intégrité Drupal** : landmarks, H1, labels, erreurs,
  statuts, focus, navigation par fragments, champs/actions réels et parcours à
  usage unique ont été revus ; aucun bloqueur ne subsiste.

Les corrections issues de ces revues ont notamment supprimé un faux monogramme
et un décor non prouvé, aplati les messages, préservé « Réserver » à 320 px,
évité un ordre H2/H1 inversé, ciblé le focus sur les statuts, neutralisé les
formulaires même sans JavaScript via `inert`, retiré les données fictives du
compte et précisé le fonctionnement du widget `managed_file`.

Aucun test DDEV, Docker, Drush, Chromium, navigateur ou VPS n’a été exécuté dans
cette phase.
