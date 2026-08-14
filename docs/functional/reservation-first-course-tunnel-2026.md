# Tunnel reservation-first cours 2026

Audit statique du parcours cours apres le revert de la PR #63 par la PR #64,
complete par des sondes PHP dans l'environnement DDEV local. Aucun paiement
reel, appel Google ou environnement distant n'a ete utilise. Les scenarios
navigateur de bout en bout n'ont pas ete executes.

## Routes et formulaires actuels

- `/reserver` : page node `8`, rendue par
  `drupal/web/themes/custom/unisonges_theme/templates/content/node--8.html.twig`.
  Elle reste disponible pour le flux historique des eleves qui ont deja un droit
  de reservation, mais elle oriente maintenant vers le nouveau parcours guide.
- `/reservation-cours` : route applicative
  `unisonges_structure.reservation_course_tunnel`, rendue par
  `Drupal\unisonges_structure\Form\ReservationFirstCourseTunnelForm`.
- `unisonges_structure_preprocess_node()` : injecte le contexte
  `unisonges_reservation_portal` et le formulaire Webform dans la page `/reserver`.
- Webform `cours_particuliers_reservation` :
  `drupal/config/sync/webform.webform.cours_particuliers_reservation.yml`.
  Son element `reservation` est un `webform_booking` consultable par les anonymes
  et creatable par les utilisateurs connectes.
- `unisonges_structure_form_alter()` : bloque le submit Webform si l'utilisateur
  est anonyme ou si `_unisonges_structure_user_can_book()` refuse la reservation.
- `unisonges_structure_booking_form_validate()` : recontrole compte, droit de
  reservation, format de creneau, capacite et conflits.
- `unisonges_structure_webform_submission_insert()` : consomme soit une seance
  deja payee, soit un droit paiement sur place PR #62, puis queue le payload
  Google Calendar dry-run.
- Drupal Commerce :
  les produits de cours passent encore par les formulaires add-to-cart Commerce,
  le panier, puis le checkout standard `default`.
- Paiement sur place PR #62 :
  la table `unisonges_structure_course_to_pay_right` cree des droits durables
  `pending_payment`, puis les reservations consommees sont marquees
  `COURS À PAYER`.
- Le nouveau tunnel stocke temporairement la selection cours + creneau + details
  du cours dans le private tempstore `unisonges_structure` sous la cle
  `course_reservation_first_tunnel`.

## Bloqueurs actuels

- Le parcours public reste inverse : il suppose un achat, une seance payee ou un
  droit paiement sur place avant la confirmation d'un creneau.
- Le formulaire Webform sait verifier et consommer un droit au moment de la
  submission, mais il ne sait pas reserver durablement un creneau en attente
  pendant que l'utilisateur choisit ensuite son paiement.
- Si on autorise simplement un utilisateur sans droit a soumettre le Webform, le
  hook d'insertion peut marquer la reservation inactive avec une valeur `|0` afin
  de ne pas bloquer gratuitement le creneau.
- Le checkout Commerce actuel ne recoit pas le cours et le creneau selectionnes
  comme contexte obligatoire avant paiement.
- Aucun stockage temporaire teste ne relie encore compte, cours, creneau, choix
  de paiement et commande Commerce.
- Une implementation complete doit etre testee a l'execution, notamment les
  conflits de creneaux, les verrous, la creation de commande, le paiement manuel,
  le paiement en ligne et le retour checkout.

## Nouveau flux propose

1. Compte / identification.
2. Choix du cours.
3. Choix du creneau.
4. Details du cours.
5. Choix du paiement :
   - paiement en ligne ;
   - paiement sur place.
6. Confirmation.

Regles fonctionnelles :

- Aucun message public ne doit presenter un pack ou un credit requis comme
  condition prealable avant le choix du creneau.
- Un utilisateur connecte doit pouvoir choisir le cours et le creneau avant que
  le site demande le paiement.
- Les details metier reellement requis par le tunnel doivent etre collectes
  avant le choix du paiement.
- Tous les cours particuliers sont ouverts a tous les niveaux. Le niveau ne doit
  modifier ni le cours, ni le creneau, ni le prix, ni la navigation, ni le
  paiement, ni la confirmation.
- Le type historique `cours_avance` est obsolete pour ce tunnel : aucun cours
  particulier avance separe n'est propose.
- Le choix du paiement doit etre explicite apres la selection cours + creneau +
  details.
- Paiement sur place : reutiliser le modele PR #62 et marquer la reservation
  `COURS À PAYER`.
- Paiement en ligne : envoyer vers un checkout Commerce normal seulement apres
  selection cours + creneau.
- Apres selection d'un creneau, le retour checkout ne doit pas renvoyer
  l'utilisateur au choix du creneau.
- Les reservations existantes couvertes par une seance payee ou par un droit
  paiement sur place PR #62 doivent continuer a fonctionner.

## Ce qui est implemente dans cette PR

- Documentation d'audit et de cadrage du tunnel reservation-first.
- Ajustement minimal de l'entree `/reserver` :
  - le texte public presente le parcours cible dans l'ordre compte, cours,
    creneau, details, paiement, confirmation ;
  - les messages de blocage ne disent plus d'acheter un pack ou des credits
    avant de choisir un creneau ;
  - le submit guard existant reste en place pour eviter de creer des
    reservations sans droit rattache.
- Ajout du route/form `/reservation-cours` :
  - les anonymes voient les actions connexion/creation de compte avec
    `destination=/reservation-cours`, en conservant un deep-link discipline
    valide ;
  - les utilisateurs connectes choisissent d'abord une des quatre disciplines :
    cours d'essai, didgeridoo, guimbarde ou meditation / improvisation ;
  - le cours d'essai affiche son tarif unique de 10 EUR ; les trois autres
    disciplines affichent ensuite seulement le tarif normal a 25 EUR ou le
    tarif etudiant a 15 EUR ;
  - les produits Commerce publies et accessibles sont resolus par leurs SKU
    stables, sans identifiant numerique code en dur ; les packs, `cours_avance`,
    les SKU inconnus et les offres incompletes sont exclus ;
  - un fallback strict accepte seulement les deux SKU `LOCAL-FIXTURE-*` de cours
    dans DDEV (`IS_DDEV_PROJECT=true`) et sans aucun SKU de production reconnu ;
  - les deep-links `?discipline=essai`, `didgeridoo`, `guimbarde` et
    `meditation-improvisation` preselectionnent la discipline sans sauter un
    tarif requis ; toute autre valeur est ignoree ;
  - ils choisissent ensuite le creneau via l'element `webform_booking`
    configure sur le Webform existant ;
  - cette validation de creneau verifie le format, la capacite et les conflits
    de reservations existantes, sans appeler `_unisonges_structure_user_can_book`
    et donc sans exiger de droit paye avant le choix du creneau ;
  - ils renseignent ensuite les details du cours a partir des libelles, options,
    patterns et champs conditionnels lus sur le Webform existant :
    `mode_cours`, `telephone`, `plateforme_visio` si le mode est `visio`,
    `adresse_domicile` et `code_postal_domicile` si le mode est `domicile`,
    `didgeridoo_pret` seulement pour `essai` et `didgeridoo`, et
    `notes_supplementaires` si utile ;
  - l'etape details ne rend aucun selecteur `instrument`. La valeur destinee a
    la submission Webform est derivee de la discipline validee : `essai` et
    `didgeridoo` donnent `instrument=didgeridoo`, `guimbarde` donne
    `instrument=guimbarde`, et `meditation-improvisation` omet entierement la
    cle `instrument` ;
  - l'etape indique que les cours particuliers sont ouverts a tous les niveaux
    et ne rend aucun selecteur de niveau ;
  - les cles d'options lues ou alimentees par le tunnel dans
    `cours_particuliers_reservation` sont :
    `mode_cours=visio|studio|domicile`, `instrument=guimbarde|didgeridoo`,
    `plateforme_visio=zoom|google_meet|skype|whatsapp|autre`,
    `didgeridoo_pret=oui|non`; `adresse_domicile` est un textarea conditionnel
    au mode `domicile`, et `code_postal_domicile` est conditionnel au mode
    `domicile` avec le pattern `^\d{5}$` ;
  - le Webform historique conserve ses selecteurs requis `instrument` et
    `niveau_cours`, avec les anciennes options de niveau
    `debutant|intermediaire|avance`, pour `/reserver`. Ces selecteurs ne sont pas
    retires de sa configuration ; le nouveau tunnel ne rend ni `instrument` ni
    `niveau_cours` ;
  - l'etape suivante presente explicitement `Payer en ligne` et
    `Payer sur place`.
- Pour `Payer en ligne`, le tunnel redirige seulement apres selection cours +
  creneau + details vers la page du produit Commerce selectionne quand elle est
  resolvable, sinon vers `/cours`.
- Pour `Payer sur place`, le tunnel revalide le creneau, cree une commande
  Commerce manuelle non payee, cree le droit PR #62 `pending_payment`, cree la
  submission Webform, puis affiche une confirmation seulement si la reservation
  est marquee `COURS À PAYER`.
- La submission Webform n'est plus creee avec seulement `reservation` et des
  marqueurs internes : les champs metier reellement requis et conditionnels du
  parcours choisi sont presents et valides avant creation. `niveau_cours` est
  volontairement absent, sans valeur artificielle `debutant`, `intermediaire`
  ou `avance`. `instrument` est derive de la discipline, ou absent pour
  `meditation-improvisation`, et `didgeridoo_pret` n'est enregistre que pour
  `essai` ou `didgeridoo`.
- La presentation client est distincte du statut interne : la confirmation
  affiche « À régler sur place », tandis que `COURS À PAYER` reste inchange dans
  les notifications administrateur, les logs et la queue Google dry-run.
- Aucun changement `config/sync`, Composer, DDEV, script de deploiement ou prix
  Commerce n'est ajoute.
- Aucun appel Google reel n'est ajoute.
- La table et le modele PR #62 `COURS À PAYER` ne sont pas modifies.

## Ce qui est cable dans le code

- Parcours paiement sur place :
  - choix du cours ;
  - choix du creneau sans exigence de credit/paiement prealable ;
  - collecte et validation serveur des details metier obligatoires et
    conditionnels, sans collecte de niveau ;
  - revalidation du format, de la capacite et des conflits juste avant la
    confirmation ;
  - creation d'une commande Commerce manuelle non payee ;
  - creation puis consommation d'un droit `COURS À PAYER` lie a cette commande ;
  - creation de la submission Webform de reservation avec les details metier
    complets ;
  - confirmation affichee uniquement apres verification du statut
    `COURS À PAYER`.

## Correction runtime du selecteur et des retours

La version installee inspectee est `webform_booking` 1.1.11 avec Webform
6.3.0-beta7. La cause du warning etait l'utilisation directe de
`getElementDecoded('reservation')` : ce getter expose la configuration brute,
avant que Webform ajoute `#webform_key`, `#webform`, `#webform_id` et les autres
metadonnees runtime. Le FormElement de `webform_booking` lit
`#webform_key` sans garde dans ses callbacks process et pre-render.

Ajouter seulement `#webform_key` aurait supprime le warning sans rendre le
calendrier utilisable. Le plugin Webform doit aussi attacher sa bibliotheque et
ses `drupalSettings`, qui utilisent `#webform` pour interroger les routes de
disponibilite. Le tunnel charge donc maintenant l'element initialise du vrai
Webform `cours_particuliers_reservation`, avec :

- `#webform_key = reservation` ;
- `#webform = cours_particuliers_reservation` ;
- `#webform_id = cours_particuliers_reservation--reservation`, fourni par
  Webform meme s'il n'est pas consulte directement par `webform_booking` ;
- `#parents = ['reservation']` pour recevoir `slot` et `seats`.

L'element est ensuite prepare par le mecanisme supporte
`plugin.manager.webform.element::processElement()`. Toute la configuration du
Webform reste ainsi la source de verite : dates et exclusions, jours visibles,
intervalles, duree, capacite, maximum par reservation, libelles et disponibilite
calculee a partir des submissions existantes. Le submit reconsulte les jours et
creneaux exposes par le controleur de disponibilite de cette meme version avant
d'accepter une valeur pre-remplie ou nouvellement choisie. Aucun
`#webform_submission` n'est necessaire dans ce formulaire autonome et aucune
submission n'est creee pour
afficher le selecteur. La valeur d'input pre-remplie est le slot
`YYYY-MM-DD HH:MM`; le tempstore conserve la valeur canonique
`YYYY-MM-DD HH:MM|N`. Le JavaScript de cette version contrib ne surligne pas
l'ancien slot dans le calendrier. Il initialise aussi les places a `1` hors
submission Webform ; cela correspond a la configuration actuelle
`seats_slot=1` et `max_seats_per_booking=1`, mais devra etre revu si la capacite
multi-places est activee plus tard.

Pour les navigations entre etapes, le tempstore reste la source des valeurs
metier valides. Avant chaque rebuild reussi, le tunnel retire de `getValues()`
et `getUserInput()` uniquement ses champs (`course`, `reservation`, details,
paiement et bouton soumis), puis retire ses valeurs de validation transitoires.
Les `#default_value` peuvent donc rehydrater proprement l'etape cible. Un vrai
changement de cours invalide le creneau, les details, le paiement et une
eventuelle confirmation ; continuer avec le meme cours les conserve. Si le
produit stocke n'est plus une cle des cours publies accessibles, le tunnel
revient au choix du cours avec un message controle. `Recommencer` supprime le
private tempstore et applique le meme nettoyage FormState.

Une ancienne selection issue d'un produit `cours_avance` est traitee comme une
offre obsolete, y compris si le tunnel etait deja sur sa confirmation. Seuls le
cours et ses dependances creneau, details, paiement et affichage de confirmation
sont retires du private tempstore ; les entites deja persistees ne sont pas
supprimees. Le tunnel revient au choix avec un message controle. Si aucun produit
publie des types autorises n'est disponible, il affiche un seul message public
et ne propose aucune option codee en dur.

## Correction du cycle de vie des dependances

Le core Drupal installe a ete inspecte avant correction. `FormBase` utilise
`DependencySerializationTrait` et l'objet formulaire est conserve dans le
`build_info` du FormState mis en cache entre les requetes et les rebuilds.
`DependencySerializationTrait::__sleep()` appelle `get_object_vars($this)`
depuis la portee de `FormBase`, puis remplace les services visibles par leurs
identifiants dans `_serviceIds`. Les proprietes `private` declarees uniquement
par la classe enfant ne sont pas visibles dans cette portee : elles etaient donc
omises de la liste serialisee, sans identifiant de service, puis valaient `null`
apres deserialisation puisque le constructeur n'est pas rejoue.

Cela explique la meme cause racine pour les trois symptomes runtime :

- `currentAccount` nul provoquait le fatal sur `isAnonymous()` et `id()` ;
- `tempStoreFactory` nul faisait echouer la lecture/ecriture de la selection et
  affichait l'avertissement de memorisation ;
- `entityTypeManager` nul etait masque par le `catch` de
  `getWebformElement()`, qui renvoyait alors `[]` et transformait cinq options
  pourtant valides en cinq erreurs trompeuses ;
- `moduleHandler` et `webformElementManager` auraient subi la meme perte au
  prochain acces pendant un rebuild.

Le correctif utilise le mecanisme Drupal supporte : les cinq proprietes
injectees (`current_user`, `tempstore.private`, `entity_type.manager`,
`module_handler` et `plugin.manager.webform.element`) sont maintenant
`protected`. Le constructeur et `create()` restent la source unique de
l'injection. Le trait peut ainsi enregistrer leurs identifiants, puis
`__wakeup()` les recharge depuis le conteneur. Aucun `__sleep()`/`__wakeup()`
personnalise et aucun acces generalise a `\Drupal::service()` n'ont ete ajoutes.

La validation serveur derive d'abord `instrument` depuis la discipline stockee
et validee, puis charge les vraies options brutes du Webform avec
`getElementDecoded()` pour les seuls champs applicables. Un POST forge ne peut
donc ni contredire la discipline avec une autre valeur `instrument`, ni
conserver un `didgeridoo_pret` hors `essai` ou `didgeridoo`.
`plateforme_visio` n'est controle et requis que pour `mode_cours=visio`,
l'adresse et le code postal que pour `mode_cours=domicile`, et
`didgeridoo_pret` est requis et valide seulement pour `essai` et `didgeridoo`.
Une erreur de pattern du telephone reste donc independante des options non
applicables. Si les metadonnees `#options` sont reellement indisponibles, la
cause technique et les cles concernees sont journalisees dans un seul message
et la validation affiche une seule erreur globale controlee, sans produire une
erreur « valeur invalide » pour chaque option.

Les handlers avant/arriere et de redemarrage continuent tous a passer par le
meme nettoyage cible de FormState avant rebuild. Les valeurs metier validees
restent dans le private tempstore, les saisies restent dans FormState lors d'une
erreur de validation, et les anciens inputs soumis ne peuvent pas ecraser les
`#default_value` lors du retour a une etape precedente.

A la lecture du private tempstore, les anciennes dependances sont aussi
normalisees sans perdre les autres details compatibles : une valeur
`instrument` obsolete est remplacee par `didgeridoo` ou `guimbarde` selon la
discipline, retiree pour `meditation-improvisation`, et `didgeridoo_pret` est
retire des qu'il ne s'applique plus. Un vrai changement de cours ou de
discipline conserve la protection existante : creneau, details, paiement et
confirmation, devenus dependants d'une autre offre, sont invalides ensemble ;
les cles de tunnel sans dependance restent preservees.

## Regle « tous niveaux » et compatibilite Webform

Le nouveau tunnel ne contient plus `niveau_cours` dans ses champs de details,
ses champs requis, ses listes d'options, sa normalisation, sa validation de
completude ou les donnees envoyees a la submission. Aucune valeur de niveau
fictive n'est creee. Au chargement et avant chaque rebuild, une ancienne valeur
`niveau_cours` est supprimee des valeurs FormState, de l'input brut, du stockage
transitoire `course_details` et du private tempstore. Ce nettoyage cible ne
modifie ni le cours ni le creneau memorises.

Le Webform historique de `/reserver` conserve ses champs requis `niveau_cours`
et `instrument`, leurs options et leur rendu dans la configuration existante.
Le tunnel cree toutefois ses submissions directement par l'API Entity Webform :
la contrainte `#required` du formulaire Webform n'est pas rejouee lors de ce
`create()->save()`, et une submission sans ces cles est acceptee. Les
consommateurs du nouveau tunnel tolerent cette absence : les lignes de niveau
et d'instrument absentes sont omises de la description Google dry-run, et les
lignes HTML historiques vides sont retirees des mails des submissions marquees
`pay_on_site`. Les submissions historiques qui possedent ces valeurs et le flux
`/reserver` ne sont pas modifies.

Le titre Google dry-run ajoutait deja l'instrument seulement lorsqu'il avait une
valeur. Sa description ajoute maintenant elle aussi `instrument` et
`didgeridoo_pret` seulement lorsqu'ils existent. Pour les deux notifications
Webform, le `mail_alter` retire la ligne HTML `Instrument` codee en dur uniquement
pour une submission du tunnel `pay_on_site` sans instrument. Le mail client, le
mail administrateur et le payload meditation / improvisation ne contiennent donc
ni ligne vide ni instrument invente.

Le niveau n'intervient dans aucun choix de produit, calcul de disponibilite,
prix, navigation, paiement ou confirmation. L'audit du formulaire a aussi
confirme qu'il n'existait aucune branche « niveau incompatible » vers le
creneau. Dans chaque etape, le bouton primaire suivant est maintenant le premier
submit dans le DOM : « Continuer vers les créneaux », « Continuer vers les
détails », « Continuer vers le paiement » ou « Confirmer la réservation ». Une
soumission implicite avec Entree prend donc toujours la direction suivante. Les
retours « Retour au cours », « Retour au créneau » et « Retour aux détails »
restent explicites ; la bibliotheque CSS du module les place visuellement a
gauche et les actions primaires a droite.

## Nettoyage editorial de la confirmation

La route fournit deja le titre principal « Réserver un cours ». Le formulaire
n'ajoute donc plus un second titre identique. Pendant les etapes actives, une
seule introduction concise precede la progression « Cours / Créneau / Détails /
Paiement / Confirmation ». L'introduction et la progression disparaissent apres
la confirmation pour mettre le resultat en avant. Les titres internes du
formulaire sont des `h2` sous le `h1` de la page et l'etape courante utilise
`aria-current="step"`.

La confirmation paiement sur place presente uniquement :

- le titre « Réservation confirmée » ;
- le statut client « À régler sur place » ;
- le message « Votre créneau est réservé. Le règlement sera effectué sur place
  le jour du cours. » ;
- un resume non vide avec cours, creneau et mode, plus l'instrument derive pour
  essai, didgeridoo ou guimbarde ; la ligne Instrument est omise pour meditation
  / improvisation ;
- les actions « Réserver un autre cours » et « Retour à mon compte ».

L'action de nouvelle reservation supprime seulement le private tempstore du
tunnel et revient au choix du cours. Le lien de compte utilise la route Drupal
existante `user.page`. Aucun identifiant de commande, droit, statut machine ou
nouvelle action de choix de creneau n'est expose sur la confirmation.

Les titres et prix Commerce restent inchanges dans les produits, commandes,
submissions et donnees d'audit. Le tunnel presente separement un libelle client
derive de la discipline et du tarif valides, tandis que le produit et la
variation restent resolus par leur SKU au moment de la commande. Le creneau est
affiche sous la forme `14/08/2026 à 15:00`, sans modifier la valeur canonique
stockee `YYYY-MM-DD HH:MM|N`.

## Simplification progressive du choix de cours

La premiere etape ne rend plus les sept produits Commerce simultanement. Elle
rend quatre radios discipline sous forme de choix accessibles. Le groupe tarif
est pilote par `#states` pour les trois disciplines concernees, avec validation
serveur identique sans JavaScript. Le cours d'essai n'a aucun choix tarifaire a
faire et affiche seulement « Tarif unique — 10 EUR ».

La resolution utilise exclusivement les SKU suivants :

- `COURS-ESSAI-10` ;
- `COURS-DIDGERIDOO-1H-25` et
  `COURS-DIDGERIDOO-1H-ETUDIANT-15` ;
- `COURS-GUIMBARDE-1H-25` et `COURS-GUIMBARDE-1H-ETUDIANT-15` ;
- `COURS-MEDITATION-IMPRO-1H-25` et
  `COURS-MEDITATION-IMPRO-1H-ETUDIANT-15`.

Chaque variation et son produit parent doivent etre publies, accessibles et du
bundle attendu. Une discipline hors essai n'est affichee que si ses deux tarifs
sont resolvables. La valeur temporaire `sku:<SKU>` remplace l'ancienne valeur
`product:<id>` ; la commande paiement sur place recupere toujours l'entite
Commerce reelle avant de creer la ligne de commande. Le fallback local est
ferme aux SKU `LOCAL-FIXTURE-COURS-ESSAI` et
`LOCAL-FIXTURE-COURS-DEB-INTER`, uniquement quand aucun SKU production reconnu
n'est present et que DDEV expose `IS_DDEV_PROJECT=true`. Les fixtures avance et
pack ne peuvent pas entrer dans la liste. Leurs prix techniques 20/40 EUR ne
sont pas des donnees metier : ce fallback valide la resolution et le parcours
local, pas les montants de commande. Les montants publics 10/25/15 restent
portes par les SKU de production documentes, sans modifier les fixtures.

Au premier chargement apres mise a jour, une selection temporaire de la version
precedente au format `product:<id>` est migree seulement si le produit resolve
exactement un SKU autorise. Discipline, tarif et libelles sont alors rehydrates
sans perdre le creneau, les details ou l'etape. Toute selection ambigue ou
obsolete suit le reset controle existant. Aucun identifiant numerique n'est
code en dur : l'identifiant transitoire sert uniquement a charger l'entite puis
le SKU redevient la cle stable.

La feuille `unisonges_structure/reservation-first-tunnel` est attachee seulement
au formulaire `/reservation-cours`. Elle habille les radios en petits choix
lisibles et fixe l'ordre visuel gauche/droite des actions sans modifier
`styles.css` du theme.

## Validations historiques du tunnel initial

Les sondes ci-dessous ont valide la version initiale avant cette simplification.
Elles restent utiles pour le backend et les protections conservees, mais ne
decrivent pas le nouveau selecteur progressif.

Une sonde du rendu Drupal dans DDEV a confirme que le formulaire actif ne
contient plus le titre duplique, que la confirmation n'affiche ni introduction
ni progression, et que le HTML et son texte extrait ne contiennent aucun
marqueur Markdown litteral `##` ou `**`. Ces marqueurs signales provenaient donc
du copier-coller et non du DOM rendu. La meme sonde a confirme les libelles
fixtures nettoyes, le lien `/user`, l'absence de ligne vide et le nettoyage du
tempstore par « Réserver un autre cours », avec des compteurs commandes,
soumissions, droits et queue identiques avant/apres. Il s'agit d'une sonde
FormBuilder/renderer, pas d'un nouveau test dans un navigateur reel.

Le code de `webform_booking` a ete inspecte dans le projet DDEV principal sans
le modifier. PHP 8.3 dans DDEV a valide la syntaxe du formulaire. Une sonde a
charge la classe modifiee, l'a serialisee puis deserialisee, et a confirme que
les cinq proprietes retrouvent exactement les services du conteneur. La meme
sonde a confirme les options Webform du tunnel, l'erreur telephone seule, la
reussite apres correction du telephone, Studio + Guimbarde sans champ
conditionnel, Domicile avec adresse et code postal requis, et Didgeridoo avec
le choix de pret requis. Une seconde sonde a confirme l'absence du champ dans le
rendu details, la purge d'un ancien niveau dans FormState, input brut et private
tempstore, puis le passage direct de Studio + Guimbarde et Visio + Didgeridoo a
l'etape paiement sans perdre le cours ou le creneau.

Une confirmation paiement sur place locale a ensuite ete executee sous
transaction externe avec un creneau disponible calcule dynamiquement. Dans la
transaction, la submission Webform sauvegardee ne contenait ni cle ni ligne
`niveau_cours`, conservait le produit et le creneau exacts, et la reservation
avait le contexte `to_pay` / `COURS À PAYER`. La queue Google dry-run et les deux
mails rendus omettaient le niveau. Le recu Commerce et les handlers mail Webform
etaient desactives uniquement en memoire ; la transaction a ete annulee et une
verification dans un second processus a confirme l'absence de submission,
commande, ligne de commande, droit ou entree de queue residuelle. Aucun mail ni
appel Google reel n'a ete produit. Les auto-increments peuvent avoir avance
malgre le rollback.

Un test navigateur de persistance execute ensuite a confirme la creation de la
submission Webform, de la commande Commerce manuelle non payee et du droit
consomme `COURS À PAYER`, ainsi que la queue Google dry-run `pending_create`
avec `payment_status=to_pay` et l'absence de `niveau_cours`. Ce test a aussi mis
en evidence qu'un produit fixture de type `cours_avance` restait selectable ; le
filtre de cours a donc ete resserre aux deux types actuels. Aucun test navigateur
de la soumission implicite par la touche Entree n'avait alors ete execute.

Une sonde DDEV chargeant la classe exacte modifiee a ensuite confirme que le
selecteur retourne les produits publies fixtures `cours_essai` et
`cours_deb_inter`, et exclut le produit `cours_avance`, le pack et toute option
bundle de repli. Une selection `cours_avance` conservee sur l'etape confirmee a
ete nettoyee dans un tempstore de test : retour au choix, un seul avertissement,
dependances supprimees et valeur sentinelle non liee preservee. Enfin, la mise
hors publication transactionnelle des deux offres autorisees a produit la liste
vide attendue, sans radios ni action et avec un seul message controle. Le
rollback a restaure les quatre produits fixtures publies et le tempstore de
test ; le tempstore du compte utilise pour le test navigateur n'a pas ete
modifie.

## Validations DDEV de la selection progressive

Le fichier exact de ce worktree a ete charge dans le Drupal DDEV local sans le
copier dans le depot principal. Trois sondes ont reussi 205 assertions :

- 118 assertions sur les quatre disciplines, le tarif conditionnel, les SKU
  fixtures controles, les deep-links valides/invalides, les migrations et
  protections du tempstore, les retours et les libelles backend/client separes ;
- 23 assertions avec de vrais POST Form API non programmes et sans champ `op`,
  confirmant que le core choisit les quatre actions primaires suivantes et
  jamais un retour ; il s'agit d'une soumission implicite Form API, pas d'une
  frappe physique dans un navigateur ;
- 64 assertions sur le paiement sur place sous transaction annulee : commande
  et variation fixture attendues, droit consomme `COURS À PAYER`, submission,
  queue Google dry-run `pending_create`, puis compteurs, auto-increments,
  tempstore et donnees restaures.

Google est reste desactive, les handlers de mail et le recu Commerce ont ete
neutralises seulement en memoire, et aucun appel externe n'a ete effectue.

## Validations DDEV de la derivation de l'instrument

Les fichiers exacts de ce worktree ont ete charges depuis une racine Drupal
temporaire dans le DDEV local ; les chemins de reflection et les empreintes des
deux fichiers modifies ont ete controles avant les tests. Trois nouvelles
sondes ont reussi 172 assertions :

- 62 assertions Form API sur les quatre disciplines : aucun selecteur
  `instrument`, pret obligatoire et sans `#states` seulement pour `essai` et
  `didgeridoo`, derivation serveur meme face a un POST contradictoire, omission
  pour meditation / improvisation, nettoyage du tempstore, retours et
  progression avec une selection inchangee, puis invalidation des dependances
  lors d'un vrai changement de discipline ;
- 27 assertions downstream sur les corps string/Markup des mails client et
  administrateur, la description et le titre Google dry-run, ainsi que la garde
  qui laisse le flux Webform historique hors du nettoyage propre au tunnel ;
- 83 assertions transactionnelles sur quatre creneaux disponibles distincts :
  le tarif public Essai reste affiche a 10 EUR et son SKU d'essai reste sans
  tarif, les quatre confirmations paiement sur place atteignent `COURS À PAYER`,
  les submissions contiennent exactement l'instrument et le pret attendus, et
  les deux mails, la confirmation client et la queue Google `pending_create`
  omettent proprement l'instrument pour meditation / improvisation.

La derniere sonde a desactive les handlers mail et le recu Commerce uniquement
sur les objets en memoire. La transaction externe a ensuite ete annulee : les
compteurs commandes, lignes de commande, submissions, droits et queue sont
revenus a leurs valeurs initiales, et chaque entite creee par la sonde etait
absente apres rollback. Une requete DDEV dans un second processus a confirme
zero residu pour les identifiants de submissions, commandes, lignes de commande,
droits et queue concernes. Aucun cron, `processPending()`, appel Google reel,
paiement externe ou test navigateur de bout en bout n'a ete execute.

## Ce qui n'est pas encore complet

- Le paiement en ligne ne rattache pas encore le creneau au panier ou a la
  commande Commerce.

## Prochaine PR requise

- Ajouter le handoff paiement en ligne : creer le panier/commande Commerce avec
  le contexte creneau, rediriger checkout, puis finaliser la Webform submission
  sans redemander le creneau.
- Durcir la concurrence : conserver le verrou de confirmation finale, ajouter
  une expiration propre des selections temporaires et garder un message clair si
  le creneau est pris avant paiement.
- Tester en navigateur/DDEV les parcours connecte, anonyme, paiement sur place,
  paiement en ligne, retour checkout, conflits, capacite et mails.
