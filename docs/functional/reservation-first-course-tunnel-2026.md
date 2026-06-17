# Tunnel reservation-first cours 2026

Audit statique du parcours cours apres le revert de la PR #63 par la PR #64.
Aucun navigateur, DDEV, paiement reel, appel Google ou environnement distant n'a
ete utilise pour ce document.

## Routes et formulaires actuels

- `/reserver` : page node `8`, rendue par
  `drupal/web/themes/custom/unisonges_theme/templates/content/node--8.html.twig`.
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
4. Choix du paiement :
   - paiement en ligne ;
   - paiement sur place.
5. Confirmation.

Regles fonctionnelles :

- Aucun message public ne doit presenter un pack ou un credit requis comme
  condition prealable avant le choix du creneau.
- Un utilisateur connecte doit pouvoir choisir le cours et le creneau avant que
  le site demande le paiement.
- Le choix du paiement doit etre explicite apres la selection cours + creneau.
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
    creneau, paiement, confirmation ;
  - les messages de blocage ne disent plus d'acheter un pack ou des credits
    avant de choisir un creneau ;
  - le submit guard existant reste en place pour eviter de creer des
    reservations sans droit rattache.
- Aucun nouveau chemin public n'est ajoute.
- Aucun changement `config/sync`, Composer, DDEV, script de deploiement ou prix
  Commerce n'est ajoute.
- Aucun appel Google reel n'est ajoute.
- La table et le modele PR #62 `COURS À PAYER` ne sont pas modifies.

## Reste a faire

- Ajouter un petit controleur/formulaire dedie au tunnel reservation-first, ou
  une evolution equivalente testee, qui stocke temporairement compte, cours et
  creneau.
- Definir le modele de stockage temporaire : session, private tempstore ou entite
  interne expirante, avec duree de vie et nettoyage.
- Creer le choix de paiement apres selection cours + creneau :
  - paiement sur place : creer ou rattacher une commande manuelle et un droit
    PR #62, puis soumettre/consommer la reservation comme `COURS À PAYER` ;
  - paiement en ligne : creer le panier/commande Commerce avec le contexte
    creneau, rediriger checkout, puis confirmer sans redemander le creneau.
- Proteger la concurrence : verrou de creneau pendant la confirmation finale,
  expiration propre des selections temporaires, message clair si le creneau est
  pris avant paiement.
- Tester en navigateur/DDEV les parcours connecte, anonyme, paiement sur place,
  paiement en ligne, retour checkout, conflits, capacite et mails.
