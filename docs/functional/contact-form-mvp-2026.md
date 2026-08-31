# Formulaire Contact MVP 2026

## Décision et périmètre

Le MVP complète le Webform Drupal existant `contact` et l'affiche dans un bloc
réservé au chemin public existant `/contact`. Il ne crée ni nouveau Webform, ni
nouvelle URL publique, ni destinataire de courrier électronique.

Les demandes sont enregistrées dans Drupal pour examen privé par un
administrateur. Aucun gestionnaire d'e-mail, téléchargement, intégration
externe, création de compte, abonnement ou publication automatique n'est
configuré. La page Contact, son corps et son alias ne sont pas modifiés.

Configuration détenue par ce MVP :

- `webform.webform.contact` : Webform existant, UUID conservé ;
- `block.block.unisonges_contact_form` : bloc Webform dans la région `content`,
  visible uniquement quand le chemin correspond exactement à `/contact`.

## Audit préalable

### Page et placement

L'alias `/contact` désigne actuellement le nœud publié Contact, de type Page de
base. Son corps actif est vide d'après l'audit versionné. Le gabarit
`node--7.html.twig` rend un bandeau, le corps lorsqu'il existe et des actions ;
il n'est pas modifié. Le bloc suit la convention des blocs propres au thème
`unisonges_theme` et utilise la région `content` avec la condition native
`request_path` limitée à `/contact`.

Le mode page autonome du Webform est désactivé. Les anciennes routes propres au
Webform ne deviennent donc pas un second point d'entrée public. Lors de la
première application, Webform peut supprimer ses seuls alias historiques
`/form/contact`; le script autorise cette conséquence ciblée, mais vérifie que
l'alias `/contact` et tous les alias sans rapport restent inchangés.

### Webform existant

Le dépôt contient un seul Webform de contact, ID `contact`, et aucun bloc ne
l'intégrait. Sa configuration antérieure était le formulaire d'exemple Webform :
champs génériques, page autonome, historique des soumissions, adresse IP,
aucune limite et deux gestionnaires d'e-mail. Le MVP conserve son ID et son UUID,
remplace ses éléments et supprime tous ses gestionnaires ; il ne crée pas de
doublon.

Le dépôt comporte aussi le Webform de réservation de cours. Il est hors
périmètre et reste inchangé.

### JavaScript historique

`drupal/web/themes/custom/unisonges_theme/js/contact-form.js` est orphelin :
aucune bibliothèque `contact` n'est déclarée dans le fichier de bibliothèques
du thème, malgré l'attachement demandé par le gabarit du nœud Contact. Le script
vise un ancien formulaire statique et un service Google Apps Script, collecte
des données supplémentaires (téléphone, URL et agent utilisateur), ne contrôle
pas le statut HTTP avant d'afficher un succès et n'apporte pas de validation
serveur Drupal.

Il reste donc inutilisé et inchangé. Le MVP repose exclusivement sur le rendu,
la validation et la confirmation standards de Webform. Son retrait éventuel et
la correction de la déclaration de bibliothèque appartiennent à une évolution
distincte, coordonnée avec le propriétaire du thème.

### Confidentialité existante

Le seul texte approchant une politique dans le dépôt est la page statique
historique `public/politique-confidentialite/index.html`. Elle affirme que le
site ne comporte pas de formulaire serveur ; elle n'est ni correcte pour ce MVP,
ni disponible sur la route Drupal auditée. Elle ne peut donc pas servir de
politique approuvée. Aucun délai légal de conservation n'est inventé ici.

## Champs et validation

Les cinq données métier stockées sont :

| Clé | Libellé | Règles serveur |
| --- | --- | --- |
| `name` | Nom | texte simple obligatoire, 120 caractères maximum |
| `email` | Adresse e-mail | élément Webform `email` obligatoire, 254 caractères maximum |
| `subject` | Objet | liste obligatoire limitée aux six valeurs ci-dessous |
| `message` | Message | zone de texte simple obligatoire, 20 à 5 000 caractères |
| `consent` | Consentement | case obligatoire |

Les objets autorisés sont exactement :

- Cours et stages ;
- Concerts et événements ;
- Projets collectifs ;
- Association et partenariats ;
- Prestations artistiques ;
- Autre.

Le consentement affiché est : « J’accepte que les informations fournies soient
utilisées uniquement pour traiter ma demande de contact et y répondre. »

Chaque champ obligatoire possède un message d'erreur explicite. Le contrôle de
type de l'e-mail, la liste fermée de l'objet et les longueurs sont portés par les
éléments Webform/Form API et ne dépendent pas du navigateur. Le message est un
`textarea`, sans format de texte ni éditeur riche. Aucun élément n'accepte de
fichier ou du HTML formaté. Après une erreur, le cycle normal de reconstruction
du formulaire conserve les valeurs non sensibles lorsque Webform le permet.

Après succès, la confirmation reste sur la page et indique « Demande
enregistrée » puis « Votre message a été envoyé. » Dans ce MVP, « envoyé »
signifie enregistré dans Drupal : aucun e-mail n'est expédié.

## Accès et absence de publication

Les rôles `anonymous` et `authenticated` disposent uniquement de l'opération
Webform `create`. Toutes les opérations `view_any`, `update_any`, `delete_any`,
`purge_any`, `view_own`, `update_own`, `delete_own`, `administer`, `test` et
`configuration` restent sans rôle, utilisateur ou permission. Aucun rôle ni
permission globale n'est modifié par cette contribution.

Les réglages de consultation des soumissions précédentes, de partage et des
jetons de consultation, mise à jour ou suppression sont désactivés. La page
autonome du Webform est désactivée et le bloc est restreint à `/contact`. Un
visiteur ne reçoit donc aucun chemin lui permettant d'énumérer, consulter,
modifier ou supprimer une demande après envoi.

`results_disabled` reste faux pour conserver les soumissions en base et
permettre leur examen administratif. Le rôle existant `administrator` est
marqué `is_admin: true`; le script vérifie ce pré-requis et refuse tout rôle non
administrateur possédant une permission globale de gestion des soumissions
Webform.

## Stockage et procédure administrateur

Webform stocke les cinq valeurs ci-dessus dans une entité de soumission privée.
Drupal/Webform enregistre aussi automatiquement les métadonnées techniques de
l'entité : identifiant et numéro de série, UUID et jeton interne, Webform et
éventuelle entité source, langue, URI relative de la requête source (chemin et
chaîne de requête), identifiant utilisateur (0 pour un visiteur anonyme), dates
de création/achèvement/modification, page courante et indicateurs de brouillon,
verrouillage ou épinglage. L'entité comporte aussi un champ de notes
administratives, normalement vide ici. Il ne faut donc jamais placer de donnée
sensible dans la chaîne de requête de `/contact`.

`form_disable_remote_addr: true` empêche Webform de conserver l'adresse IP dans
la soumission. Le journal de soumission Webform est désactivé et aucun champ de
suivi ou agent utilisateur n'est ajouté. Ce réglage vaut pour les nouvelles
soumissions ; il n'efface aucune éventuelle adresse enregistrée dans une ligne
historique. Cela ne prétend pas modifier les journaux techniques indépendants,
notamment Drupal `dblog`/watchdog, le serveur HTTP ou l'hébergeur, qui relèvent
de leur propre politique opérationnelle et peuvent contenir des métadonnées de
requête ou d'erreur.

Pour examiner les demandes, un administrateur ouvre :

`/admin/structure/webform/manage/contact/results/submissions`

Pour supprimer une demande, il utilise l'action Supprimer de la ligne concernée
et confirme l'écran de suppression. Avant une suppression en masse, il doit
vérifier explicitement la sélection et le Webform `contact`. La suppression est
irréversible hors sauvegarde de base de données.

Le responsable du site doit encore faire approuver puis documenter une politique
de conservation et organiser les suppressions correspondantes. Aucun délai
n'est défini automatiquement par cette configuration (`purge: none`).

## Protection contre les abus réellement disponible

La pile verrouillée contient Webform 6.3.0-beta7, mais aucun module CAPTCHA,
Honeypot, Antibot ou reCAPTCHA installé. Le MVP n'ajoute aucune dépendance et ne
prétend pas disposer d'une protection absente.

Les limites natives configurées portent uniquement sur les soumissions
terminées :

- 5 demandes par heure et par UID pour un compte connecté ;
- 5 demandes par heure selon l'identification anonyme native de Webform
  (session/cookie) pour un visiteur anonyme ;
- 30 demandes terminées par heure pour l'ensemble du Webform.

La version installée ne fournit pas de limite Webform native par adresse IP.
Un visiteur peut contourner la limite anonyme en renouvelant son état client,
et la limite globale peut elle-même être utilisée pour provoquer une
indisponibilité temporaire. Les requêtes invalides ne créent pas de soumission
terminée et ne sont donc pas décomptées par ces limites. Ces risques résiduels
sont consignés, pas masqués. Le contrôle fonctionnel des limites fait partie de
la matrice différée ; la PR reste en brouillon jusque-là. Une protection
supplémentaire, dans Drupal, dans du code ciblé ou dans l'infrastructure,
nécessiterait une décision et un changement séparés.

Enfin, `composer.lock` marque la version bêta Webform 6.3.0-beta7 comme non
couverte par les avis de sécurité Drupal. Cette dette est héritée de la pile et
ne peut pas être résolue par un changement ciblé du formulaire ; une mise à jour
Webform compatible doit être planifiée séparément.

## Déploiement ciblé

Le script `drupal/scripts/apply-contact-form-mvp-2026.sh` est en lecture seule
par défaut. Il n'exécute aucun import de configuration complet ou partiel. Son
allowlist d'écriture contient exactement :

- `webform.webform.contact` ;
- `block.block.unisonges_contact_form`.

Avant toute écriture, le lanceur vérifie les chemins, l'intégrité Git des sources,
l'origine non productive explicite et les versions verrouillées. Le helper
valide ensuite les YAML et leur sémantique, les dépendances, les UUID, le thème,
les plugins, les permissions, la page `/contact`, les doublons, les alias, toutes
les soumissions et l'intégralité de la configuration active. Il accepte
uniquement l'état historique connu, l'état cible exact ou l'état de rollback
exact ; toute dérive ou installation partielle ferme l'exécution.

Une application exige en plus :

- une sauvegarde ou un instantané récent confirmé ;
- le mode maintenance Drupal activé ;
- une fenêtre exclusive sans cron, file de tâches ni autre écriture privilégiée ;
- le checkout exact et ses dépendances Composer verrouillées.

Le helper prend les verrous persistants d'import et de fonctionnalité, répète
le préflight complet, compare son empreinte, puis sauvegarde les deux entités de
configuration dans une transaction de base de données. Il vérifie ensuite que
la page, les soumissions, les rôles, les alias non concernés et toutes les
configurations hors allowlist sont inchangés.

Lors d'une passe ultérieure explicitement autorisée, depuis l'environnement de
staging approuvé et le répertoire `drupal` :

```bash
CONTACT_STAGE_ORIGIN='https://staging.example.invalid'

./scripts/apply-contact-form-mvp-2026.sh \
  --site-uri="${CONTACT_STAGE_ORIGIN}"

./scripts/apply-contact-form-mvp-2026.sh \
  --site-uri="${CONTACT_STAGE_ORIGIN}" \
  --apply \
  --backup-confirmed

./scripts/apply-contact-form-mvp-2026.sh \
  --site-uri="${CONTACT_STAGE_ORIGIN}"

./scripts/apply-contact-form-mvp-2026.sh \
  --site-uri="${CONTACT_STAGE_ORIGIN}" \
  --apply \
  --backup-confirmed
```

Remplacer l'origine d'exemple par l'origine de staging approuvée. Le script
refuse explicitement les origines de production. Ne pas exécuter ces commandes
sur le VPS dans le cadre de cette PR.

La deuxième application doit être idempotente et annoncer un no-op. Le dry-run
qui la précède doit déjà indiquer l'état cible sans opération.

## Rollback et réapplication

Le rollback sûr ne restaure pas les anciens gestionnaires d'e-mail et ne détruit
ni configuration, ni soumission. Il ferme le Webform et désactive son bloc :

```bash
./scripts/apply-contact-form-mvp-2026.sh \
  --site-uri="${CONTACT_STAGE_ORIGIN}" \
  --rollback

./scripts/apply-contact-form-mvp-2026.sh \
  --site-uri="${CONTACT_STAGE_ORIGIN}" \
  --rollback \
  --apply \
  --backup-confirmed
```

Les administrateurs peuvent encore examiner et supprimer les soumissions après
ce rollback. Pour réappliquer, exécuter d'abord le dry-run d'installation, puis
la commande `--apply --backup-confirmed` sans `--rollback`. Toute autre dérive
doit être examinée manuellement ; le script ne la remplace pas.

## Validation statique

Les contrôles hors runtime exécutés sur le diff comprennent :

- parsing YAML strict du Webform, de ses éléments imbriqués, du bloc, de
  `core.extension`, du thème et de l'override français ;
- assertions sémantiques sur les champs, options, longueurs, accès, limites,
  stockage, absence de gestionnaire et visibilité exacte `/contact` ;
- résolution des dépendances de configuration et unicité de tous les UUID de
  `config/sync` ;
- vérification indépendante de la confidentialité, des permissions et des
  limites anti-abus ;
- vérification indépendante du préflight, de la transaction, du rollback, de
  l'idempotence et des caches du helper ;
- `bash -n` et ShellCheck sur le lanceur ;
- `php -l` sur le helper ;
- `git diff --check`, garde de cinq fichiers, recherche de secrets et contrôle
  de non-chevauchement avec les PR concurrentes.

Le parser YAML utilisé pour ces contrôles est `yaml@2.8.1`, épinglé et exécuté
depuis un cache temporaire supprimé après validation. Les contrôles de runtime
Drupal restent ceux de la matrice ci-dessous.

## Matrice d'exécution différée

La PR #80 a été fusionnée pendant la préparation de ce changement. Conformément
à la contrainte explicite de cette tâche, aucun test DDEV, Docker, Drush,
Chromium ou Mailpit n'est néanmoins exécuté ici. La PR doit rester en brouillon
jusqu'à une passe autorisée exécutant la matrice complète sur staging.

### Visiteur anonyme

- `/contact` charge et le formulaire est visible ;
- une demande valide réussit ;
- une adresse e-mail invalide est rejetée ;
- les champs obligatoires manquants sont rejetés ;
- une valeur d'objet hors liste est rejetée côté serveur ;
- un message trop long est rejeté ;
- une charge HTML/script est traitée sans exécution ni rendu dangereux ;
- le visiteur ne peut consulter aucun résultat ;
- les limites par visiteur et globale fonctionnent.

### Utilisateur authentifié

- une demande valide réussit ;
- l'utilisateur ne peut énumérer ni consulter les autres soumissions ;
- aucune permission générale d'administration Webform ne lui est accordée.

### Administrateur

- l'administrateur peut examiner les soumissions ;
- l'administrateur peut supprimer une soumission ;
- les données privées n'apparaissent dans aucun cache ou balisage public.

### Affichage et intégration

- le formulaire apparaît uniquement sur `/contact`, sans doublon ;
- après intégration de la PR #81, ses messages Drupal s'affichent correctement ;
- rendu desktop et mobile sous Chromium ;
- navigation intégrale au clavier ;
- erreurs et confirmation accessibles ;
- aucun débordement horizontal ;
- aucune erreur de console ;
- aucun avertissement PHP.

### Opérations

- dry-run ciblé ;
- application ;
- second dry-run ;
- seconde application idempotente ;
- rollback ;
- réapplication ;
- aucune dérive de configuration sans rapport ;
- zéro soumission fixture après nettoyage ;
- Mailpit reste inutilisé, car aucun gestionnaire d'e-mail sortant n'existe.

## Suivis hors périmètre

- faire approuver et publier une politique de confidentialité cohérente avec le
  stockage serveur ;
- faire approuver une politique de conservation, puis son processus de purge ;
- n'ajouter une livraison e-mail qu'après validation d'un destinataire et de la
  politique de confidentialité ;
- décider séparément d'une éventuelle protection anti-spam supplémentaire ;
- traiter séparément le JavaScript et la bibliothèque de thème historiques.
