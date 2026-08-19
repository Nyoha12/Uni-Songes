# Inscription publique des comptes 2026

Cette note définit le changement minimal qui ouvre la création de compte aux
visiteurs pour le tunnel de réservation, tout en imposant la validation de
l'adresse email. Elle ne modifie ni le tunnel, ni le thème, ni les rôles, ni les
comptes existants.

## Politique retenue

Le dépôt verrouille Drupal Core 11.3.3. Son schéma
`web/core/modules/user/config/schema/user.schema.yml`, depuis la racine Drupal,
définit :

- `user.settings:register` comme une chaîne limitée à `visitors`, `admin_only`
  ou `visitors_admin_approval` ;
- `user.settings:verify_mail` comme un booléen qui impose la validation de
  l'adresse email lors d'une auto-inscription.

La configuration versionnée avant ce changement était :

```yaml
register: admin_only
verify_mail: true
```

La cible est :

```yaml
register: visitors
verify_mail: true
```

`visitors` rend `/user/register` accessible aux anonymes et crée le compte actif
sans file d'approbation administrateur. Avec `verify_mail: true`, Drupal génère
le mot de passe initial et envoie le message `register_no_approval_required`
contenant le lien à usage unique : le visiteur doit contrôler cette adresse
avant de pouvoir choisir son mot de passe et se connecter. La notification
`notify.register_no_approval_required` est déjà activée et reste inchangée.

Cette politique ne met à jour aucune entité utilisateur et aucune configuration
de rôle. Les comptes, statuts et attributions de rôles existants restent donc
inchangés.

## Destination et CTA existants

Les CTA « Créer un compte » existants sont conservés. En particulier, le tunnel
construit déjà le lien vers :

```text
/user/register?destination=/reservation-cours
```

Le formulaire Drupal conserve cette query string lors du POST et le mécanisme
de redirection du core donne priorité à une destination locale. La vérification
fonctionnelle doit confirmer que `destination=/reservation-cours` est toujours
présent dans le lien et l'action du formulaire, puis que la soumission revient
immédiatement au tunnel. Comme la validation email est obligatoire, ce retour
immédiat reste anonyme ; le lien à usage unique généré par le core ne transporte
pas la query string d'origine. Après validation et choix du mot de passe, le
testeur revient donc au tunnel pour poursuivre la réservation.

Aucune URL publique, aucun CTA, aucun template et aucun code du tunnel n'est
ajouté ou modifié dans ce changement.

## Script d'application ciblée

Le script `drupal/scripts/apply-user-registration-policy-2026.sh` :

- est en dry-run par défaut et exige `--apply` pour écrire ;
- refuse tout chemin sous `/mnt/c` ;
- exige `--allow-vps` sous `/var/www` ;
- lit les configurations active stockée et effective (overrides inclus), puis
  valide la forme et les types Drupal 11.3.3 avec `jq` ;
- refuse toute divergence visible entre `register` ou `verify_mail` stocké et
  effectif ;
- exige en lecture seule que `notify.register_no_approval_required` soit
  effectivement activé, sans écrire cette clé hors allowlist ;
- si la vérification effective est désactivée, applique un garde
  `admin_only + true` dans une même sauvegarde et exige ces deux valeurs
  effectives avant toute ouverture ; cette séquence n'ouvre pas l'inscription
  si un override répète initialement une valeur stockée ;
- enregistre toujours `verify_mail` et `register` ensemble dans chaque
  sauvegarde ciblée ;
- n'écrit que `user.settings:verify_mail` et `user.settings:register` avec des
  valeurs YAML typées ;
- relit les valeurs, vérifie qu'aucune autre clé de `user.settings` n'a changé,
  et tente un rollback ciblé si l'application échoue ;
- n'exécute aucun import de configuration, complet ou partiel.

Prérequis : dépendances Composer installées, Drupal amorçable avec le Drush
local et `jq` disponible. Depuis la racine Drupal :

```bash
cd drupal
./scripts/apply-user-registration-policy-2026.sh
./scripts/apply-user-registration-policy-2026.sh --apply
```

## Préparation et application en staging

Avant toute écriture en staging, identifier la branche et le commit déployés,
confirmer le bootstrap Drupal, puis réaliser une sauvegarde de base selon la
procédure d'exploitation approuvée. Conserver la référence de cette sauvegarde
avec la sortie du dry-run.

Sur un déploiement situé sous `/var/www`, l'accusé de réception du chemin est
obligatoire :

```bash
cd /var/www/chemin-vers-le-deploiement/drupal
git status --short
git branch --show-current
git rev-parse HEAD
./vendor/bin/drush status --fields=bootstrap,db-status,uri
./scripts/apply-user-registration-policy-2026.sh --dry-run --allow-vps
```

Après sauvegarde de la base et revue explicite des valeurs `Before`, `Target`
et `Rollback`, appliquer puis relire la configuration :

```bash
cd /var/www/chemin-vers-le-deploiement/drupal
./scripts/apply-user-registration-policy-2026.sh --apply --allow-vps
./vendor/bin/drush cache:rebuild
./vendor/bin/drush config:get user.settings --format=yaml
./vendor/bin/drush config:get user.settings --include-overridden --format=yaml
```

Il ne faut pas remplacer ces commandes par un `config:import`, même partiel.

## Rollback

Le script distingue les valeurs `Before` capturées pour audit de la cible de
rollback automatique. Cette cible conserve toujours `verify_mail: true`. Si un
environnement dérivé avait désactivé la vérification, elle force aussi
`register: admin_only` : le rollback ne rétablit jamais une paire publique sans
validation email. Pour la version de départ documentée ici, les valeurs de
rollback sont également `register: admin_only` et `verify_mail: true` ; elles
sont restaurées ensemble par une seule sauvegarde ciblée :

```bash
vendor/bin/drush config:set --input-format=yaml user.settings '?' \
  '{"register":"admin_only","verify_mail":true}' --yes
```

Le rollback ne nécessite aucun import de configuration et ne touche ni les
comptes ni les rôles. Si les valeurs actives de départ avaient dérivé, utiliser
la cible de rollback sûre affichée par le script, et non aveuglément les valeurs
`Before`.

## Vérification DDEV et Mailpit exécutée

Le protocole a été exécuté le 14 août 2026, puis rejoué intégralement le 19 août
après l'interruption de la machine. Les deux passages ont utilisé le projet DDEV
local principal avec DDEV 1.25.3, PHP 8.3, MariaDB 10.11 et Drupal Core 11.3.3.
Le dépôt principal, sans modification suivie sur `release/prod`, a servi en HEAD
détachée le commit applicatif rebasé
`dabf51d0cba0b2674acfc5a7b544ceeece41c3c1`. Aucune configuration `.ddev` ni
aucun fichier versionné n'ont été édités. Après chaque passage, le checkout
principal a été replacé sur `release/prod` et le projet DDEV a été arrêté.

Le transport PHP constaté pendant le test était exclusivement le Mailpit local :

```text
/usr/local/bin/mailpit sendmail -t --smtp-addr 127.0.0.1:1025
```

Aucun VPS et aucun transport mail externe n'ont été utilisés. Les commandes
d'application rejouées dans le conteneur étaient :

```bash
cd /home/yohan/Uni-Songes/repo/drupal
ddev describe --json-output
ddev exec ./scripts/apply-user-registration-policy-2026.sh --dry-run --allow-vps
ddev exec ./scripts/apply-user-registration-policy-2026.sh --apply --allow-vps
ddev exec vendor/bin/drush config:get user.settings --format=yaml
ddev exec vendor/bin/drush config:get user.settings --include-overridden --format=yaml
```

Lors du premier passage, l'état actif initial était `register: admin_only` et
`verify_mail: true` ; `/user/register` répondait alors HTTP 403. Le dry-run n'a
rien écrit, puis `--apply` a produit `register: visitors` et
`verify_mail: true`, à la fois dans la configuration stockée et dans la
configuration effective. Le rejeu du 19 août a retrouvé cette cible après le
redémarrage ; dry-run et apply ont confirmé le même état, sans dérive. La
notification `register_no_approval_required` est restée effectivement activée.

Résultats HTTP et Mailpit avec un nom et une adresse `@example.test` uniques :

1. `/user/register?destination=/reservation-cours` a répondu HTTP 200. L'action
   du formulaire était exactement
   `/user/register?destination=/reservation-cours` ; aucun champ de mot de
   passe n'était présent.
2. Le rendu des formulaires d'inscription et de profil n'exposait aucun champ
   de crédit, d'essai ou d'expiration (`field_seances_restantes`,
   `field_essai_utilise`, `field_pack_expire_le`).
3. Depuis une session anonyme neuve, un email invalide et un nom vide ont
   renvoyé HTTP 200 avec `The email address … is not valid` et
   `Username field is required`, sans création de compte.
4. La soumission valide a renvoyé HTTP 303 vers
   `https://unisonges.ddev.site/reservation-cours`. La session est restée
   anonyme et `/user` redirigeait encore vers `/user/login` : il n'y a donc eu
   aucune connexion automatique. Avec l'absence de champ mot de passe à
   l'inscription, la suite du test confirme que le lien reçu est nécessaire au
   choix initial du mot de passe.
5. Mailpit a capturé exactement un message pour l'adresse de test unique,
   intitulé `Account details for … at Uni-Songes Local`. Son lien à usage
   unique a ouvert `user_pass_reset`, authentifié le compte et permis
   d'enregistrer le mot de passe depuis le formulaire `user_form`.
6. Le compte temporaire était actif, sans attente d'approbation administrateur,
   avec le seul rôle effectif `authenticated` et aucune attribution de rôle
   explicite.
7. Après l'enregistrement du mot de passe, la même session issue du lien à usage
   unique était authentifiée et a reçu HTTP 200 sur `/reservation-cours`. Le
   lien email standard ne conserve pas `destination` : ce GET explicite prouve
   la restauration manuelle du tunnel. En complément, une session neuve s'est
   connectée depuis `/user/login?destination=/reservation-cours` ; Drupal a
   répondu HTTP 303 vers `/reservation-cours?check_logged_in=1`, avec un statut
   de session authentifiée égal à `1` et un tunnel en HTTP 200.
8. Dans deux nouvelles sessions anonymes, une tentative avec le même nom et une
   autre adresse a renvoyé `The username … is already taken`, puis une tentative
   avec un autre nom et la même adresse a renvoyé
   `The email address … is already taken`. Les deux réponses étaient HTTP 200.
   Aucun compte supplémentaire et aucun second mail pour l'adresse de test
   n'ont été créés.

Le compte temporaire du premier passage avait reçu l'UID 7 ; celui du rejeu a
reçu l'UID 8. Tous deux ont été supprimés par l'API d'entité avec des gardes
strictes sur l'UID, le nom et l'adresse. Lors du rejeu, une photographie
canonique avant/après suppression a retrouvé exactement les UID 0 à 6 et les
mêmes empreintes pour chaque entité utilisateur et ses rôles, les quatre
configurations `user.role.*`, les autres clés de `user.settings` et les 314
objets de configuration active. La comparaison n'excluait que les deux clés de
politique autorisées ; celles-ci étaient déjà identiques avant/après le rejeu.

Enfin, le dry-run final du 19 août a affiché `visitors + true` dans `Before`,
`Target` et `After`. Les objets `user.settings` stocké et effectif sont restés
identiques avant/après ce dry-run, et
`/user/register?destination=/reservation-cours` répondait toujours HTTP 200.
Le script est donc idempotent sur l'état cible.

## Validations statiques de la PR

Les contrôles suivants ont été exécutés avec succès le 19 août 2026 avant
commit :

```bash
git diff --check
bash -n drupal/scripts/apply-user-registration-policy-2026.sh
python3 - <<'PY'
from pathlib import Path
import yaml

data = yaml.safe_load(
    Path('drupal/config/sync/user.settings.yml').read_text(encoding='utf-8')
)
assert data['register'] == 'visitors'
assert data['verify_mail'] is True
print('user.settings.yml: syntaxe et politique OK')
PY
```

Après indexation explicite des trois fichiers autorisés :

```bash
git diff --cached --check
git diff --cached --name-only
```

La dernière commande doit afficher exactement, quel que soit l'ordre :

```text
docs/functional/account-registration-2026.md
drupal/config/sync/user.settings.yml
drupal/scripts/apply-user-registration-policy-2026.sh
```

Le script ne contient pas de PHP embarqué ; aucune validation de syntaxe PHP
supplémentaire n'est donc nécessaire.
