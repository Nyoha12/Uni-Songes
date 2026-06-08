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
- Credit ou pack apres achat : `unisonges_structure` envoie un email dedie a
  l'eleve quand les droits de cours sont effectivement appliques. Cet envoi
  reste derriere les memes garde-fous que l'attribution des credits : commande
  `completed`, commande payee, puis ajout reel de credit.

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
- Le recu Commerce confirme l'achat, tandis que l'email droits de cours indique
  les credits ajoutes, les credits disponibles et la validite du pack si
  pertinent.
- Pour les paiements non finalises automatiquement, les credits ne sont pas
  appliques tant que la commande n'est pas `completed`. Toute copie client doit
  distinguer paiement recu, paiement a finaliser et credits disponibles.
- La configuration du checkout standard garde son message par defaut en anglais,
  mais `unisonges_structure` le remplace au rendu pour les commandes de cours et
  ajoute un CTA `/reserver` quand la commande est payee.

## Emails manquants pour un parcours propre

Priorite 1 :
- Email de modification ou d'annulation de reservation pour l'eleve et le
  professeur/admin.
- Rappel avant cours, par exemple 24 h avant, une fois la source de verite et la
  strategie cron stabilisees.

Priorite 2 :
- Email pack expire ou bientot expire.
- Email credits faibles ou credits epuises avec CTA achat.

## Recommandation

Garder les rappels, modifications et annulations de reservation pour une PR
dediee. Les prochains emails lies aux credits doivent conserver la meme regle :
ne jamais promettre de credits avant qu'une commande soit reellement
`completed` et payee.
