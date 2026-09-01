# Cycle de vie des messages système Drupal — 2026

## Périmètre et statut

Cette correction part exactement de `origin/release/prod` au commit
`2bfb2b3b57bffcdbef72306a96a1c7f8a4055002`, sur la branche
`codex-fix-delayed-system-messages`. Elle ne crée ni ne modifie aucune URL
publique, ne touche pas Drupal core et ne désactive pas BigPipe en production.
Les fichiers détenus par d'autres PR, `styles.css`, `page.html.twig` et
`page--front.html.twig`, restent inchangés.

Codex n'a consulté ni modifié aucun VPS, endpoint public de production, DNS ou
routage. Après la validation locale, le propriétaire a toutefois exécuté le
helper en lecture seule sur la production et a communiqué la preuve consignée
plus bas. Ce dry-run confirme l'état actif sans avoir effectué d'écriture.

## Cause établie

Le défaut associe une dérive de placement active et le cycle de vie normal du
messenger Drupal. BigPipe révèle le message abandonné, mais ne le crée pas.

| Couche | Constat |
| --- | --- |
| Configuration synchronisée | `block.block.unisonges_theme_messages` est activé, sans condition de visibilité, dans `content`, poids `-8`. |
| Historique | `5c8cc025` avait créé le même bloc dans `header`, poids `-6`; `d190d4e3` a corrigé seulement le YAML synchronisé vers `content/-8`. |
| Déploiement | `scripts/deploy-staging.sh` exécute `git pull`, `composer install`, `updb` et `cr`, mais aucun import de configuration ni helper ciblé. |
| Shell du thème | Les deux templates de page rendent `page.content`, jamais `page.header`. Un bloc actif resté dans `header` n'est donc pas construit. |
| Messenger | L'échec de connexion est stocké dans le `FlashBag` de session. `StatusMessages::renderMessages()` appelle `deleteAll()` seulement lorsque le bloc est réellement rendu. |
| Cache | Le messenger déclenche le kill switch de cache. Page Cache et Dynamic Page Cache ne sont pas la source de la persistance observée. |
| BigPipe | Le placeholder normal `status_messages` est exclu de BigPipe. En revanche, un message de session resté sans consommateur est ensuite retiré par BigPipe et encodé dans un `MessageCommand`. |
| JavaScript | Sans destination `[data-drupal-messages]`, `Drupal.Message.defaultWrapper()` crée un conteneur à la fin de `body`, donc hors du contenu principal. |
| Login | Le formulaire core `/user/login` est un POST non-AJAX; aucun alter custom et aucun marqueur AJAX n'a été trouvé. Le chemin JavaScript pertinent est le `MessageCommand` BigPipe ou celui d'une vraie réponse AJAX. |
| Géométrie Barrio | Avec le bon placement mais le template Barrio par défaut, `.alert-wrapper` reste `position: fixed`, `z-index: 9999`; la variante toast est elle aussi fixe. |

L'ordre causal reproduit est donc :

```text
POST /user/login invalide
  -> message ajouté à la session
  -> bloc actif dans header, région non rendue
  -> aucune consommation sur la réponse du POST ni les GET suivants
  -> BigPipe rencontre plus tard le flash restant
  -> MessageCommand après le HTML initial
  -> fallback absent, insertion dans body hors main
```

Sans BigPipe, la dernière étape n'a pas lieu, mais le flash reste dans la
session à travers plusieurs requêtes. Réactiver BigPipe dans cette même session
le fait alors apparaître sur une page sans rapport. Désactiver BigPipe masque
donc le révélateur sans corriger la cause.

## Reproduction exacte avant correction

Le thème public et sa configuration synchronisée ont été chargés dans DDEV,
puis le seul bloc actif `system_messages_block` du thème a été remis dans son
état historique exact `header/-6`. Un unique identifiant invalide a été soumis
depuis `/user/login`.

- erreur attendue enregistrée :
  `Unrecognized username or password. Forgot your password?`;
- réponse du POST : HTTP 200, zéro message visible et aucune destination de
  messages dans `main`;
- GET explicite de login, reload normal et reload cache navigateur désactivé :
  toujours zéro message visible;
- navigation vers `/user/password` avec BigPipe : un `MessageCommand` contenant
  l'ancienne erreur est arrivé après le HTML initial;
- le conteneur JavaScript avait `body` pour parent et se trouvait hors de
  `main`; en desktop, son rectangle commençait à `y=80` alors que le header
  finissait à `y=110.046875`, soit un chevauchement mesuré;
- back/forward et reloads suivants n'ont créé aucun second POST; le message
  tardif était bien celui de la session initiale;
- avec BigPipe désinstallé localement, cinq réponses ultérieures n'ont rendu
  aucun message; après réactivation, la même session l'a reçu sur
  `/user/password`, ce qui prouve la persistance multi-requêtes.

Une seconde baseline a placé le bloc dans `content/-8` tout en conservant le
template Barrio. L'erreur était alors immédiate et dans `main`, mais son wrapper
restait fixe avec `z-index: 9999`. En mobile, le rectangle fixe intersectait
géométriquement le drawer ouvert. Ces deux baselines séparent le défaut de
consommation du défaut de positionnement visuel.

## Correction livrée

La correction reste limitée à deux politiques complémentaires :

1. `unisonges_theme_theme_suggestions_status_messages_alter()` sélectionne le
   template de messages du thème enfant après les suggestions Barrio, y compris
   lorsque la préférence active de Barrio vaut `alerts` ou `toasts`.
2. `status-messages--unisonges-inline.html.twig` rend le wrapper
   `[data-drupal-messages]` en flux normal, avec un enfant permanent
   `.messages__wrapper`. Les messages serveur et les futurs `MessageCommand`
   partagent ainsi une destination stable sans wrapper fixe.

Le template conserve :

- `status`, `warning`, `error` et `info`;
- `.alert-success`, `.alert-warning`, `.alert-danger` et `.alert-info`;
- `role="status"` pour un statut et `role="alert"` pour un avertissement ou une
  erreur;
- le titre invisible de sévérité et le HTML sûr des messages Drupal;
- le bouton de fermeture Barrio, sans CSS ou JavaScript custom supplémentaire.

Il ne masque aucun message, n'ajoute aucun subscriber et ne modifie ni le
scrollframe, ni le header, ni les styles globaux.

## Helper de placement actif

`scripts/apply-system-message-placement-2026.sh` est dry-run par défaut. Il
n'exécute jamais `config:import` ou `config:export`, ne crée pas de bloc et ne
peut écrire que `region` et `weight` sur
`block.block.unisonges_theme_messages`.

Il refuse notamment :

- un bloc absent, désactivé, dupliqué, d'un autre thème ou d'un autre plugin;
- une visibilité active ou synchronisée non vide;
- tout état autre que le legacy complet `header/-6` ou la cible complète
  `content/-8`;
- une différence entre la configuration brute et une configuration effective
  soumise à un override runtime;
- un fichier cible/helper ou un répertoire de sync symlinké;
- un chemin `/var/www` sans acquittement explicite, et un faux contexte DDEV
  dont les racines canoniques ne correspondent pas au projet;
- `--apply` sans sauvegarde confirmée et sans le jeton SHA-256 produit par le
  dry-run sur l'état exact;
- un succès Drush sans marqueurs internes attendus. Le binaire utilisé est
  exclusivement `vendor/bin/drush`; la variable `DRUSH` est ignorée.

L'écriture utilise l'objet de configuration éditable, sans overrides, puis
relit l'entité override-free. Toutes les clés autres que `region` et `weight`
sont comparées. En cas d'écart, l'instantané actif complet est restauré et ce
rollback est lui-même vérifié. Si le placement est validé mais que le rebuild
de cache échoue, le script sort en erreur et demande explicitement de réparer
le rebuild sans répéter aveuglément l'application.

### Procédure opérateur

Prérequis : checkout revu au commit de la PR, dépendances Composer verrouillées
installées, Drupal bootstrapable, cible et base identifiées, aucune écriture de
configuration concurrente, sauvegarde de base récente et restaurable.

Dans DDEV local, `--allow-vps` est volontairement interdit :

```bash
cd drupal
ddev exec ./scripts/apply-system-message-placement-2026.sh --dry-run
```

Sur un checkout `/var/www` indépendamment approuvé, l'option est uniquement un
acquittement de chemin; elle ne constitue jamais une autorisation d'accès ou
d'écriture sur staging/production :

```bash
cd /var/www/<checkout-approuve>/drupal
./scripts/apply-system-message-placement-2026.sh --dry-run --allow-vps
```

Relire `SYNC`, `ACTIVE`, `ROLLBACK`, `PLAN` et copier la valeur exacte de
`PLAN_TOKEN`. Après confirmation séparée de la sauvegarde et de la fenêtre de
changement :

```bash
./scripts/apply-system-message-placement-2026.sh \
  --apply \
  --backup-confirmed \
  --plan-token=<HASH_DU_DRY_RUN> \
  --allow-vps

./scripts/apply-system-message-placement-2026.sh --dry-run --allow-vps
```

Le dry-run final doit afficher `content/-8` et `NO_CHANGE`. Un nouveau dry-run
est obligatoire dès que l'état ou le YAML change, car l'ancien jeton sera
refusé. Le retour arrière opérationnel est la restauration de la sauvegarde
prise avant application; ne pas lancer d'import global pour inverser cette
seule écriture.

### Dry-run production fourni par le propriétaire

Le 1er septembre 2026, le propriétaire a exécuté un dry-run en lecture seule
contre le head exact de cette PR. Codex n'a pas accédé au VPS et ne possède ni
ne reproduit le jeton opérationnel émis pendant cette exécution.

| Élément | Valeur contrôlée |
| --- | --- |
| Branche de production | `release/prod` |
| HEAD de production | `2bfb2b3b57bffcdbef72306a96a1c7f8a4055002` |
| HEAD PR #100 testé | `ad917a3972ce6a9e23cd061943bad411e9537b20` |
| Objet synchronisé | `block.block.unisonges_theme_messages` |
| Cible synchronisée | activé, thème `unisonges_theme`, plugin `system_messages_block`, région `content`, poids `-8` |
| État actif production | activé, thème `unisonges_theme`, plugin `system_messages_block`, région `header`, poids `-6` |
| État de rollback | région `header`, poids `-6` |
| Plan exact | modifier uniquement la région de `header` vers `content` et le poids de `-6` vers `-8` |
| Résultat | `DRY_RUN_OK`; aucune configuration active écrite |

L'état actif production `header/-6` correspond exactement à l'état historique
reproduit localement. Il confirme donc la cause racine diagnostiquée : le bloc
actif se trouve dans la région que les shells publics ne rendent pas, tandis
que le sync revu contient déjà la cible `content/-8`.

Pour exécuter le helper avant sa fusion sans modifier durablement le checkout,
le propriétaire a extrait temporairement depuis le head PR testé les deux
fichiers du helper à leurs chemins attendus sous `drupal/scripts/`, exécuté
uniquement le wrapper en mode `--dry-run --allow-vps`, puis supprimé ces deux
fichiers temporaires. Le checkout production est resté propre et inchangé. Le
journal opérateur est conservé sur la cible à l'emplacement :

```text
/tmp/pr100-production-dry-run-20260901-185955.log
```

Le `PLAN_TOKEN` de cette capture est opérationnel et éphémère. Il ne doit pas
être copié dans Git, la PR, un ticket ou une commande post-fusion, et ne doit
jamais être présenté comme réutilisable. Après fusion et déploiement du head
final, l'opérateur doit obligatoirement :

1. confirmer le commit réellement déployé et l'absence d'écriture concurrente;
2. prendre une sauvegarde courante et restaurable de la base;
3. exécuter un nouveau dry-run depuis les fichiers déployés;
4. relire l'identité, le sync, l'actif, le rollback et le plan;
5. utiliser uniquement le nouveau jeton avec `--apply --backup-confirmed`;
6. vérifier le succès du rebuild de cache;
7. relancer le dry-run et obtenir `ACTIVE ... region=content weight=-8` puis
   `NO_CHANGE`;
8. confirmer que le bloc reste activé, sans restriction de visibilité, avec le
   thème et le plugin attendus, et que le checkout demeure propre;
9. effectuer le smoke test anonyme : une erreur sur le POST invalide, aucune
   erreur sur le GET étranger suivant et aucun chevauchement de navigation.

Si l'actif, le sync ou le plan diffère, ou si aucune sauvegarde courante n'est
disponible, l'application doit être abandonnée et aucun ancien jeton ne doit
être essayé.

## Validation Drupal et Chromium

Le snapshot DDEV
`system-message-lifecycle-pretest-20260901T171019Z` a été créé avant toute
mutation runtime. Drupal 11.3.3, PHP 8.3, Drush 13.7 et Chromium 140 via
Playwright 1.55 ont servi à la validation.

### Matrice de cycle de vie

La matrice cartésienne complète contient 16 cas :

| Axe | Valeurs |
| --- | --- |
| BigPipe | activé, désactivé localement |
| Cache Drupal | froid après `drush cr`, chaud après préchauffage des routes |
| Session | profil Chromium persistant normal, contexte privé neuf |
| Viewport | desktop 1440 × 900, mobile tactile 390 × 844 |

Chaque cas exécute exactement un POST invalide, puis : GET explicite de login,
reload normal, reload avec cache navigateur désactivé, `/user/password`, `/`,
trois retours, trois avances et un dernier reload. Un `MutationObserver`, les
scripts `application/vnd.drupal-ajax`, les réponses document, les événements
`pageshow`, les erreurs console et la géométrie sont enregistrés.

Résultat final sur le commit corrigé : **16/16 cas réussis**.

- l'erreur attendue apparaît exactement une fois sur le document du POST;
- elle se trouve dans le HTML serveur, sans `MessageCommand` embarqué;
- le wrapper unique est dans `main` et le scrollframe, jamais dans le header;
- sa position calculée est `static`, son `z-index` est `auto`;
- desktop : message `y=143..168.59375`, header
  `y=0..110.046875`, scrollframe `y=122..888`;
- mobile : message `y=96..147.1875`, header `y=0..63.171875`;
- aucune occurrence n'existe sur les 14 états ultérieurs desktop ou les 16
  états ultérieurs mobile;
- aucune commande BigPipe ultérieure ne contient l'erreur;
- zéro réponse 5xx, requête document échouée ou erreur console;
- drawer mobile ouvert : les liens Home, Réserver, Se connecter, Créer un
  compte et le bouton Fermer reçoivent tous leur point d'interaction.

Un contrôle intersession séparé place le POST en contexte A puis visite
`/user/password` et `/` en contexte B : compteurs `A POST=1`, `A suivant=0`,
`B password=0`, `B home=0`. Aucun message ne fuit entre sessions.

### AJAX et sévérités

Le POST core de login a `method=post`, zéro marqueur AJAX et zéro callback
custom. Un faux paramètre AJAX n'est donc pas un scénario valide. Le test
charge plutôt les actifs exacts de `core/drupal.message` et de l'extension
Barrio attachés par un vrai `MessageCommand`, retire l'alerte serveur puis
ajoute successivement les trois types via `Drupal.Message`.

Dans chacun des 16 cas :

- `.messages__wrapper` reste le premier enfant permanent;
- statut AJAX : `.alert-success`, `role=status`;
- avertissement AJAX : `.alert-warning`, `role=alert`;
- erreur AJAX : `.alert-danger`, `role=alert`;
- les trois sont insérés dans le wrapper de `main`, puis retirés sans exception.

Le renderer Drupal a aussi reçu simultanément trois sentinelles serveur. Il a
rendu les trois textes, les mêmes classes/rôles et aucun `.alert-wrapper`,
toast ou conteneur fixe. Enfin, la soumission réelle de `/user/password` avec
un identifiant inexistant a rendu une fois le statut générique dans
`.alert-success[role=status]` et `.messages__wrapper`.

### Helper et contrôles statiques

Les scénarios suivants réussissent :

- dry-run target idempotent avec `NO_CHANGE`;
- dry-run legacy `header/-6` sans écriture et avec jeton valide;
- refus sans `--backup-confirmed`;
- refus d'un jeton erroné ou périmé, sans écriture;
- application avec le bon jeton, puis diff vide après suppression de
  `region`/`weight` dans les snapshots JSON avant/après;
- refus d'un état croisé `content/-6`, d'une visibilité restreinte et d'un
  second bloc actif;
- `DRUSH=/bin/true` n'a aucun effet : le Drush local exécute réellement l'audit;
- `bash -n`, `shellcheck`, `php -l`, parsing Symfony YAML et
  `git diff --check` réussissent;
- `composer validate --no-check-publish` réussit avec l'avertissement existant
  `require.twbs/bootstrap: *`; le mode `--strict` échoue uniquement sur ce même
  avertissement préexistant, sans rapport avec le diff;
- la revue des PR ouvertes vers `release/prod` trouve un seul chemin partagé :
  la PR #99 modifie aussi `unisonges_theme.theme`. Les hunks sont distincts et
  `git merge-tree` ne signale aucun conflit textuel entre les heads contrôlés;
  l'ordre de fusion doit néanmoins déclencher une nouvelle vérification.

Les artefacts JSON, journaux et captures de cette validation sont temporaires
et restent sous
`/tmp/system-message-lifecycle-playwright-20260901T171019Z/`; aucun cookie ni
header `Set-Cookie` n'est persisté dans les JSON.

## Restauration locale

Après les tests, le snapshot prétest a été restauré. Une lecture de contrôle a
confirmé l'état initial : Olivero/Claro sont les seuls thèmes actifs, aucun bloc
ou réglage actif de test Uni-Songes ne subsiste, BigPipe est activé, le front
est `/node`, les événements flood du test ont disparu et le watchdog est revenu
à son historique antérieur. Le même snapshot a ensuite été restauré une seconde
fois afin que ces lectures de contrôle ne laissent même pas de cache Drupal
recalculé.

Le checkout de service a été remis sur `release/prod` au commit initial
`a673a078430501d29f1631b96edf57cb65ec4c19`, avec un statut Git propre. DDEV et
son routeur sont arrêtés. Aucun état de base actif propre à cette PR n'est donc
laissé dans l'environnement partagé.
