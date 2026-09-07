# Paiement en ligne des cours — décisions propriétaire 2026

Date : 2 septembre 2026

Référence technique :
[`online-payment-slot-handoff-2026.md`](online-payment-slot-handoff-2026.md)

Base auditée : `origin/release/prod` à
`9021fc0197fc001ac3225e879cfa2c1a0b409e88`

Statut initial : **décisions ouvertes — activation interdite**

Cette checklist est le contrat de décision du propriétaire. Les valeurs
« recommandées » sont des propositions techniques, pas des décisions prises à
sa place. Pour fermer une ligne, renseigner le choix exact, le responsable, la
date et une preuve/ticket. `Approuvé` signifie que les impacts client,
comptables et opérationnels ont été acceptés.

Valeurs de statut admises : `Ouvert`, `Approuvé`, `Refusé — alternative à
définir`, `Reporté — feature désactivée`. Toute ligne marquée **bloquante** doit
être `Approuvé` avant le gate concerné.

## Résumé des gates

Les gates sont cumulatifs : un gate ultérieur reprend toutes les décisions des
gates applicables précédents. « Parties sandbox » ou « parties Google » signifie
que la même décision sera complétée de nouveau avant production si son
périmètre change. **Ce tableau fait autorité** : si le titre d’une DEC mentionne
un gate plus tardif, le gate antérieur du tableau l’emporte. Toute modification
du release candidate, de sa configuration, de son mode ou du runbook invalide
les approbations affectées et impose leur revalidation.

| Gate | Décisions requises | Statut propriétaire |
|---|---|---|
| Début de toute PR runtime | DEC-01 à DEC-08, DEC-15 à DEC-17, DEC-20 à DEC-21, DEC-30, DEC-32 à DEC-33 | À renseigner |
| Sandbox PayPal | Gate runtime + DEC-06 à DEC-13, DEC-18 à DEC-19, DEC-22 à DEC-23, DEC-25, DEC-27, DEC-29 et parties sandbox de DEC-31 | À renseigner |
| Tableau de bord/admin | Gate runtime + DEC-09 à DEC-14, DEC-18 à DEC-19, DEC-25, DEC-27 à DEC-31 | À renseigner |
| Google | Gate runtime + DEC-19, DEC-21, DEC-26 et parties Google de DEC-29/DEC-31 | À renseigner |
| Production | Toutes les décisions | À renseigner |

## Claims, durée et expiration

### DEC-01 — Durée initiale du claim — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | 20 minutes à partir du commit serveur du claim. |
| Alternatives | 15 minutes sous forte pression de capacité ; 30 minutes pour davantage d’accessibilité et de confort mobile. |
| Impact | Plus court : davantage de captures tardives/support. Plus long : davantage de places immobilisées par abandon. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-02 — Extension autorisée — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | Une seule extension serveur au premier `payment_attempt=creating/ready` ou Payment local `pending`/`authorization`, portant l’échéance au plus à la limite dure ; jamais au refresh, retour d’onglet ou timer. CAS sur même owner/claim/order/slot. |
| Alternatives | Aucune extension ; une unique extension fixe ; extensions périodiques bornées pour un pending vérifié. |
| Point à fixer si la recommandation est refusée | Nombre d’extensions, pas et événement déclencheur exact. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-03 — Durée maximale totale — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | 45 minutes depuis la création, extensions comprises. |
| Alternatives | 30 minutes ; 60 minutes si l’accessibilité prime et que la pression de capacité est faible. |
| Règle non négociable | Un pending au-delà de la limite ne bloque pas indéfiniment la place ; une capture ultérieure suit DEC-06/07. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-04 — Avertissement d’échéance — bloquante avant mise en ligne

| Champ | À renseigner |
|---|---|
| Recommandation | Afficher l’heure serveur et prévenir 5 minutes avant ; le compte à rebours client est indicatif. |
| Alternatives | Avertissement 3 ou 10 minutes avant. |
| Texte à approuver | « Votre créneau est réservé jusqu’à {heure}. Terminez le paiement avant cette heure. » |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-05 — Expiration sans paiement — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | Libérer atomiquement la capacité ; rendre la commande dédiée obsolète et non payable ; exiger un nouveau claim. |
| Alternative | Court délai de grâce, nécessairement inclus dans la limite dure DEC-03. |
| Interdit | Ressusciter le slot ou le prix par un ancien panier/tempstore. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

## Paiement reçu après expiration

### DEC-06 — Politique initiale — bloquante avant sandbox

| Champ | À renseigner |
|---|---|
| Recommandation pilote | Aucune confirmation automatique. État `payment_received_after_expiry`, affiché « Paiement reçu, vérification en cours » ou « Action de l’association nécessaire ». Réconciliation manuelle sous les mêmes gardes atomiques. |
| Alternative A | Réacquisition automatique du slot original seulement s’il est encore disponible. |
| Alternative B | Remboursement automatique intégral, idempotent, avec circuit d'échec comptable. |
| Frontière à approuver | Seul le scellement local Drupal commité avant `expires_at` conserve la place. Une capture PayPal horodatée avant l'échéance mais découverte après que Drupal a libéré le claim est un paiement tardif ; elle ne recrée pas la garantie. |
| Order déjà `canceled` | Contrainte V1 : refund-only. Commerce ne replace pas cette commande après capture ; aucune réacquisition/alternative ne peut être finalisée depuis elle. Choisir remboursement manuel ou automatique selon DEC-10/11. |
| Interdits | Surbooking, autre slot imposé, faux succès, simple consommation d'un crédit. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-07 — Slot original devenu indisponible — bloquante avant sandbox

| Champ | À renseigner |
|---|---|
| Recommandation pilote | Proposer humainement une alternative avec consentement explicite ; sans accord, remboursement intégral selon la procédure approuvée. |
| Alternative | Remboursement automatique immédiat après preuve de capture exacte. |
| Consentement alternatif | Approuver texte, canal, durée de validité et preuve minimale. Aucun replacement claim avant consentement durable ; retrait/péremption avant commit bloque la confirmation. |
| Délai maximum de résolution |  |
| Contact responsable |  |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-08 — Surbooking — non négociable

- [ ] Approuver : aucune politique, y compris temporaire ou administrative, ne
  peut confirmer au-delà de la capacité Drupal.
- [ ] Approuver : il n’existe pas de bouton « forcer la confirmation ».
- [ ] Approuver : un autre créneau ne peut être choisi sans consentement du
  membre.

Responsable / date / preuve : _à renseigner_
Statut : Ouvert

## Support, annulation, remboursement et replanification

### DEC-09 — Propriétaire et SLA des incidents payés — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | Responsable nominatif/de rôle et résolution le jour ouvré courant ou suivant. |
| Canal principal |  |
| Horaires et délai de première réponse |  |
| Escalade technique |  |
| Escalade comptable/remboursement |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-10 — Annulation et remboursement — bloquante avant sandbox

Décider séparément chaque cas ; un remboursement de Payment n’annule pas
automatiquement une réservation.

| Cas | Règle, délai, montant/frais et notification à renseigner |
|---|---|
| Annulation par le membre avant le cours |  |
| Annulation par l’association |  |
| Absence du membre |  |
| Incident technique Uni-Songes |  |
| Paiement scellé mais aucun SID/finalisation impossible | Recommandation : conserver la place pendant les retries bornés ; si abandon décidé, remboursement intégral vérifié puis clôture atomique sans réservation et libération de la capacité. |
| Paiement reçu après expiration |  |
| Capture vérifiée après annulation locale de l'Order | Refund-only en V1, sans transition `place`, replacement claim ni réservation ; préciser délai et notification. |
| Refund intégral observé sans demande locale | Ouvrir d'abord une réconciliation et tracer l'origine ; décider qui peut reconnaître la preuve et clore un dossier sans SID. Ne jamais inventer une approbation antérieure ni annuler implicitement une réservation. |
| Cours/slot supprimé après paiement |  |
| Remboursement partiel |  |
| Litige ou chargeback |  |

Pour le cas « paiement scellé mais aucun SID », choisir explicitement entre :

- recommandation : retries locaux bornés, puis remboursement intégral
  vérifié et clôture CAS `reservation_abandoned` qui rend la place ;
- poursuite de la finalisation manuelle sous SLA, en conservant la place tant
  que le paiement reste exposé.

Il est interdit de libérer le slot ou l’éligibilité d’essai sur un simple
échec Webform, un refund partiel ou un résultat ambigu.

Choix pour ce cas : _à renseigner_

Responsable / date / preuve : _à renseigner_
Statut : Ouvert

### DEC-11 — Automatisation des remboursements — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation pilote | Désactivée. Action administrative étroite après preuve du Payment et accord comptable. |
| Alternative | Worker idempotent ultérieur, avec demande durable, retries bornés et réconciliation manuelle. |
| Refund externe | Définir les rôles autorisés à le reconnaître, la preuve comptable, la notification et le rapprochement ; l'absence de demande Drupal ne vaut jamais approbation rétroactive. |
| Double approbation requise ? |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-12 — Partiel, litige et chargeback — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | `reconciliation_required`; aucune création/annulation automatique de réservation. |
| Politique de service |  |
| Politique comptable |  |
| Notification membre |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-13 — Replanification — bloquante avant promesse client

| Champ | À renseigner |
|---|---|
| Recommandation V1 | Aucune replanification implémentée : le support applique seulement la politique d’annulation/refund approuvée. Ne pas promettre de déplacement. |
| Alternative | Future PR dédiée, humaine ou libre-service, avec registre de changement, consentement, acquisition atomique du nouveau slot, libération de l’ancien, prix et Google. |
| Nombre/délai de changements permis |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-14 — Écart de prix lors d’une replanification — bloquante si DEC-13 activée

| Champ | À renseigner |
|---|---|
| Options | Maintien du prix initial ; supplément ; avoir ; remboursement de différence. |
| Recommandation | Reporter toute automatisation ; décision humaine et trace comptable en V1. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

## Prix, capacité et périmètre vendu

### DEC-15 — Changement de prix — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | Honorer le snapshot Commerce d’un claim encore valide ; tout nouveau claim prend le prix courant. |
| Alternative | Reprice avant paiement avec affichage et consentement explicite, puis nouveau snapshot. |
| Interdit | Utiliser les libellés de prix codés dans le tunnel comme autorité. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-16 — Réduction de capacité ou retrait du slot — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | Ne jamais invalider une réservation confirmée ; bloquer les nouvelles acquisitions ; honorer les claims actifs jusqu’à leur échéance ; traiter un claim payé scellé comme une vente à résoudre. |
| Alternatives pour claims non payés | Maintenir jusqu’à échéance ; expirer avec notification et support. |
| Règle si engagements > nouvelle capacité |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-17 — Quantité/capacité V1 — bloquante

- [ ] Approuver : une commande = une OrderItem = quantité 1 =
  `capacity_units=1` = suffixe Webform `|1`.
- [ ] Approuver : achats multi-places reportés à une conception séparée.

Responsable / date / preuve : _à renseigner_
Statut : Ouvert

## Contact, wording et projection compte

### DEC-18 — Contact support client — bloquante avant mise en ligne

| Champ | À renseigner |
|---|---|
| Nom public du service |  |
| Email/formulaire/téléphone |  |
| Horaires |  |
| Délai annoncé |  |
| Relais en cas d’absence |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-19 — Wording client — bloquante avant mise en ligne

Approuver ou remplacer les textes sans introduire de promesse non implémentée.

| Situation | Proposition à approuver | Texte retenu |
|---|---|---|
| Claim actif | « Votre créneau est réservé jusqu’à {heure}. » |  |
| Échéance proche | « Il vous reste environ 5 minutes pour terminer le paiement. » |  |
| Cancel navigateur | « Paiement non finalisé. Votre créneau reste réservé jusqu’à {heure}. » |  |
| Pending | « Paiement en attente. La réservation n’est pas encore confirmée. » |  |
| Payé, finalisation locale | « Paiement reçu, vérification en cours. » |  |
| Réservation commitée | « Réservation confirmée pour le {date} à {heure}. » |  |
| Claim expiré | « Créneau expiré. Choisissez un nouveau créneau. » |  |
| Incident payé | « Action de l’association nécessaire. Nous vous contacterons sous {SLA}. » |  |
| Échec terminal | « Paiement non abouti. Aucun montant confirmé n’a réservé ce créneau. » |  |
| Remboursement intégral vérifié | « Paiement remboursé. » |  |
| Remboursement partiel | « Remboursement partiel — action de l’association nécessaire. » |  |

Responsable / date / preuve : _à renseigner_
Statut : Ouvert

### DEC-20 — Détails conservés avant paiement — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | `mode_cours`, `telephone`, `instrument`, `niveau_cours`; conditionnels `plateforme_visio`, adresse+code postal, `didgeridoo_pret`; notes facultatives bornées. Table séparée privée, duplicata purgé après transfert. |
| Décision `niveau_cours` | Le collecter dans le tunnel ou approuver une dérivation exacte par variation ; le Webform le requiert mais le tunnel actuel l’omet. |
| Liste exacte de champs autorisés / longueur notes |  |
| Champs explicitement interdits |  |
| Délai de purge si non payé |  |
| Responsable confidentialité / date / preuve |  |
| Statut | Ouvert |

## Conservation et confidentialité

### DEC-21 — Conservation, suppression et protection — bloquante avant toute PR runtime

Les obligations comptables/juridiques doivent être validées par les personnes
compétentes ; ce document ne les invente pas.

| Catégorie | Durée/purge à approuver |
|---|---|
| Brouillons/tempstore pré-claim |  |
| Commerce Orders et Payments |  |
| Claims structurés finalisés |  |
| Claims expirés/abandonnés |  |
| `owner_guard` et `trial_usage` |  |
| Détails personnels du claim |  |
| Liens de réservation et dossiers de résolution |  |
| Payment attempts, resources et refund attempts |  |
| Submission Webform |  |
| Audit append-only minimal |  |
| Logs techniques futurs expurgés et logs historiques SID/UID/UUID |  |
| Signaux webhook hashés et inbox non terminale |  |
| Files email/Google terminales et non terminales |  |
| Sauvegardes, snapshots et délai de propagation d’une purge |  |

- [ ] Définir la pseudonymisation/anonymisation après suppression d’un compte,
  sans casser les obligations comptables, l’unicité d’essai ni un incident ouvert.
- [ ] Interdire la purge d’un paiement, signal, dossier ou travail non terminal ;
  définir un legal hold codé, son approbateur et sa revue.
- [ ] Approuver chiffrement/protection au repos de la base et des sauvegardes,
  accès minimal, rotation des clés et preuve de restauration/purge.
- [ ] Attribuer l’inventaire et la purge des logs historiques contenant
  SID/UID/UUID avant activation.

Responsable comptable/confidentialité / date / preuve : _à renseigner_
Statut : Ouvert

## PayPal, sécurité et comptabilité

### DEC-22 — Propriété de l’application sandbox — bloquante

| Champ | À renseigner |
|---|---|
| Compte organisationnel propriétaire |  |
| Dépositaires des credentials environnementaux |  |
| Responsable webhook et rotation |  |
| Responsable incident/indisponibilité |  |
| Interdit | Compte personnel ou credential dans GitHub, ticket, log ou document. |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-23 — Contrat PR #108 et rotation — bloquante avant sandbox

- [ ] L’ancien credential suivi a été révoqué/rotaté, preuve hors GitHub public.
- [ ] #108 est fusionnée ou son contrat est explicitement repris sans conflit.
- [ ] Gateway indisponible quand un credential manque.
- [ ] Sandbox/test est le seul mode activé au pilote.
- [ ] `webhook_id` propre à l’environnement est configuré hors secret suivi.
- [ ] Le logging du corps webhook est désactivé.
- [ ] L’advisory Composer ignorée a une décision sécurité documentée.

Responsable / date / preuve : _à renseigner_
Statut : Ouvert

### DEC-24 — Activation PayPal production — bloquante

| Champ | À renseigner |
|---|---|
| Approbateur métier |  |
| Approbateur technique/sécurité |  |
| Date/fenêtre d’activation |  |
| Critères sandbox acceptés |  |
| Kill switch et responsable rollback |  |
| Périmètre du pilote live |  |
| Condition d’élargissement |  |
| Statut | Ouvert |

### DEC-25 — Conséquences comptables — bloquante avant sandbox

| Sujet | Décision/preuve à renseigner |
|---|---|
| Capture immédiate actuelle versus autorisation/capture ultérieure |  |
| Frais PayPal et traitement d’un refund |  |
| TVA, justificatif et reçu Commerce |  |
| Rapprochement order/payment/réservation |  |
| Double capture ou surpaiement |  |
| Paiement reçu après expiration |  |
| Remboursement partiel/chargeback |  |
| Durée de conservation comptable |  |

Responsable comptable / date / preuve : _à renseigner_
Statut : Ouvert

## Google, emails, compte et administration

### DEC-26 — Données et activation Google — bloquante pour Google

| Champ | À renseigner |
|---|---|
| Recommandation | Après #111 seulement ; libellé générique, référence opaque, début/fin/fuseau ; aucun nom, contact, note, montant ou identifiant paiement. |
| Propriétaire organisationnel | Compte/application de service et suppléant ; aucun compte personnel. |
| Calendrier cible et responsable | Identité conservée hors document public ; propriétaire/administrateur nommé. |
| ACL minimale | Rôles lecture/écriture/admin, revue périodique et retrait d’accès. |
| Credentials | Stockage environnemental, dépositaires, rotation et procédure de compromission. |
| Libellé générique retenu |  |
| Extension de payload V1 | Interdite. Toute donnée supplémentaire exige une nouvelle décision confidentialité et une PR revue. |
| Rétention/suppression | Durée des événements, cancel/delete après annulation, compte supprimé et preuve de purge. |
| Responsable des échecs permanents |  |
| Incident et rollback | Runbook, alertes, backlog, révocation/rotation et remise en service. |
| Activation distincte du paiement | Gate Google live, environnement, test, fenêtre et approbateur ; le paiement peut être live avec Google off. |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-27 — Emails post-commit — bloquante

| Champ | À renseigner |
|---|---|
| Source membre recommandée | Email principal courant du compte Drupal actif au moment de l’envoi ; jamais `[current-user]` ni une valeur POST. |
| Compte bloqué/supprimé ou email absent | Recommandation : `recipient_unavailable`, aucun fallback automatique ; règle support à approuver. |
| Destinataire association | Adresse/rôle organisationnel configuré, jamais fourni par la submission. |
| Allowlist email membre | Cours/tarif lisible, date/heure/fuseau, statut confirmé, contact public ; aucun ID interne, téléphone, adresse ou note. |
| Allowlist email association | Référence opaque, cours/date/heure et identité/contact strictement nécessaires ; téléphone/adresse/notes exclus par défaut. |
| Textes/modèles et exceptions approuvés |  |
| Délai et nombre de retries avant alerte |  |
| Bounce/rejet destinataire | État, alerte, responsable et canal de contact alternatif. |
| Traitement d’un `send_ambiguous` |  |
| Renvoi manuel | Revalider le destinataire courant, confirmation/audit et politique si l’adresse a changé. |
| Risque résiduel rare de doublon accepté ? |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-28 — Libellés du compte membre — bloquante pour #109

Les états paiement et réservation restent séparés.

| État client | Libellé proposé | Approuvé/remplacé |
|---|---|---|
| Payment non certain | Paiement en attente |  |
| Capture, finalisation en cours | Paiement reçu, vérification en cours |  |
| Submission active | Réservation confirmée |  |
| Claim échu | Créneau expiré |  |
| Incident à traiter | Action de l’association nécessaire |  |
| Payment refunded | Paiement remboursé |  |
| Submission annulée | Réservation annulée |  |

- [ ] Aucun claim UUID, transaction PayPal, webhook ID, SID, état Google ou
  workflow brut n’est visible.
- [ ] Aucun bouton/texte ne promet annulation ou replanification libre-service.
- [ ] Décider si « Remboursée » est refusé car ambigu, ou seulement affiché avec
  deux badges paiement/réservation explicites.

Responsable / date / preuve : _à renseigner_
Statut : Ouvert

### DEC-29 — Rôles et actions administratives — bloquante pour l’admin

| Action | Rôle(s) autorisé(s) / double approbation |
|---|---|
| Voir la réconciliation minimale |  |
| Voir les détails personnels exceptionnels |  |
| Lire/exporter l’audit |  |
| Voir/reprendre les files opérationnelles |  |
| Réessayer une finalisation locale |  |
| Libérer un claim sûr |  |
| Marquer réconciliation |  |
| Initier un workflow de refund |  |
| Clore sans réservation après refund intégral vérifié |  |
| Lier un Payment déjà vérifié |  |
| Renvoyer un email ambigu |  |

- [ ] Séparer au minimum support lecture, opérations paiement, approbation
  refund et audit/confidentialité ; documenter les incompatibilités de rôles.
- [ ] Exiger MFA ou réauthentification récente pour lier un Payment, approuver/
  exécuter un refund et clore un claim ; si la plateforme ne le permet pas,
  bloquer l’action ou imposer une double approbation hors bande auditée.
- [ ] Interdire tout export contenant des détails personnels par défaut ;
  tracer motif, périmètre et destinataire de chaque export autorisé.
- [ ] La référence support est aléatoire et distincte ; jamais un préfixe de
  claim UUID, SID, order ID ou transaction distante.

Chemin administratif proposé/validé : _à renseigner_

Responsable / date / preuve : _à renseigner_
Statut : Ouvert

## Gouvernance des PR et rollout

### DEC-30 — Propriété des ressources runtime / PR #103 — bloquante immédiate

L’instruction reçue affirme que « PR #103 possède exclusivement les ressources
runtime ». La [PR GitHub #103](https://github.com/Nyoha12/Uni-Songes/pull/103) auditée est pourtant
`codex-implement-editorial-home-blog` à `7ea4ff4d` et porte le Blog éditorial.
Cette divergence n’autorise aucune supposition.

| Champ | À renseigner |
|---|---|
| Numéro/ticket runtime réellement propriétaire |  |
| Branches/fichiers réservés |  |
| Règle de séquencement avec #108/#109/#111 |  |
| Responsable qui lève le blocage |  |
| Date / preuve |  |
| Statut | Ouvert |

### DEC-31 — Rollout, rollback et responsables — bloquante avant production

- [ ] Feature désactivée par défaut.
- [ ] Matrice fake gateway/concurrence acceptée.
- [ ] Pilote sandbox accepté avec preuve.
- [ ] Fenêtre live, périmètre initial et observateur nommés.
- [ ] Seuils d’alerte et SLA de réconciliation nommés.
- [ ] Cadence/batch cleanup et seuil du plus vieux claim approuvés ; la
  recommandation technique est une minute et une alerte à cinq minutes.
- [ ] Kill switch d’acquisition testé : stop nouveaux claims, orders, créations
  PayPal et captures locales ; webhooks vérifiés, retours servant de preuve,
  reconstruction, finalizer et reconciler des tentatives en vol restent actifs.
- [ ] Désactivation complète de la gateway autorisée seulement après inventaire
  sans tentative `creating`, `ready`, `pending`, `authorization` ou `ambiguous`,
  sauf procédure d’urgence approuvée en cas de compromission.
- [ ] Rollback ne supprime aucun claim, order, Payment ou SID.
- [ ] Rapprochement comptable et opérationnel prévu après la fenêtre.
- [ ] Runbook versionné avec commandes exactes sans secret, owner du switch,
  permissions, approbateur, suppléant et exercice daté.
- [ ] RPO/RTO, sauvegarde, test de restauration et preuve de cohérence
  claim/order/Payment/SID après restauration approuvés.
- [ ] Procédure de compromission : gel create/capture, rotation/révocation,
  conservation des preuves en vol, communication et critères de reprise.

Responsable / date / preuve : _à renseigner_
Statut : Ouvert

### DEC-32 — Achat classique des variations de cours — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | À l’activation, bloquer l’add-to-cart/Smart Payment Button classique des SKU de cours et orienter vers `/reservation-cours`. Une offre de crédits doit avoir des SKU et un wording distincts. |
| Alternative | Maintenir explicitement le produit crédit classique, avec invariant et communication séparés ; il ne devient jamais un handoff slot-aware. |
| Vieux carts draft | Recommandation : les rendre non payables après inventaire ; ne pas leur greffer un claim. |
| Vieux orders payés/ambigus | Conserver/réconcilier sous le contrat crédit historique. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

### DEC-33 — Unicité du cours d’essai — bloquante

| Champ | À renseigner |
|---|---|
| Recommandation | Un seul essai confirmé par compte, tous canaux confondus ; un claim actif réserve l’éligibilité, un échec sûr la restitue, un paiement ambigu la bloque jusqu’à résolution. |
| Alternative | Définir précisément quand un essai annulé/refunded redevient éligible. |
| Migration | Approuver l’audit/backfill de `field_essai_utilise`, droits, orders et submissions avant l’index unique. |
| Choix propriétaire |  |
| Responsable / date / preuve |  |
| Statut | Ouvert |

## Approbation finale

L’approbation porte sur un release candidate exact, pas sur un document abstrait.

| Identité du release candidate | Valeur approuvée |
|---|---|
| SHA du commit applicatif |  |
| SHA de base `release/prod` |  |
| Environnement |  |
| Hash/révision de configuration exportée |  |
| Feature flags et kill switches |  |
| Gateway/entity et mode PayPal |  |
| Mode Google (`off`, dry-run, live) |  |
| Version du runbook |  |
| Preuves tests/concurrence/sandbox/restore |  |
| Date d’expiration ou de revalidation |  |

| Rôle | Nom | Date | Décision | Preuve/ticket |
|---|---|---|---|---|
| Propriétaire métier |  |  |  |  |
| Responsable technique |  |  |  |  |
| Responsable sécurité/confidentialité |  |  |  |  |
| Responsable comptable |  |  |  |  |
| Responsable support/opérations |  |  |  |  |

Décision finale autorisée : `Sandbox seulement`, `Pilote production limité`,
`Production`, ou `Reporté — feature désactivée`.

Décision retenue : _à renseigner_

Date : _à renseigner_

Ticket/preuve :

Toute différence de SHA, configuration, environnement, mode PayPal/Google,
politique signée ou runbook réouvre les DEC affectées avant déploiement.
