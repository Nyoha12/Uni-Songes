# Fondation du hub « Ressources » — 2026

## Statut

Cette PR prépare un petit catalogue statique à `/ressources`. Elle ne publie
rien : le manifeste versionné est vide, `catalogue_approved` vaut `false`, le
module ne figure pas dans `core.extension.yml` et sa configuration
d'installation contient `enabled: false` avec une empreinte vide.

La conception de la PR brouillon #102 a été lue au commit
`19b6b5eeaecfa205b1de2ce86468a5dfff25c933` avec `git show`. Ses quatre
artefacts restent inchangés. Cette fondation ne modifie ni le thème partagé,
ni le script d'architecture de contenu, ni sitemap/robots, ni les ressources
runtime détenues exclusivement par la PR #103.

Aucune validation Drupal runtime n'est revendiquée dans cette phase.

## Pourquoi la première version a été réduite

La première implémentation comptait 82 fichiers et 7 011 lignes : 25 fichiers
de production, trois runners, 53 fixtures YAML et un document. Cette granularité
était disproportionnée pour un modèle A vide et fermé.

La version réduite contient exactement :

- 17 fichiers de production : 15 dans le module, le manifeste et le CLI ;
- quatre sources de test : trois runners et un provider data-driven ;
- un document ;
- soit 22 fichiers suivis, sans fixture YAML individuelle.

Les responsabilités conservées sont celles qui ont une frontière concrète :

- `ManifestRepository` : lecture locale, parsing YAML strict et mémoïsation ;
- `ManifestValidator` : schéma, texte, URL, ordre et empreinte, sans effet de bord ;
- `ManifestValidationResult` : résultat immuable, sans catalogue partiel ;
- `ResourcesAccess` : unique prédicat partagé par la route et le contrôleur ;
- `ResourcesController` : filtre GET, construction du rendu et métadonnées ;
- `resources-hub-activation.php` : plan pur et activation/désactivation gardée.

Consolidations réalisées :

- `ManifestResource` et `ManifestValidationError` deviennent des tableaux de
  forme fixe contenus dans le résultat immuable ;
- `ManifestLoaderInterface` et `ManifestLoader` deviennent un repository final ;
- `ResourcesExposureGate` et `ResourcesAccessCheck` deviennent un service ;
- `ActivationPlanner`, le wrapper shell et le helper PHP deviennent un CLI PHP
  unique dont le plan pur reste directement testable ;
- le contexte de cache par empreinte et la request policy globale sont retirés
  au profit des mécanismes Core détaillés plus bas ;
- 53 fichiers de fixtures deviennent un provider PHP nommé et génératif.

La complexité qui reste dans le validateur URL et le CLI est intentionnelle :
elle couvre respectivement les ambiguïtés de destinations externes et la
frontière de la seule écriture future autorisée.

## Contrat fermé par défaut

La route ne peut être ouverte que si toutes ces conditions sont vraies :

1. aucune surcharge runtime ne touche les réglages du module ;
2. `enabled` est exactement le booléen `true` ;
3. le manifeste entier est valide et `catalogue_approved` vaut `true` ;
4. il contient de 1 à 20 ressources publiées ;
5. son empreinte SHA-256 correspond à la configuration active.

Le même prédicat sert au contrôle de route et au contrôle défensif du
contrôleur. Le lien statique de menu vise cette route et hérite de son accès.
Installer le module, forcer seulement `enabled`, fournir un manifeste vide ou
changer le fichier sans mettre à jour son empreinte ne publie donc rien. Il
n'existe ni fausse ressource, ni page publique « bientôt disponible ».

## Manifeste version 1

Le fichier hors webroot `drupal/content/resources/resources.yml` possède
exactement trois clés :

```yaml
schema_version: 1
catalogue_approved: false
resources: []
```

Chaque ressource accepte :

| Champ | Contrainte |
| --- | --- |
| `id` | requis, ASCII minuscule/chiffres/tirets, 1–64, unique |
| `title` | requis, texte brut NFC, 1–160 caractères |
| `url` | requis si publié, HTTPS absolu, 2 048 octets maximum |
| `description` | requis, texte brut NFC, 1–500 caractères |
| `theme`, `type` | requis, texte brut NFC, 1–80 caractères |
| `language` | requis, syntaxe contrôlée `xx` ou `xx-YY` |
| `published` | booléen explicite requis |
| `audience` | facultatif, texte brut NFC, 1–160 caractères |
| `editorial_note` | facultatif, texte brut NFC, 1–500, jamais rendu |
| `last_verified` | facultatif, date grégorienne `YYYY-MM-DD` |
| `order` | facultatif, entier 0–9 999, complet et unique si utilisé |

Les clés inconnues, types implicites, chaînes vides ou rembourrées, caractères
de contrôle/format, HTML, UTF-8 invalide et texte non NFC sont refusés. Le
parser distingue réellement `resources: []` de `resources: {}` et refuse les
clés YAML dupliquées, ancres, alias et merge keys. Toute erreur produit un
résultat sans ressource rendable.

Sans `order`, le tri stable est thème, titre, ID en ordre de points de code
Unicode NFC. Avec `order`, les valeurs définissent l'ordre global revu. Le hash
canonique est indépendant de l'ordre accidentel du YAML.

## Sécurité des URL

Le validateur n'effectue aucune résolution DNS et aucune requête. Il exige :

- le schéma `https` ;
- un nom d'hôte pointé, IDNA UTS #46 valide et absent de la denylist locale ou
  réservée ;
- aucun userinfo ni credential ;
- aucun port explicite, ou le littéral exact `:443`.

Une URL HTTPS ordinaire sans `:443` est donc valide. `:444`, `:`, `:+443`,
`:443.0` et `:443x` sont refusés avant `parse_url()`.

Sont aussi refusés : URL relatives/protocol-relative, fragments seuls,
antislashs, contrôles/espaces directs ou encodés, pourcentages mal formés,
littéraux IPv4/IPv6 publics ou privés, localhost et suffixes réservés, hôtes
malformés, `utm_*`, `fbclid`, `gclid`, click IDs, campagne, referral,
affiliation et clés de query porteuses de secrets/session. Le contrôle couvre
les variantes camelCase, plusieurs niveaux de percent-encoding et les
affectations sensibles imbriquées.

Les URL suivies sont rejetées, jamais réécrites. La normalisation interne des
doublons couvre casse schéma/hôte, IDNA, port 443, chemin vide, segments
`.`/`..`, percent-encoding, UTF-8 et NFC ; le rendu conserve la destination
approuvée.

Sans DNS, le terme « public » ne peut pas être déduit de la résolution réelle :
la règle garantit un nom pointé syntaxiquement valide et non réservé selon une
denylist statique. La revue humaine de la destination reste obligatoire. Cette
limite évite SSRF, non-déterminisme et courses DNS.

`example.invalid` est accepté uniquement par le validateur explicitement créé
par les tests et reste refusé par le service de production.

## Rendu, thèmes et menu

Le bloc de titre Drupal fournit l'unique H1. Le template produit ensuite des H2
de thème et H3 de ressource dans une liste verticale sobre : titre,
description, type, langue, audience éventuelle, URL repliable et date de
vérification. Il ne contient ni carte géante, logo, favicon, image, rating,
masonry, infinite scroll, Archives, script tiers ou formulation de partenariat.

Les liens externes restent dans le même onglet, portent
`rel="external noreferrer"`, affichent `↗`, complètent leur nom accessible par
« site externe » et reçoivent `Referrer-Policy: no-referrer`.

À partir de deux thèmes, la page affiche « Tous les thèmes » et les seuls thèmes
présents dans les ressources publiées. La requête accepte zéro paramètre ou une
seule valeur scalaire `theme`. Toute autre clé, répétition, tableau, valeur vide
ou thème inconnu donne une 404. Un filtre valide ne contient aucun groupe vide
et reçoit meta plus en-tête `noindex, follow`. Le canonical global demeure
`/ressources`.

Le plugin `unisonges_resources.page` est un lien racine unique du menu principal,
sans enfant, poids 25 : après « Projets collectifs » et avant la future
« Boutique », puis « À propos » et « Contact ». Les thèmes restent dans la page.
Le drawer mobile clone le même arbre filtré par accès. Aucun lien n'est visible
dans l'état livré.

## Cache et publication contrôlée

Le prototype initial créait un contexte par empreinte et une request policy qui
pouvait désactiver les caches de tout le site. Cette architecture a été retirée :

- la route seule porte l'option Core `no_cache: true`, ce qui empêche IPC et DPC
  de stocker ses nouvelles réponses sans policy personnalisée globale ; après
  le rebuild/invalidation du déploiement, chaque requête atteint le garde ;
- l'accès et le rendu dépendent de `config:unisonges_resources.settings` et du
  tag stable `unisonges_resources:manifest` ;
- le rendu déclare langue d'interface, route et query complète ;
- le CLI invalide le tag stable après toute activation, mise à jour ou
  désactivation réussie.

Les métadonnées d'accès du lien remontent dans le rendu du menu Drupal ;
l'invalidation du tag stable rafraîchit donc le menu sans interférence globale.

Ce modèle exige un cutover contrôlé : un manifeste candidat est validé en CI ou
dans le checkout avant de remplacer un manifeste actif. Un fichier vide,
invalide ou non approuvé ne doit jamais être publié hors du helper. Le helper le
refuse avec zéro écriture. Une modification disque qui contourne ce processus
est hors contrat. Le déploiement doit reconstruire/invalider les caches avant
la bascule ; le garde échoue alors fermé à chaque nouvelle requête.

## Activation et rollback

Commande depuis `drupal/` :

```bash
php scripts/resources-hub-activation.php
php scripts/resources-hub-activation.php --activate --apply --plan-token=HASH
php scripts/resources-hub-activation.php --disable
php scripts/resources-hub-activation.php --disable --apply --plan-token=HASH
```

Le mode par défaut est `--activate --dry-run`. Le plan contient les comptes
exacts, les seules actions prévues et un token déterministe, jamais une URL.
Pour activer, le CLI valide tout le manifeste et le contrat statique avant le
bootstrap Drupal. Vide, invalide, non approuvé ou 21+ produit `BLOCKED` et zéro
écriture.

Un apply revalide sous verrou persistant, installe au maximum
`unisonges_resources` sans dépendances, vérifie que `/ressources` est la route
gagnante, vérifie le plugin racine, puis écrit uniquement `enabled` et
`manifest_fingerprint`. Aucun import, Basic Page, `menu_link_content`, taxonomie,
SQL brut ou appel externe n'existe.

La désactivation est asymétrique et disponible même si manifeste, route ou menu
sont endommagés : après validation du token et du snapshot des seuls réglages,
elle remet `enabled: false`, vide l'empreinte et laisse le module installé. Une
surcharge ou un état module/config inconnu reste refusé, sachant que le garde
runtime refuse déjà toute surcharge.

Sur activation, une erreur pendant l'écriture, l'invalidation ou le post-check
restaure exactement le snapshot des réglages antérieurs lorsqu'il n'a pas subi
de modification concurrente. Une installation déjà réalisée peut rester en
place, mais ses valeurs fermées empêchent toute exposition. La désactivation ne
rollbacke jamais vers un état ouvert.

## Limite Model A et données attendues

Le maximum est 20 ressources publiées. La 21e invalide le catalogue entier et
impose Model B. Model B est également requis sous cette limite dès que le besoin
implique plusieurs éditeurs, workflow structuré, mises à jour fréquentes,
réutilisation ou automatisation.

Le propriétaire doit encore fournir et approuver au moins une ligne réelle :
ID stable, titre, URL HTTPS sans tracking, description, thème, type, langue,
`published`, et le cas échéant audience, note interne, date et ordre. Il doit
ensuite approuver explicitement le catalogue complet.

## Validation statique et matrice différée

Les runners couvrent schéma, 0/1/4/20/21 entrées, publié/non publié, doublons,
ordre, Unicode, limites, YAML adversarial, sécurité URL et ports, tri/empreinte,
filtre thème, allowlist publique, mémoïsation, accès fermé, cache standard,
Twig/échappement, CSS mobile, helper et garde exacte des 22 fichiers.

Restent obligatoires sur un staging PHP 8.3 autorisé : route/menu désactivés,
1/4/20 entrées, filtres valides/invalides, anonyme/authentifié, invalidation de
cache, desktop/tablette/mobile/320 px, clavier, reflow 100/150/200 %, H1/main/
messages, absence de débordement et de requête média tierce, activation,
idempotence, désactivation, intégration sitemap #82 et zéro résidu de test.
`/ressources` ne rejoindra le sitemap qu'après activation ; les filtres resteront
noindex et aucune URL externe ne deviendra une entrée sitemap ou un canonical.
