# Flux reservation paiement sur place 2026

Ce document decrit le comportement implemente pour les commandes de cours avec
paiement sur place. La source de verite reste Drupal Commerce pour les commandes,
Drupal Webform pour les reservations, et la table interne du module pour les
droits "cours à payer".

## Regle metier

- Paiement en ligne confirme : les credits payes sont ajoutes au compte eleve
  apres confirmation du paiement, comme avant.
- Paiement sur place : une commande de cours `completed` mais non payee ouvre un
  droit de reservation immediat, sans marquer la commande comme payee.
- Une reservation issue de ce droit bloque le creneau comme une reservation
  normale et consomme un droit "cours à payer".
- Les logs, notifications Webform et payloads agenda dry-run indiquent
  explicitement `COURS À PAYER` / `payment_status=to_pay` pour ces reservations.
- Quand le paiement sur place est confirme plus tard, les droits deja consommes
  par une reservation ne generent pas de credit supplementaire.

## Modele de donnees

Le module ajoute la table `unisonges_structure_course_to_pay_right` via
`unisonges_structure_update_11003()`.

Champs principaux :

- `order_id` : commande Commerce source.
- `uid` : eleve proprietaire.
- `source_order_item_id` : ligne de commande source.
- `credit_index` : unite de credit stable dans la ligne de commande.
- `product_bundle` : bundle produit au moment de la creation du droit.
- `remaining_to_pay_credits` : `1` tant que le droit peut reserver, `0` apres
  consommation, paiement ou annulation.
- `webform_submission_id` : submission qui a consomme le droit.
- `status` : `pending_payment`, `consumed`, `paid` ou `cancelled`.
- `created`, `changed`, `consumed`, `paid`, `cancelled` : horodatages d'audit.

La cle unique `(order_id, source_order_item_id, credit_index)` rend la creation
idempotente : re-sauver la meme commande ne cree pas de droits en double.

## Creation des droits paiement sur place

`_unisonges_structure_apply_course_rights_from_order()` garde le flux paye
existant pour les commandes payees.

Pour une commande de cours `completed`, non payee, et associee a la passerelle
Commerce manuelle (`plugin: manual`, passerelle `manual` dans la configuration
actuelle), le module cree des droits `pending_payment` :

- `cours_essai` : 1 droit si l'essai n'est pas deja utilise et s'il n'existe pas
  deja un droit d'essai paiement sur place actif.
- `cours_deb_inter` et `cours_avance` : 1 droit par quantite commandee.
- `pack_4_deb_inter` : 4 droits par quantite commandee.

Aucun credit paye n'est ajoute a `field_seances_restantes` pendant cette etape
et la commande reste non payee.

## Validation et consommation de reservation

`_unisonges_structure_user_can_book()` autorise la reservation si l'eleve a :

- au moins un credit paye valide dans `field_seances_restantes`, ou
- au moins un droit `pending_payment` dans la table paiement sur place.

Lors de l'insertion de la submission Webform, le module consomme le droit avec
un verrou par utilisateur :

- si un credit paye valide existe, il est consomme en priorite et le compteur
  paye est decremente ;
- sinon, le plus ancien droit `pending_payment` est marque `consumed`, rattache a
  la submission, et la reservation est marquee `COURS À PAYER`.

Choix documente : lorsqu'un eleve a a la fois des credits payes et des droits
paiement sur place, le credit paye est consomme en premier. Le formulaire de
reservation ne porte pas de marqueur fiable indiquant que la reservation courante
doit utiliser precisement la derniere commande paiement sur place ; ce choix evite
d'etiqueter "à payer" une reservation qui pouvait etre couverte par un credit
deja paye.

Si une course concurrente laisse la submission sans droit disponible au moment de
l'insertion, la valeur de reservation est remplacee par une valeur inactive
`|0`, afin de ne pas bloquer gratuitement le creneau.

## UX checkout et confirmation reservation

Le checkout de cours reste reserve aux comptes connectes. Si un visiteur anonyme
arrive sur le checkout avec un panier de cours, le module masque l'action de
continuation et affiche un message explicite :
"Connectez-vous ou creez un compte pour reserver un cours." Les liens de
connexion et creation de compte gardent la destination du checkout.

Quand un utilisateur connecte reprend un panier de cours cree anonymement, le
module rattache la commande au compte courant si la commande n'a pas encore de
client. Ce rattachement est necessaire pour que les conditions Commerce de la
passerelle manuelle `Paiement sur place` voient bien le role `authenticated`.
Sans cela, le pane de paiement peut conclure a tort qu'aucune passerelle n'est
disponible pour la commande.

Apres une submission valide du formulaire de reservation, `/reserver` affiche une
confirmation recapitulative au lieu de remettre "Choisir un creneau" comme action
principale. Le recapitulatif indique :

- le creneau choisi ;
- le cours, le mode, l'instrument et le niveau ;
- le lieu ou la plateforme quand l'information est applicable ;
- l'etat de paiement : credit paye utilise, paiement sur place confirme, ou
  `COURS À PAYER - paiement sur place` ;
- les actions suivantes vers le compte ou l'accueil.

## Confirmation de paiement admin

Quand une commande paiement sur place devient payee :

- les droits `consumed` restent lies a leur reservation et ne creent pas de
  credit supplementaire ;
- les droits `pending_payment` non consommes sont convertis en credits payes
  normaux et ajoutes au compte eleve ;
- les lignes concernees passent en `paid` ;
- les reservations deja consommees sont remises en queue `pending_update` pour
  reconstruire le payload agenda avec le statut paye.

Le mail custom "Vos credits de cours sont disponibles" ne part que si des
credits payes non consommes sont effectivement ajoutes.

## Notifications, logs et agenda dry-run

Pour une reservation issue d'un droit paiement sur place non confirme :

- le log `unisonges_structure` ecrit `COURS À PAYER reservation ...`;
- le log `unisonges_booking_sync` inclut `payment=COURS À PAYER`;
- `hook_mail_alter()` prefixe le sujet de la notification admin Webform avec
  `[COURS À PAYER]` et ajoute une ligne explicite au corps du mail ;
- le mail eleve Webform ajoute une ligne prudente : "Paiement : cours à payer sur
  place." ;
- le payload Google Calendar dry-run ajoute :
  - `extendedProperties.private.payment_status = to_pay`
  - `payment_label = COURS À PAYER`
  - `payment_source = pay_on_site`
  - `commerce_order_id`
  - `commerce_order_item_id`
  - `COURS À PAYER` dans le `summary`
  - les lignes `payment_status`, `payment_label` et `payment_source` dans la
    `description`.

Aucun appel Google reel n'est ajoute par ce flux. La table
`unisonges_structure_booking_gcal_sync` continue a stocker seulement le payload
dry-run et les actions en attente.

## Deploiement

La PR ajoute un hook de schema. Commande a executer sur l'environnement cible
apres deploiement du code :

```bash
cd /var/www/...
drush updb
drush cr
```

Ne pas lancer de configuration import pour ce changement : aucun export
`config/sync` n'est modifie.

## Tests attendus

- Commande de cours payee en ligne : credits ajoutes apres paiement confirme,
  mail credits envoye, reservation suivante consomme un credit paye.
- Commande paiement sur place `completed` non payee : aucun credit paye ajoute,
  droit `pending_payment` cree, CTA reservation visible en fin de checkout.
- Reservation avec uniquement un droit paiement sur place : submission creee,
  creneau bloque, droit marque `consumed`, `field_seances_restantes` inchange,
  logs/mail/payload indiquent `COURS À PAYER`.
- Paiement admin apres reservation : aucun credit supplementaire pour la
  reservation deja consommee ; les droits non consommes deviennent des credits
  payes.
- Garde-fous : anonymes toujours bloques au submit, doublon meme creneau refuse,
  capacite respectee, re-sauvegarde de commande sans doublon de droits, aucune
  modification `config/sync`, aucun appel Google reel.
