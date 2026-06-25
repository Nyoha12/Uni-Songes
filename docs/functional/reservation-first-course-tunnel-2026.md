# Tunnel reservation-first cours 2026

Audit statique du parcours cours apres le revert de la PR #63 par la PR #64.
Aucun navigateur, DDEV, paiement reel, appel Google ou environnement distant n'a
ete utilise pour ce document.

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
- Les details metier requis par le Webform doivent etre collectes avant le choix
  du paiement.
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
    `destination=/reservation-cours` ;
  - les utilisateurs connectes choisissent d'abord le cours ;
  - ils choisissent ensuite le creneau via l'element `webform_booking`
    configure sur le Webform existant ;
  - cette validation de creneau verifie le format, la capacite et les conflits
    de reservations existantes, sans appeler `_unisonges_structure_user_can_book`
    et donc sans exiger de droit paye avant le choix du creneau ;
  - ils renseignent ensuite les details du cours a partir des libelles, options,
    patterns et champs conditionnels lus sur le Webform existant :
    `mode_cours`, `telephone`, `instrument`, `niveau_cours`,
    `plateforme_visio` si le mode est `visio`, `adresse_domicile` et
    `code_postal_domicile` si le mode est `domicile`, `didgeridoo_pret` si
    l'instrument est `didgeridoo`, et `notes_supplementaires` si utile ;
  - les cles Webform verifiees dans `cours_particuliers_reservation` sont :
    `mode_cours=visio|studio|domicile`, `instrument=guimbarde|didgeridoo`,
    `plateforme_visio=zoom|google_meet|skype|whatsapp|autre`,
    `didgeridoo_pret=oui|non`; `adresse_domicile` est un textarea conditionnel
    au mode `domicile`, et `code_postal_domicile` est conditionnel au mode
    `domicile` avec le pattern `^\d{5}$` ;
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
  marqueurs internes : les champs metier requis et conditionnels du parcours
  choisi sont presents et valides avant creation.
- Aucun changement `config/sync`, Composer, DDEV, script de deploiement ou prix
  Commerce n'est ajoute.
- Aucun appel Google reel n'est ajoute.
- La table et le modele PR #62 `COURS À PAYER` ne sont pas modifies.

## Ce qui est cable dans le code

- Parcours paiement sur place :
  - choix du cours ;
  - choix du creneau sans exigence de credit/paiement prealable ;
  - collecte et validation serveur des details Webform obligatoires et
    conditionnels ;
  - revalidation du format, de la capacite et des conflits juste avant la
    confirmation ;
  - creation d'une commande Commerce manuelle non payee ;
  - creation puis consommation d'un droit `COURS À PAYER` lie a cette commande ;
  - creation de la submission Webform de reservation avec les details metier
    complets ;
  - confirmation affichee uniquement apres verification du statut
    `COURS À PAYER`.

## Ce qui n'est pas encore complet

- Le paiement en ligne ne rattache pas encore le creneau au panier ou a la
  commande Commerce.
- Le parcours paiement sur place n'a pas ete teste en navigateur/DDEV dans cette
  PR ; la PR doit rester draft tant que ce test runtime n'est pas fait.

## Prochaine PR requise

- Tester en navigateur/DDEV le parcours paiement sur place complet : cours,
  creneau, paiement sur place, commande manuelle, droit `COURS À PAYER`,
  submission Webform, mails et queue Google dry-run.
- Ajouter le handoff paiement en ligne : creer le panier/commande Commerce avec
  le contexte creneau, rediriger checkout, puis finaliser la Webform submission
  sans redemander le creneau.
- Durcir la concurrence : conserver le verrou de confirmation finale, ajouter
  une expiration propre des selections temporaires et garder un message clair si
  le creneau est pris avant paiement.
- Tester en navigateur/DDEV les parcours connecte, anonyme, paiement sur place,
  paiement en ligne, retour checkout, conflits, capacite et mails.
