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

Après la fusion de la PR #78, le contrat versionné conserve `/contact` comme
alias canonique en lecture seule vers le nœud publié Contact de type Page de
base. La validation consignée par cette PR retrouve `/contact` vers le nœud 7,
et son script refuse un alias absent, ambigu ou pointant vers un autre type de
nœud. Le présent rafraîchissement reste statique et ne réinterroge donc pas la
base active. Son corps actif est vide d'après l'audit versionné. Le gabarit
`node--7.html.twig` rend un bandeau, le corps lorsqu'il existe et des actions ;
il n'est pas modifié. Le bloc suit la convention des blocs propres au thème
`unisonges_theme` et utilise la région `content` avec la condition native
`request_path` limitée à `/contact`.

Le mode page autonome du Webform est désactivé : `/webform/contact` ne devient
donc pas un second formulaire public et les routes de résultats restent soumises
aux accès privés décrits ci-dessous. La route native de confirmation générique
est auditée séparément dans la section Accès. Lors de la première application,
Webform peut supprimer ses seuls alias historiques `/form/contact`; le script
autorise cette conséquence ciblée, mais vérifie que l'alias `/contact` et tous
les alias sans rapport restent inchangés.

### Webform existant

Le dépôt contient un seul Webform de contact, ID `contact`, et aucun bloc ne
l'intégrait. Sa configuration antérieure était le formulaire d'exemple Webform :
champs génériques, page autonome, historique des soumissions, adresse IP,
aucune limite et deux gestionnaires d'e-mail. Le MVP conserve son ID et son UUID,
remplace ses éléments et supprime tous ses gestionnaires ; il ne crée pas de
doublon.

Le dépôt comporte aussi le Webform de réservation de cours. Il est hors
périmètre et reste inchangé.

### Configuration principale et traduction française

La configuration principale et l'override français existant
`language/fr/webform.webform.contact.yml` portent le même titre (`Contact`) et
le même message de confirmation (`Votre message a été envoyé.`). L'override est
hors du périmètre des cinq fichiers et reste inchangé. Le helper le lit comme
pré-requis, compare sa forme exacte avant toute écriture et échoue fermé en cas
de dérive ; il ne tente pas de le corriger.

Le code verrouillé de Drupal applique l'override de la langue d'interface après
la configuration principale. Toute modification future de ces textes doit donc
être coordonnée avec le propriétaire de l'override et autorisée dans un
périmètre distinct, faute de quoi le rendu français pourrait diverger. Aucun
fichier de traduction n'est modifié silencieusement par ce MVP.

### Intégration statique des PR #78 et #80 fusionnées

Ce changement est rebasé après les fusions de la PR #80 (Forum/Blog) et de la
PR #78 (architecture de contenu). La PR #78 ne possède ni configuration
Webform ni bloc Contact ; elle conserve `/contact` parmi quatre pages de
référence et ne modifie ni son corps, ni son alias, ni son identifiant.

La PR #80 ajoute un espace de proposition distinct du Contact :

| Fonction | Webform | Bloc | Placement et création |
| --- | --- | --- | --- |
| Contact | `contact`, UUID `c76dd154-d2cd-4b04-92c9-fe61536beabe` | `unisonges_contact_form`, UUID `fd67a4c0-e06a-46f0-af88-acc5c0b28f8f` | `/contact`, région `content`, poids 50 ; `anonymous` et `authenticated` |
| Proposition Forum/Blog | `forum_blog_proposal`, UUID `f07292df-ffe6-4fe6-8a81-b2b6378e6ed6` | `unisonges_forum_blog_proposal`, UUID `01f58684-8fde-4544-850b-09d380361c22` | `/forum`, région `content`, poids 30 ; `authenticated` uniquement |

Les IDs et UUID sont distincts. Les deux blocs partagent la région native
`content`, mais leurs chemins sont disjoints et ils référencent chacun leur
propre Webform ; il n'existe donc ni collision de placement ni formulaire
croisé. Les deux Webforms stockent leurs résultats sous leur propre
`webform_id`, n'ont aucun gestionnaire et laissent vides tous les accès de
consultation, modification ou suppression. Le Contact conserve en plus ses
limites propres, qui ne s'appliquent pas aux propositions Forum/Blog.

Le helper Contact prend une empreinte de toutes les soumissions Webform, pas
seulement de celles de `contact`, avant et après une application. Une
modification ou un reclassement d'une proposition Forum/Blog ferait donc
échouer la vérification au lieu d'être silencieusement accepté.

### Intégrations de thème, messages et compte

L'intégrité des bibliothèques du thème est désormais fusionnée. Le gabarit du
nœud Contact attache `unisonges_theme/contact`, qui existe et dépend seulement
de `unisonges_theme/unisonges-layout`. Cette bibliothèque ne déclare aucun
JavaScript et ne référence donc pas le script statique historique décrit
ci-dessous.

La PR #100 est fusionnée. Dans la configuration synchronisée, l'unique bloc de
messages actif du thème `unisonges_theme` reste
`unisonges_theme_messages`, dans `content` au poids -8. Les deux shells publics
rendent `page.content` une seule fois à l'intérieur de `main`. La suggestion de
thème fusionnée sélectionne le template inline
`status-messages--unisonges-inline.html.twig`, qui fournit une destination
`data-drupal-messages` dans le flux normal, sans wrapper fixe ni toast.

Le Contact ne crée aucun autre bloc de messages. Ses erreurs de champ restent
adjacentes au formulaire (`form_disable_inline_errors: false`) et sa
confirmation reste de type `inline`. La matrice runtime doit vérifier que ces
deux rendus Webform coexistent avec le chemin global de statut, erreur ou
avertissement sans perte, doublon ni message de session retardé. La présence de
la source fusionnée ne constitue pas une affirmation de son activation en
production.

La PR #99 est également fusionnée. Sa classe de page et sa bibliothèque
`unisonges_theme/auth-account` sont limitées aux routes de connexion,
inscription, mot de passe et compte du propriétaire. `/contact` est une route
canonique de nœud, hors de cette allowlist. La feuille `auth-account.css` n'est
chargée que dans cette allowlist et ses sélecteurs ciblent exclusivement les
classes `auth-account-*` ajoutées dans ce même scope. La bibliothèque Contact ne
dépend pas de la bibliothèque compte. Le bloc Contact ne comporte aucune
condition de rôle : ses rendus anonyme et connecté restent indépendants de la
présentation du compte.

### Isolation de la PR #103 fusionnée

La PR #103 est désormais présente dans `release/prod` et ses fichiers d'accueil
éditorial ne sont pas modifiés ici. Son bloc `unisonges_editorial_home`, UUID
`8e6e9ece-878e-4bf8-b09e-a7638827a132`, utilise son propre plugin et le chemin
`/accueil`. Elle ne déclare aucun Webform, aucun espace de soumission et aucun
fichier Contact. Son ID, son UUID, sa route et son stockage restent donc
indépendants de `contact` et de `unisonges_contact_form`.

### Isolation de la PR #94 fusionnée

La PR #94 est fusionnée depuis le 8 septembre 2026, au commit
`3af525b2be480588866aaf1afabddb4a1c40f55d`. Elle n'est pas une dépendance
fonctionnelle du formulaire Contact. Ses six fichiers de pied de page et de
thème ne chevauchent aucun des cinq fichiers Contact. Les cinq sources de thème
fusionnées sont identiques à la version `3352898` déjà auditée. Cette version
conserve un seul rendu de `page.content` dans `main`, n'ajoute aucun
chemin de messages et ne modifie ni le Webform `contact`, ni son bloc, ni son
stockage.

La PR #94 consigne la restauration du checkout servant et la libération des
ressources après son retest. Cet état opérationnel n'est pas réinspecté ici :
une attribution explicite des ressources à la validation Contact reste requise.

### JavaScript historique

`drupal/web/themes/custom/unisonges_theme/js/contact-form.js` est orphelin :
aucune bibliothèque du thème ne le référence. La bibliothèque `contact`
désormais valide ne contient qu'une dépendance de mise en page. Le script vise
un ancien formulaire statique et un service Google Apps Script, collecte des
données supplémentaires (téléphone, URL et agent utilisateur), ne contrôle pas
le statut HTTP avant d'afficher un succès et n'apporte pas de validation serveur
Drupal.

Il reste donc inutilisé et inchangé. Le MVP repose exclusivement sur le rendu,
la validation et la confirmation standards de Webform. Son retrait éventuel et
la suppression de ce code mort appartiennent à une évolution distincte,
coordonnée avec le propriétaire du thème.

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

Le code verrouillé de Webform ferme bien la page autonome du formulaire
`/webform/contact` lorsque `page: false` et refuse les chargements par jeton
quand `token_view`, `token_update` et `token_delete` sont faux. Il déclare
néanmoins toujours la route générique `/webform/contact/confirmation`, dont
l'accès `webform.view` est assimilé à la création. Avec la confirmation inline
actuelle et `confirmation_exclude_token: true`, cette URL ne reçoit aucun jeton,
n'affiche aucune valeur soumise et ne donne aucun accès aux résultats ; elle
peut seulement afficher le texte statique de confirmation. Si « aucune route
Webform autonome » doit aussi interdire cette page générique, un contrôle de
route hors des cinq fichiers et une décision propriétaire sont indispensables.
Le présent MVP ne prétend pas la fermer silencieusement.

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

`form_submit_once: true` conserve en plus la protection native immédiate contre
les doubles clics. Cette protection côté client n'est pas une clé d'idempotence
serveur contre deux requêtes parallèles ou rejouées.

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

Le mode dry-run de `drupal/scripts/apply-contact-form-mvp-2026.sh` est le mode
par défaut. Son chemin Contact retourne après le préflight complet et avant les
verrous, la transaction ou tout `save()` : il ne modifie aucune configuration,
aucun contenu, alias ou soumission. Le script n'exécute aucun import de
configuration complet ou partiel. Son allowlist d'écriture contient exactement :

- `webform.webform.contact` ;
- `block.block.unisonges_contact_form`.

Avant toute écriture, le lanceur vérifie les chemins, l'intégrité Git des sources,
l'origine non productive explicite et les versions verrouillées. Le helper
valide ensuite les YAML et leur sémantique, les dépendances, les UUID, le thème,
les plugins, les permissions, la page `/contact`, les doublons, les alias, toutes
les soumissions et l'intégralité de la configuration active. Il accepte
uniquement l'état historique connu, l'état cible exact ou l'état de rollback
exact ; toute dérive ou installation partielle ferme l'exécution.

Cette garantie statique porte sur l'état fonctionnel contrôlé par le script. Une
commande Drush doit néanmoins démarrer Drupal ; sur un environnement froid, ce
bootstrap ou les services de lecture peuvent réchauffer des caches techniques.
L'absence littérale de toute écriture physique de cache ne peut donc pas être
démontrée hors runtime et doit être observée pendant la passe différée. Aucun de
ces caches n'appartient à l'allowlist Contact.

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

La PR #104 est fusionnée et fournit le contrôle de permissions du checkout. Sur
un futur staging explicitement autorisé sous `/var/www`, ce contrôle doit
retourner `PASS` avant tout bootstrap Drupal ou réchauffement de cache du
lanceur Contact. Il s'agit d'un garde opérationnel conditionnel, pas d'une
dépendance fonctionnelle, et il n'est pas exécuté dans ce worktree.

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
- comparaison avec les configurations Forum/Blog fusionnées : IDs, UUID,
  dépendances, accès, blocs, chemins et séparation des soumissions ;
- contrôle du contrat fusionné de la PR #78 qui conserve `/contact` comme Page
  de base canonique en lecture seule ;
- contrôle de la PR #100 fusionnée : un seul bloc de messages actif du thème,
  destination inline dans `main`, sans bloc Contact supplémentaire ;
- contrôle de la PR #99 fusionnée : route `/contact`, bibliothèque Contact et
  sélecteurs hors de la portée authentification/compte ;
- contrôle de la PR #103 fusionnée : fichiers, ID, UUID, bloc, route et
  espace de stockage indépendants ;
- contrôle statique de la version de la PR #94 ensuite fusionnée : aucun
  chevauchement de fichiers, aucun nouveau chemin de messages et aucune
  dépendance fonctionnelle Contact ;
- lecture des sources verrouillées Drupal 11.3.3 et Webform 6.3.0-beta7 : accès
  aux formulaires et soumissions, jetons, routes, validation des champs requis,
  e-mail, options fermées, longueurs, confirmation inline et override de langue ;
- unicité de 398 UUID et de 69 IDs de blocs dans le snapshot Contact audité sur
  la base `3e53bc5` ;
- `bash -n` et ShellCheck sur le lanceur ;
- `php -l` sur le helper ;
- `git diff --check`, garde de cinq fichiers, recherche de secrets et contrôle
  de non-chevauchement avec les PR concurrentes.

Le parser YAML utilisé pour ces contrôles est `yaml@2.8.1`, épinglé dans un
préfixe temporaire isolé sans ajouter de dépendance au dépôt. Les contrôles de
runtime Drupal restent ceux de la matrice ci-dessous.

## Matrice d'exécution différée

Les implémentations source Forum/Blog, menu public final, inscription visiteur,
intégrité des bibliothèques, titres sémantiques, cycle inline des messages de la
PR #100, présentation authentification/compte de la PR #99, accueil éditorial de
la PR #103 et garde opérationnel de la PR #104 sont fusionnés dans
`release/prod` à `3e53bc5b3d1b3ded9a207bc2e16ec48aa84b9bdf`. La branche Contact
est rebasée sur cet état. Le fetch du 8 septembre retrouve ensuite la fusion de
#94 à `3af525b`. Ses sources de thème déjà auditées sont identiques ; les
configurations, la pile verrouillée et les sources Contact/#99/#100 n'ont pas
changé. Cette seule fusion ne nécessite donc ni nouveau rebase ni répétition
des contrôles fonctionnels statiques.

La validation runtime reste à planifier dans une session Contact explicitement
autorisée, après la libération consignée par Terminal 3 / PR #94. Aucun DDEV,
Docker, Drush, Chromium, Playwright, Mailpit, navigateur, serveur local ou VPS
n'est utilisé pour ce rafraîchissement
statique. La PR #85 reste en brouillon ; cette préparation technique ne vaut ni
autorisation de publication, ni preuve d'activation en production.

### Ordre runtime restant

1. obtenir une attribution explicite des ressources pour la validation Contact ;
2. récupérer `release/prod`, comparer les entrées pertinentes si sa tête a
   avancé, puis actualiser la branche et les seuls contrôles affectés si cette
   comparaison le nécessite ;
3. dans le DDEV local autorisé, lancer le dry-run et l'application ciblée, sans
   import complet ou partiel ;
4. exécuter la matrice compacte ci-dessous ;
5. exécuter le no-op, le rollback, la réapplication et le nettoyage des données
   de test.

| Axe | Contrôles runtime à effectuer |
| --- | --- |
| Soumission et validation | Sur `/contact`, soumission valide anonyme puis authentifiée ; champs requis ; e-mail invalide ; objet forgé hors liste ; messages de 19 et 5 001 caractères ; charge HTML/script sans exécution ; conservation normale des valeurs non sensibles après erreur. |
| Accès | Propriétaire, autre compte et autre visiteur incapables d'énumérer, lire, modifier ou supprimer une soumission ; accès direct aux résultats et par jeton refusé ; aucune seconde page de formulaire sur `/webform/contact` ou `/form/contact` ; observer séparément la route statique `/webform/contact/confirmation` documentée ci-dessus ; administrateur capable d'examiner puis supprimer une demande. |
| Limites | 30 soumissions terminées par heure globalement ; 5 par heure par UID ou état anonyme Webform/session ; protection immédiate contre le double clic ; confirmer et documenter qu'il ne s'agit ni d'une limite IP, ni d'une protection contre le renouvellement de session. |
| Messages et affichage | Formulaire une seule fois et uniquement sur `/contact` ; erreurs adjacentes ; confirmation inline unique ; chemin global PR #100 unique dans `main`, sans toast ni message retardé ; aucune bibliothèque/classe PR #99 ; accueil PR #103 isolé ; clavier, affichages desktop/mobile, accessibilité, débordement, console et avertissements PHP. |
| Opérations | Dry-run sans écriture ; apply ; second dry-run ; second apply no-op ; rollback fermant le Webform et désactivant le bloc tout en conservant les soumissions ; réapplication ; aucune dérive hors allowlist ; zéro soumission de test après nettoyage. |

Mailpit doit rester inutilisé pendant toute cette matrice, puisque le Contact ne
possède aucun gestionnaire d'e-mail.

## Suivis hors périmètre

- faire approuver et publier une politique de confidentialité cohérente avec le
  stockage serveur ;
- faire approuver une politique de conservation, puis son processus de purge ;
- n'ajouter une livraison e-mail qu'après validation d'un destinataire et de la
  politique de confidentialité ;
- décider séparément d'une éventuelle protection anti-spam supplémentaire ;
- retirer séparément le JavaScript historique non référencé si sa suppression
  est approuvée.

Avant publication, le propriétaire doit accepter explicitement les limites
anti-abus résiduelles, faire approuver l'information de confidentialité et le
processus de conservation, puis autoriser le déploiement après une matrice
runtime entièrement réussie. Aucun destinataire e-mail n'est requis pour ce MVP
à stockage privé ; il ne deviendrait nécessaire que pour une livraison e-mail
future séparément approuvée. La décision sur la fermeture de la route générique
de confirmation Webform reste également explicite si son accessibilité statique
est jugée incompatible avec le contrat produit.
