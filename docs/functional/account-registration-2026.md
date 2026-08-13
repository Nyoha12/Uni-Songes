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

## Vérification DDEV et Mailpit différée

Cette PR doit rester en brouillon jusqu'à l'exécution ultérieure de ce protocole.
DDEV n'est volontairement pas utilisé pendant les travaux parallèles actuels et
aucun email externe réel ne doit être envoyé.

Dans un checkout DDEV isolé de cette branche, dont le transport sortant est
Mailpit :

```bash
cd /chemin/vers/le-checkout-ddev/drupal
ddev describe
ddev exec ./scripts/apply-user-registration-policy-2026.sh --dry-run --allow-vps
ddev exec ./scripts/apply-user-registration-policy-2026.sh --apply --allow-vps
ddev exec vendor/bin/drush config:get user.settings --format=yaml
ddev exec vendor/bin/drush config:get user.settings --include-overridden --format=yaml
ddev launch '/user/register?destination=/reservation-cours'
ddev launch -m
```

Vérifier ensuite avec une adresse de test unique :

1. un anonyme reçoit HTTP 200 sur
   `/user/register?destination=/reservation-cours` ;
2. le lien du tunnel et l'action du formulaire conservent le paramètre
   `destination=/reservation-cours` ;
3. la soumission crée un compte actif sans action d'un administrateur, ne
   connecte pas immédiatement le visiteur et revient à `/reservation-cours` ;
4. Mailpit reçoit le message de bienvenue avec un lien à usage unique, sans
   qu'aucun message sorte vers un transport externe ;
5. le lien permet de définir le mot de passe et de se connecter ;
6. l'utilisateur peut revenir sur `/reservation-cours` et continuer jusqu'aux
   étapes authentifiées du tunnel ;
7. un second dry-run affiche déjà la cible et n'annonce aucune écriture ;
8. les comptes et rôles présents avant le test sont inchangés, hors compte de
   test explicitement créé.

Supprimer ensuite le compte de test par le processus local approuvé. Ne pas
effectuer ce test sur le VPS et ne pas rendre la PR prête à fusionner avant que
les résultats DDEV et Mailpit soient consignés.

## Validations statiques de la PR

Les contrôles attendus avant commit sont :

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
