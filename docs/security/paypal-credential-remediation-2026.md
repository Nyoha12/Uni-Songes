# Remédiation des credentials PayPal — 2026

## Portée et état audité

Cette remédiation est strictement statique. Elle ne contacte ni PayPal ni le
VPS, n'importe aucune configuration Drupal et ne modifie aucun paiement,
commande, produit, prix, checkout, webhook ou stock métier.

Le dépôt GitHub est public. L'audit de la configuration suivie a identifié un
seul objet de passerelle PayPal :

- objet : `commerce_payment.commerce_payment_gateway.paypal` ;
- plugin : `paypal_checkout` ;
- état avant remédiation : activé ;
- mode conservé : `sandbox` ;
- `configuration.client_id` : non vide dans `HEAD` et dans l'historique ;
- `configuration.secret` : non vide dans `HEAD` et dans l'historique ;
- `configuration.webhook_id` : vide.

`drupal/composer.lock` verrouille Commerce PayPal `2.1.0`. Son schéma définit
`configuration.client_id` et `configuration.secret` comme des chaînes, et ses
valeurs par défaut sont des chaînes vides. Les chaînes vides sont donc le format
exportable retenu.

## Architecture fail-closed retenue

L'export
`drupal/config/sync/commerce_payment.commerce_payment_gateway.paypal.yml`
contient désormais des chaînes vides pour les deux credentials et désactive la
passerelle. Cette désactivation versionnée est nécessaire : une passerelle
activée avec des valeurs vides reste sélectionnable dans Commerce et échoue
seulement plus tard pendant l'initialisation du SDK ou l'authentification.

Le fichier suivi, hors webroot,
`drupal/config/runtime/paypal.settings.php` lit exclusivement :

- `UNISONGES_PAYPAL_CLIENT_ID` ;
- `UNISONGES_PAYPAL_CLIENT_SECRET`.

Il applique des overrides Drupal `$config` imbriqués aux deux chemins exacts et
à `status`. La passerelle n'est activée au runtime que si les deux variables
contiennent une valeur après normalisation. Si l'une est absente, vide ou ne
contient que des espaces, les deux credentials effectifs sont forcés à vide et
`status` reste faux. Il n'existe aucun fallback et aucune valeur n'est
journalisée.

Les overrides de `settings.php` sont des couches runtime sur la configuration
immutable. Commerce charge normalement la configuration effective de l'entité
gateway et transmet son tableau `configuration` au plugin. La configuration
éditable et l'export YAML restent donc credential-free.

## Installation runtime requise

Le vrai `drupal/web/sites/default/settings.php` est volontairement non suivi.
Après déploiement du code et avant toute activation PayPal, le propriétaire doit
y inclure le helper suivi, après les autres includes propres à l'environnement :

```php
$unisonges_paypal_settings_file = dirname($app_root) . '/config/runtime/paypal.settings.php';
if (is_readable($unisonges_paypal_settings_file)) {
  require $unisonges_paypal_settings_file;
}
unset($unisonges_paypal_settings_file);
```

L'absence du helper ne doit pas bloquer le bootstrap des pages sans rapport avec
le paiement : l'export désactivé reste alors la source effective et PayPal reste
indisponible.

Les deux valeurs réelles doivent être placées uniquement dans le stockage de
secrets ou d'environnement approuvé du VPS, jamais dans le checkout Git, un
fichier `.env` suivi, une commande documentée, un ticket, un log ou une capture.
Le fichier `drupal/infra/paypal.env.example` ne contient que les noms de
variables avec des valeurs vides.

Les variables doivent être visibles du processus web PHP. Si les commandes CLI
servent aux vérifications de déploiement, leur processus PHP doit recevoir le
même contrat d'environnement. Ne pas afficher les variables et ne pas utiliser
de commande de configuration qui imprime les valeurs effectives.

Le mode versionné reste `sandbox`. La valeur legacy est traitée comme sandbox
par Commerce PayPal `2.1.0`; sa normalisation éventuelle vers `test` est hors de
ce changement ciblé.

## Prévention de réintroduction

Avant chaque commit et après chaque export de configuration :

```bash
cd drupal
./scripts/check-tracked-payment-secrets-2026.sh
```

Le garde inspecte les gateways YAML suivies, le helper PHP PayPal et les
exemples d'environnement suivis. Il ne produit que le nom du fichier, l'ID de
configuration, le chemin de clé et un état redacted. Toute valeur non vide ou
toute source PHP non vérifiable provoque une sortie non nulle. Il ne modifie
rien et ne fait aucun appel réseau.

Ne pas ouvrir puis sauvegarder le formulaire d'administration de cette
passerelle avec les overrides actifs. Dans Commerce PayPal `2.1.0`, les champs
de credentials utilisent les valeurs effectives comme valeurs par défaut et la
sauvegarde peut les recopier dans la configuration active. Un export ultérieur
pourrait alors les suivre à nouveau. Si une modification UI devient nécessaire,
retirer d'abord l'accès aux valeurs réelles, effectuer la modification sous
contrôle, puis exécuter le garde avant tout commit.

Le script `drupal/scripts/bootstrap-commerce.sh` ne gère et ne persiste plus les
credentials PayPal. L'absence de variables ne doit jamais déclencher la
désinstallation d'un module; le gateway exporté et l'override runtime assurent
le comportement fail-closed.

## Rotation obligatoire

Retirer une valeur de `HEAD` ne la révoque pas. Le propriétaire PayPal doit sans
attendre :

1. révoquer ou faire tourner le credential sandbox exposé depuis le tableau de
   bord PayPal ;
2. vérifier que l'ancien credential ne peut plus authentifier ;
3. placer le remplacement uniquement dans le stockage runtime approuvé du VPS ;
4. exposer les deux variables au processus PHP sans les afficher ;
5. installer l'include `settings.php`, reconstruire les caches selon la
   procédure opérationnelle approuvée, puis contrôler uniquement des états
   booléens/redacted ;
6. faire valider explicitement la réactivation du gateway et un test sandbox
   réel dans une opération séparée et autorisée.

Cette PR reste en brouillon tant que la rotation, l'injection VPS et la
validation runtime ne sont pas prouvées. Elle n'autorise aucun test de paiement
réel.

## Historique Git

L'ancien credential reste dans l'historique Git même lorsque l'arbre courant
est propre. Aucun `filter-repo`, BFG, rebase destructif ou force-push n'est
effectué dans cette PR.

Une réécriture future exige un plan séparé avec :

- approbation explicite du propriétaire ;
- sauvegarde vérifiée de toutes les références nécessaires ;
- rotation préalable du credential ;
- coordination avec chaque collaborateur et chaque PR ouverte ;
- force-push contrôlé des branches autorisées ;
- invalidation ou suppression des anciens clones, caches, artefacts et forks
  sous contrôle ;
- nouveau scan complet après réécriture.

La réécriture réduit la persistance de la fuite mais ne remplace jamais la
rotation.

## Gates restantes avant runtime

- rotation/révocation propriétaire confirmée ;
- stockage des nouvelles valeurs approuvé ;
- variables visibles par PHP-FPM et, si requis, PHP CLI ;
- include présent dans le `settings.php` non suivi ;
- validation redacted des overrides et du statut effectif ;
- confirmation propriétaire du changement fail-closed `activé` vers
  `désactivé` dans l'export ;
- contrôle qu'aucune sauvegarde UI ni export ne réintroduit une valeur ;
- test sandbox réel explicitement autorisé, hors de cette PR ;
- plan d'historique séparé approuvé, si une purge est décidée.
