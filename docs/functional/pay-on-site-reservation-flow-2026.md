# Audit pay-on-site reservation flow 2026

Audit statique du flux "paiement sur place" puis reservation. Aucune commande de
production, aucun import de configuration, aucun appel Google API et aucune
modification de logique metier n'ont ete effectues.

## Perimetre inspecte

- `drupal/web/modules/custom/unisonges_structure/unisonges_structure.module`
- `drupal/web/modules/custom/unisonges_structure/unisonges_structure.install`
- `drupal/web/modules/custom/unisonges_structure/src/GoogleCalendar/BookingCalendarSyncService.php`
- `drupal/config/sync/commerce_payment.commerce_payment_gateway.manual.yml`
- `drupal/config/sync/webform.webform.cours_particuliers_reservation.yml`
- `docs/functional/purchase-checkout-ux.md`
- `docs/functional/transactional-emails.md`
- `docs/dev/google-calendar-sync-plan.md`
- `docs/dev/ddev-testing.md`

## Comportement actuel

### Commerce et paiement sur place

- La passerelle Commerce `manual` est libellee "Paiement sur place" et accessible
  aux utilisateurs authentifies. Son instruction exportee dit actuellement :
  "Paiement sur place (espèces / sur site). Réservation autorisée après
  validation du formulaire." (`drupal/config/sync/commerce_payment.commerce_payment_gateway.manual.yml`).
- Le module surcharge toutefois l'UX checkout pour les commandes de cours :
  paiement en ligne = credits apres confirmation du paiement ; paiement sur place
  = credits et droit de reserver uniquement apres reception et validation du
  paiement par l'equipe (`_unisonges_structure_checkout_form_ux_overrides()`).
- En fin de checkout, le bouton "Réserver un cours" n'est ajoute que si la
  commande est `isPaid()`. Une commande sur place non payee affiche seulement que
  les credits et le droit de reserver seront ouverts apres validation paiement
  (`_unisonges_structure_checkout_completion_text()`).

### Attribution des droits de cours

- `hook_entity_insert()` et `hook_entity_update()` appellent
  `_unisonges_structure_apply_course_rights_from_order()` pour les commandes
  Commerce.
- L'attribution s'arrete si la commande n'est pas `completed`, puis si
  `$order->isPaid()` est faux. Une commande manuelle terminee mais impayee ne
  modifie donc pas `field_seances_restantes`, `field_essai_utilise` ou
  `field_pack_expire_le`.
- Les credits payes sont ajoutes par bundle produit :
  `cours_essai` ajoute au plus un credit d'essai, `cours_deb_inter` et
  `cours_avance` ajoutent la quantite commandee, `pack_4_deb_inter` ajoute
  quatre credits par quantite et prolonge la date d'expiration.
- Le mail custom "Vos crédits de cours sont disponibles" part uniquement apres
  ajout effectif de credits.

### Validation de reservation

- `/reserver` affiche le webform `cours_particuliers_reservation` sur le noeud de
  reservation.
- Les anonymes peuvent voir les disponibilites mais le submit est masque.
- Les utilisateurs connectes doivent passer `_unisonges_structure_user_can_book()` :
  le champ `field_seances_restantes` doit exister, etre strictement positif, et
  le pack ne doit pas etre expire.
- La validation serveur repete ce garde-fou avant les controles de creneau,
  verrou temporaire, doublon meme utilisateur et capacite.
- A l'insertion d'une submission valide, le module decremente exactement un
  credit, vide la date d'expiration si le solde tombe a zero, puis queue
  `pending_create` pour la synchro Google interne.
- Consequence actuelle : un eleve avec zero credit et une commande "paiement sur
  place" impayee ne peut pas reserver.

### Emails et notifications admin

- Le message de confirmation inline et l'email eleve disent que la "demande de
  reservation" a ete enregistree.
- L'email admin a pour sujet "Nouvelle demande de réservation cours particuliers"
  et liste compte eleve, creneau, mode, instrument, niveau, telephone, visio,
  adresse et notes.
- Aucun champ ou libelle ne signale aujourd'hui "cours à payer" dans les emails
  Webform.

### Queue Google dry-run et payload agenda

- La table `unisonges_structure_booking_gcal_sync` stocke `sid`,
  `submission_uuid`, `google_event_id`, `sync_status`, `sync_action`,
  `reservation_value`, `payload_json`, `last_error`, `created`, `changed`,
  `last_synced` et `cancelled`.
- Le payload dry-run contient `summary`, `location`, `description`, `start`,
  `end` et des `extendedProperties.private` avec l'identite Drupal de la
  submission.
- Le `summary` est construit comme "Cours particulier - instrument - mode -
  eleve". La `description` liste sid, uuid, uid, telephone, instrument, niveau,
  notes et pret de didgeridoo.
- Aucun champ de table, propriete privee, summary ou description ne porte
  aujourd'hui le statut "cours à payer".

## Comportement cible

- Un eleve authentifie qui choisit le paiement sur place doit pouvoir reserver un
  creneau sans attendre la confirmation de paiement admin.
- La reservation doit etre une vraie reservation Drupal/webform_booking : elle
  bloque le creneau, respecte les doublons et la capacite, et queue la synchro
  interne comme une reservation normale.
- Cette reservation doit etre marquee comme "cours à payer" dans les surfaces
  admin et dans les futurs payloads agenda/Google.
- Cette voie ne doit pas ajouter de credit normal paye dans
  `field_seances_restantes`.
- Si un paiement sur place est confirme plus tard, la confirmation ne doit pas
  creer un credit supplementaire pour une seance deja reservee comme "cours à
  payer".

## Points de risque

- Ne pas utiliser `field_seances_restantes` comme simple raccourci pour ouvrir la
  reservation sur place : cela rendrait l'impaye indistinguable d'un credit paye.
- Le flux actuel attribue les credits des qu'une commande `completed` devient
  `isPaid()`. Si une commande sur place a deja servi a reserver, il faut eviter
  le double effet "reservation deja prise" puis "credit ajoute a posteriori".
- L'email Webform peut partir avant un hook d'insertion tardif selon l'ordre des
  handlers. Le marqueur "cours à payer" doit donc etre pose avant l'envoi, ou les
  emails doivent passer par un handler/controlleur explicitement maitrise.
- Une commande sur place avec quantite superieure a 1 doit ouvrir au plus le
  nombre correspondant de reservations a payer, de facon idempotente.
- Plusieurs commandes manuelles impayees pour le meme utilisateur doivent etre
  consommees dans un ordre stable et auditables.
- Le payload Google seul ne suffit pas comme source de verite : le statut doit
  etre relu depuis Drupal/submission/order si la queue est reconstruite.
- Toute evolution de schema pour la queue Google doit rester additive et
  deployable par `updb`, sans rendre les lignes existantes invalides.
- La configuration exportee de la passerelle manuelle dit deja "reservation
  autorisee apres validation du formulaire", alors que le code bloque encore la
  reservation sans credit. Il faudra aligner texte et logique dans la meme PR.

## Phases d'implementation proposees

1. Definir le modele de droit "reservation sur place a payer".
   - Representer la source de reservation separement des credits payes :
     `paid_credit` vs `pay_on_site`.
   - Lier chaque reservation sur place a une commande/order item ou a une unite
     commandee consommable.
   - Prevoir l'etat `cours_a_payer` pour stockage machine, et le libelle admin
     exact "cours à payer".

2. Adapter l'eligibilite et la consommation de reservation.
   - Remplacer le controle unique `_unisonges_structure_user_can_book()` par un
     helper qui retourne la source utilisable : credit paye ou reservation sur
     place disponible.
   - Garder les controles de creneau, doublon, verrou et capacite inchanges.
   - A l'insertion, decrementer un credit uniquement pour `paid_credit`.
   - Pour `pay_on_site`, ne pas decrementer `field_seances_restantes`, marquer la
     submission et consommer une unite de commande sur place.

3. Verrouiller la transition paiement admin.
   - Quand la commande manuelle devient payee, ne grant que les quantites non
     deja consommees par des reservations "cours à payer".
   - Mettre a jour le statut admin de la reservation deja prise, par exemple
     "paye" ou "paiement confirme", sans ajouter de credit silencieux.

4. Rendre le statut visible.
   - Ajouter un champ ou une donnee de submission exploitable dans les emails.
   - Modifier l'email admin pour afficher "cours à payer" de maniere explicite.
   - Garder l'email eleve prudent : reservation enregistree, paiement a effectuer
     sur place.

5. Etendre le payload agenda/Google.
   - Ajouter le statut au `summary`, a la `description` et aux
     `extendedProperties.private`.
   - Si l'admin agenda lit la table de queue, ajouter des colonnes durables
     `payment_status`, `payment_source` et eventuellement `commerce_order_id`.
   - Conserver Drupal/webform_booking comme source de verite, pas Google.

6. Deployer en deux temps.
   - PR 1 : modele, validation, stockage, emails Webform, tests locaux dry-run.
   - PR 2 : payload agenda/Google et eventuelles vues admin, apres validation du
     flux interne.

## Fichiers probablement a modifier plus tard

- `drupal/web/modules/custom/unisonges_structure/unisonges_structure.module`
  - `_unisonges_structure_checkout_form_ux_overrides()`
  - `_unisonges_structure_checkout_completion_text()`
  - `_unisonges_structure_user_can_book()`
  - `unisonges_structure_booking_form_validate()`
  - `unisonges_structure_webform_submission_insert()`
  - `_unisonges_structure_apply_course_rights_from_order()`
  - `unisonges_structure_build_google_calendar_dry_run_payload()`
  - `_unisonges_structure_build_booking_google_summary()`
  - `_unisonges_structure_build_booking_google_description()`
- `drupal/web/modules/custom/unisonges_structure/unisonges_structure.install`
  - uniquement si une table ou des colonnes durables sont ajoutees.
- `drupal/web/modules/custom/unisonges_structure/src/GoogleCalendar/BookingCalendarSyncService.php`
  - si le service doit preserver ou reconstruire des champs payload/statut.
- `drupal/web/modules/custom/unisonges_structure/config/schema/unisonges_structure.schema.yml`
  - uniquement si une configuration pilotable est ajoutee.
- `drupal/config/sync/webform.webform.cours_particuliers_reservation.yml`
  - champ/cachet de statut, confirmation eleve et notification admin.
- `drupal/config/sync/commerce_payment.commerce_payment_gateway.manual.yml`
  - texte d'instruction aligne avec le nouveau flux.
- `drupal/config/sync/views.view.booking_submissions.yml`
  - si l'agenda/admin doit afficher directement le statut de paiement.
- `drupal/scripts/test-local-commerce-credit-flow.sh`
  - si les tests locaux Commerce existants sont etendus dans une PR autorisee.
- `docs/dev/ddev-testing.md`
  - pour documenter les nouvelles commandes de verification locales.

## Tests necessaires

- Checkout paiement sur place :
  - commande de cours `completed` mais impayee ;
  - aucun credit ajoute ;
  - pas de mail "Vos crédits de cours sont disponibles" ;
  - CTA ou instruction vers `/reserver` disponible pour le nouveau flux.
- Reservation sans credit mais avec droit sur place :
  - formulaire reservable pour l'utilisateur connecte ;
  - submission creee ;
  - creneau bloque ;
  - pas de decrement de `field_seances_restantes` ;
  - une unite de commande sur place consommee ;
  - statut "cours à payer" stocke.
- Garde-fous :
  - zero credit et aucune commande sur place disponible reste bloque ;
  - anonymes toujours bloques au submit ;
  - doublon meme utilisateur/creneau toujours refuse ;
  - capacite du creneau toujours respectee ;
  - deux soumissions concurrentes ne consomment pas la meme unite.
- Paiement admin apres reservation :
  - la commande devient payee sans ajouter un credit pour la seance deja reservee ;
  - les quantites non consommees, si elles existent, restent traitables sans
    double attribution.
- Flux paye existant :
  - paiement en ligne paye continue a ajouter les credits ;
  - reservation payee continue a decrementer exactement un credit ;
  - packs, expiration et cours d'essai restent inchanges.
- Emails :
  - email admin contient "cours à payer" pour le flux sur place ;
  - email eleve confirme la reservation et rappelle le paiement sur place ;
  - flux paye ne montre pas "cours à payer".
- Queue Google dry-run :
  - une reservation sur place cree `pending_create` ;
  - `payload_json` contient "cours à payer" dans les champs convenus ;
  - aucune requete Google n'est envoyee en dry-run ;
  - les reservations payees gardent un payload normal.

## Strategie de rollback

Pour cette PR d'audit, le rollback est un simple revert du fichier de
documentation.

Pour une future PR fonctionnelle :

- Prevoir un changement deployable en avant et arriere : schema additif,
  nouveaux champs optionnels, valeurs par defaut neutres.
- En cas de regression avant exploitation, revert code/config et `drush cr`
  suffisent si aucun schema n'a ete ajoute.
- Si des colonnes/table ont ete ajoutees, ne pas les supprimer dans l'urgence :
  les laisser inutilisees ou livrer un hook de nettoyage dedie apres export des
  lignes concernees.
- En cas de reservations sur place creees par erreur, desactiver d'abord
  l'eligibilite sur place, lister les submissions marquees `cours_a_payer`,
  annuler ou restaurer les reservations cote Drupal, puis queue `pending_cancel`
  seulement si la synchro agenda reelle a deja ete activee.
- Ne jamais utiliser Google Calendar comme source de rollback ; la source de
  verite reste Drupal/webform_booking et les commandes Commerce.
