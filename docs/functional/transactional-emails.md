# Audit emails transactionnels

Audit statique du flux achat de cours puis reservation. Aucune page active n'a
ete rendue et aucun envoi email n'a ete teste.

## Perimetre inspecte

- `drupal/config/sync/webform.webform.cours_particuliers_reservation.yml`
- `drupal/config/sync/webform*.yml`
- `drupal/config/sync/commerce*.yml`
- `drupal/web/modules/custom/unisonges_structure/`
- `docs/`

## Etat actuel

### Reservation de cours

- Etudiant apres reservation : le webform `cours_particuliers_reservation`
  configure un handler Webform email vers `[current-user:mail]`, avec le
  message "Votre demande de reservation a bien ete enregistree."
- Professeur/admin apres reservation : le webform
  `cours_particuliers_reservation` configure un handler Webform email vers
  `_default`, donc vers le mail site/admin configure dans Webform.
- Le module `unisonges_structure` ne contient pas d'envoi mail custom pour les
  reservations. Il decompte les credits, journalise la transition et met en
  queue une ligne Google Calendar interne.

### Achat de cours

- Etudiant apres achat : le type de commande Commerce `default` a
  `sendReceipt: true`, donc le recu Commerce standard est configure pour les
  commandes. Le sujet de recu est laisse vide, ce qui delegue le libelle au
  comportement Commerce par defaut.
- Professeur/admin apres achat : aucune copie admin n'est configuree dans le
  type de commande (`receiptBcc: ''`).
- Credit ou pack apres achat : aucun email dedie n'est configure. Les droits de
  cours sont appliques par `unisonges_structure` uniquement quand la commande
  atteint l'etat `completed`.

### Relances et annulations

- Aucun email de rappel avant cours n'est configure.
- Aucun email d'annulation ou de modification de reservation n'est configure.
- Aucun email de pack expire, credit restant faible, credit ajoute ou credit
  consomme n'est configure.
- La synchronisation Google Calendar reste un flux interne/dry-run ; elle ne
  remplace pas une notification email client ou professeur.

## Points de vigilance

- Les emails reservation restent volontairement prudents : ils parlent d'une
  demande enregistree et ne promettent pas de synchronisation Google Calendar.
- Le recu Commerce confirme l'achat, mais ne dit pas explicitement combien de
  credits ont ete ajoutes, si un pack a une date d'expiration, ni quand
  l'utilisateur peut reserver.
- Pour les paiements non finalises automatiquement, les credits ne sont pas
  appliques tant que la commande n'est pas `completed`. Toute copie client doit
  distinguer paiement recu, paiement a finaliser et credits disponibles.
- Le message de fin de checkout par defaut est en anglais dans la configuration
  du checkout standard. Ce n'est pas un email, mais cela nuit au parcours
  transactionnel francophone.

## Emails manquants pour un parcours propre

Priorite 1 :
- Email post-achat ou extension du recu Commerce indiquant les credits ajoutes,
  la date d'expiration du pack le cas echeant, et un CTA vers `/reserver`.

Priorite 2 :
- Email de modification ou d'annulation de reservation pour l'eleve et le
  professeur/admin.
- Rappel avant cours, par exemple 24 h avant, une fois la source de verite et la
  strategie cron stabilisees.

Priorite 3 :
- Email pack expire ou bientot expire.
- Email credits faibles ou credits epuises avec CTA achat.

## Recommandation

Traiter ensuite les emails lies aux credits au moment exact ou les droits sont
appliques, pour eviter de promettre des credits avant qu'une commande soit
reellement `completed`. Garder les rappels, modifications et annulations de
reservation pour une PR dediee.
