# Architecture de contenu 2026

Ce document décrit la préparation de l'architecture publique Uni-Songes 2026 :
accueil, nouveaux hubs d'orientation, association, cours, stages, ateliers,
artistes partenaires, origine, prestations artistiques et arbre du menu
principal. La mise en place est portée par
`drupal/scripts/apply-content-architecture-2026.sh`.

## Carte des pages

| Page | Alias | Rôle |
| --- | --- | --- |
| Accueil | `/accueil` | Introduction courte et orientation vers Cours, Stages, Concerts, Artistes, Prestations et Association, avec CTA principal « Réserver un cours » vers `/reservation-cours`. |
| Cours & Stages | `/cours-et-stages` | Hub entre accompagnement individuel et pratique collective, avec cartes vers `/cours` et `/stages` et CTA principal vers `/reservation-cours`. |
| Cours | `/cours` | Hub avec trois cartes de discipline et un CTA principal « Réserver un cours » vers `/reservation-cours`. |
| Cours de didgeridoo | `/cours/didgeridoo` | Page détaillée avec cours d'essai à 10 EUR, cours à 25 EUR / heure ou 15 EUR / heure étudiant, CTA principal « Réserver un cours de didgeridoo » et CTA essai séparé vers le tunnel. |
| Cours de guimbarde | `/cours/guimbarde` | Page dédiée avec tarifs confirmés 25 EUR / heure et 15 EUR / heure étudiant, puis réservation guimbarde dans le tunnel. |
| Méditation / improvisation | `/cours/meditation-improvisation` | Page dédiée avec tarifs confirmés 25 EUR / heure et 15 EUR / heure étudiant, puis réservation méditation / improvisation dans le tunnel. |
| Stages | `/stages` | Hub des stages avec trois entrées : didgeridoo, musique improvisée / méditation, stages spéciaux, et routage vers les pages Stage publiées. |
| Stages didgeridoo | `/stages/didgeridoo` | Page des stages mensuels débutant et intermédiaire, tarif 20 EUR, réservation via les pages Stage publiées. |
| Musique improvisée / méditation | `/stages/musique-improvisee-meditation` | Page des stages musique improvisée / méditation, tarif 20 EUR, réservation via les pages Stage publiées. |
| Stages spéciaux | `/stages/speciaux` | Page des stages gong, guimbarde, éveil musical, etc., publiés via le système existant de pages Stage et billets. |
| Ateliers | `/ateliers` | Hub de pratique musicale collective avec cartes vers D’Jam, l'Orchestre des Rêveurs et les services et prestations artistiques. |
| À propos | `/a-propos` | Hub d'orientation vers l'association, les artistes et partenaires, l'origine et les services et activités artistiques. |
| L’Association | `/association` | Mission et activités musicales, pédagogiques et collectives, avec orientation vers les cours, stages, concerts, artistes, prestations et pages dédiées de D’Jam et de l'Orchestre des Rêveurs. |
| Les Artistes de l'asso | `/les-artistes-de-l-asso` | Page de présentation des artistes partenaires, avec sections à compléter. |
| Origine | `/origine` | Racines de la démarche dans le souffle, le didgeridoo, l'improvisation, l'écoute, la pratique collective et la transmission. |
| Services et prestations artistiques | `/services-prestations-artistiques` | Page des services artistiques, pédagogiques et sonores avec CTA contact. |

## Conventions éditoriales 2026

- L'accueil reste bref : une introduction, un CTA principal vers le tunnel de
  réservation des cours et six cartes d'orientation. Il ne répète pas les
  textes commerciaux détaillés des pages de destination.
- La page Association décrit la mission et les activités sans ajouter de faits
  juridiques, de noms d'équipe, de dates ni de statistiques. D’Jam et
  l'Orchestre des Rêveurs y sont situés comme projets de l'association, tandis
  que leurs pages dédiées restent les sources de détail.
- Les trois nouveaux hubs et la page Origine utilisent les mêmes classes
  éditoriales que les pages existantes. Ils ne modifient ni thème, ni template,
  ni CSS, ni JavaScript, ni configuration synchronisée. Le rendu dynamique des
  sous-menus appartient à une PR séparée ; cette architecture ne fournit que les
  données de pages et de menu.
- Les résumés de D’Jam et de l'Orchestre restent limités aux pratiques
  collectives, au didgeridoo, à l'écoute et à l'improvisation déjà documentés.
  Ils n'ajoutent ni horaire, ni règle d'adhésion, ni nom, ni tarif.
- La page Origine ne fournit aucune date fondatrice, aucun nom de fondateur,
  jalon juridique, chronologie, statistique ou partenaire non documenté. Elle
  reste dans le périmètre factuel de la pratique et de la transmission.
- Les pages publiques et alias existants sont conservés. Les seuls nouveaux
  alias sont `/cours-et-stages`, `/ateliers`, `/a-propos` et `/origine`.
- Les pages de cours particuliers ne structurent plus l'offre avec un cadrage
  générique débutant / intermédiaire / avancé. Elles décrivent plutôt ce que le
  cours permet de travailler et renvoient vers le tunnel de réservation.
- Le didgeridoo conserve un cours d'essai à 10 EUR avec un CTA séparé. Les CTA
  d'achat direct et les CTA contact concurrents sont retirés des pages Cours.
  Les produits Commerce existants restent hors du périmètre de ce script.
- Les tarifs sont formulés de façon homogène : `25 EUR / heure`, `15 EUR /
  heure étudiant`, `20 EUR par stage`.
- Les stages didgeridoo gardent deux repères collectifs mensuels, débutant et
  intermédiaire, sans développer longuement le fonctionnement mensuel.
- Les stages spéciaux ne créent pas d'offre générique : le format, le tarif et
  les billets restent portés par chaque page Stage publiée.
- Le corps des pages Drupal est la source de vérité pour les contenus Accueil,
  Association, Cours, Stages, les trois nouveaux hubs et Origine. Les templates
  de thème ne doivent pas injecter de sections éditoriales hardcodées qui
  réintroduisent l'ancienne structure.

## Parcours de réservation des cours

Le parcours public est : `/cours` → page de discipline ou CTA direct →
`/reservation-cours` → choisir la discipline → choisir le créneau → choisir le
paiement → confirmation.

| Choix | Destination |
| --- | --- |
| CTA général de `/cours` | `/reservation-cours` |
| Cours d'essai | `/reservation-cours?discipline=essai` |
| Didgeridoo | `/reservation-cours?discipline=didgeridoo` |
| Guimbarde | `/reservation-cours?discipline=guimbarde` |
| Méditation / improvisation | `/reservation-cours?discipline=meditation-improvisation` |

Cette évolution de contenu consomme le contrat du tunnel sans en modifier
l'implémentation ni la route.

## Menu principal

Les titres des pages et les libellés de navigation sont distincts lorsque la
concision du menu le demande. Les libellés sont stockés exactement en UTF-8 ;
les alias restent ASCII.

### Premier niveau

| Poids | Libellé | Destination |
| ---: | --- | --- |
| 0 | Cours & Stages | `/cours-et-stages` |
| 10 | Concerts & Événements | `/concerts` |
| 20 | Ateliers | `/ateliers` |
| 30 | À propos | `/a-propos` |
| 40 | Contact | `/contact` |

### Enfants de Cours & Stages

| Poids | Libellé | Destination |
| ---: | --- | --- |
| 0 | Cours particuliers | `/cours` |
| 10 | Stages | `/stages` |

### Enfants d'Ateliers

| Poids | Libellé | Destination |
| ---: | --- | --- |
| 0 | D’Jam | `/djam` |
| 10 | Orchestre | `/orchestre-des-reveurs` |

### Enfants d'À propos

| Poids | Libellé | Destination |
| ---: | --- | --- |
| 0 | L’Asso | `/association` |
| 10 | Partenaires | `/les-artistes-de-l-asso` |
| 20 | Origine | `/origine` |

Le lien principal existant vers `/services-prestations-artistiques` est
désactivé sur place. Il n'est ni supprimé, ni recréé, ni déplacé, et son
libellé, son poids ainsi que son parent sont conservés. La page reste accessible
depuis les cartes des hubs Ateliers et À propos.

Le préflight retrouve les liens par destination normalisée, jamais par
identifiant numérique de base de données. Il bloque les destinations multiples,
les libellés déjà pris par un autre lien, les alias dupliqués, les alias
canoniques qui convergent vers une même cible et tout lien existant requis qui
manquerait. Seuls les liens vers `/cours-et-stages`, `/ateliers`, `/a-propos` et
`/origine` peuvent être créés. Les huit autres liens actifs doivent déjà exister
et sont renommés, pondérés ou reparentés en place. Le lien Services doit être
retrouvé au premier niveau avant d'être désactivé ; une parenté inattendue
bloque l'opération.

Les enfants stockent comme parent le plugin ID retourné par
`MenuLinkContent::getPluginId()`, au format UUID
`menu_link_content:<uuid>`. Aucun identifiant numérique de contenu n'est utilisé
pour la hiérarchie. Le dry-run affiche pour chaque lien son libellé, son poids,
son parent et son état actif ou désactivé, avant et après lorsqu'ils diffèrent.
Le script ne supprime aucun lien et préserve tous les liens non concernés.

## Contenu réconcilié par le script

Le script réconcilie les seize nœuds Drupal de type `page` listés dans la carte
des pages, leurs alias et les liens de l'ordre canonique ci-dessus. Seules les
quatre nouvelles pages `/cours-et-stages`, `/ateliers`, `/a-propos` et `/origine`
peuvent être créées. Les douze autres pages gérées doivent déjà exister sous leur
alias canonique ; leur absence bloque le script. Les corps de page utilisent les
classes CSS contractuelles suivantes pour la PR CSS parallèle :

- `unisonges-page-intro`
- `unisonges-card-grid`
- `unisonges-offer-card`
- `unisonges-offer-card__title`
- `unisonges-offer-card__text`
- `unisonges-offer-card__meta`
- `unisonges-offer-card__cta`
- `unisonges-detail-section`
- `unisonges-price-note`

Le script ne crée, ne modifie ni ne supprime de produit Commerce, ne crée pas de
termes de taxonomie, ne lance pas `drush config:import`, ne modifie pas
`config/sync` et ne supprime aucun contenu.

L'alias est l'identifiant strict de résolution des seize pages : le script ne
reprend jamais un nœud sur la seule correspondance de son titre. Pour l'une des
quatre nouvelles pages, un titre déjà présent sans l'alias attendu bloque aussi
la création afin d'éviter un doublon. Le préflight bloque si un alias possède
plusieurs enregistrements, si deux alias gérés résolvent le même nœud ou si un
alias existant pointe vers autre chose qu'un nœud `page` valide.

Les pages existantes `/concerts`, `/djam`, `/orchestre-des-reveurs` et
`/contact`, qui ne reçoivent aucun nouveau corps dans ce script, sont contrôlées
en lecture seule par leur alias. Le dry-run affiche leur nœud cible et confirme
explicitement que leur corps reste inchangé.

En dry-run, chaque corps qui différerait est affiché intégralement dans un bloc
`BODY_CHANGE_EXACT`, avec le format de texte, le nombre d'octets et le SHA-256
des valeurs actuelle et prévue. Une création affiche le corps prévu et marque la
valeur actuelle comme absente. Cette sortie permet la revue exacte avant toute
application. Une différence de titre affiche les libellés actuel et prévu ; une
différence de publication affiche également les deux états. Un résumé Drupal
existant est conservé lors du remplacement du corps. Le préflight des pages et
du menu se termine avant toute écriture. En mode application, une transaction
englobe pages, alias et menu afin qu'une erreur tardive annule les écritures de
la passe. Le mode dry-run n'écrit ni contenu, ni alias, ni menu.

## Décisions de contenu confirmées

- Le hub `/cours-et-stages` reprend seulement les tarifs confirmés : essai
  didgeridoo 10 EUR, cours particulier 25 EUR / heure ou 15 EUR / heure
  étudiant, et 20 EUR pour les stages réguliers concernés. Il ne propose ni
  pack, ni offre avancée séparée, ni parcours fondé d'abord sur des crédits.
- Le hub `/ateliers` résume D’Jam comme pratique conviviale autour du didgeridoo
  ouverte à d'autres instruments, et l'Orchestre comme création collective
  autour du didgeridoo, de l'écoute et de l'improvisation. Aucun horaire, nom,
  prix ou fonctionnement d'adhésion n'est ajouté.
- Le hub `/a-propos` oriente uniquement vers les quatre sources canoniques :
  association, artistes et partenaires, origine, services et activités
  artistiques.
- La page `/origine` reste limitée aux racines dans le souffle, le didgeridoo,
  l'improvisation, l'écoute et la pratique collective, à la transmission
  artistique et pédagogique, puis aux activités actuelles déjà documentées.
- Cours d'essai : 10 EUR, réservation via
  `/reservation-cours?discipline=essai`.
- Cours de didgeridoo : 25 EUR / heure, 15 EUR / heure étudiant, réservation
  via `/reservation-cours?discipline=didgeridoo`.
- Cours de guimbarde : 25 EUR / heure, 15 EUR / heure étudiant, réservation via
  `/reservation-cours?discipline=guimbarde`.
- Méditation / improvisation : 25 EUR / heure, 15 EUR / heure étudiant,
  réservation via `/reservation-cours?discipline=meditation-improvisation`.
- Les pages produit existantes ne sont pas supprimées, mais ne sont plus les
  CTA d'achat des pages publiques Cours.
- Stages didgeridoo : 20 EUR, avec réservation sur les pages Stage publiées.
- Stages musique improvisée / méditation : 20 EUR, avec réservation sur les
  pages Stage publiées.
- Stages spéciaux : gong, guimbarde, éveil musical, etc., via le système
  existant de publication de stages et de billets.

## Raffinement éditorial et visuel

Le contenu des pages `/cours`, `/cours/didgeridoo`, `/cours/guimbarde`,
`/cours/meditation-improvisation`, `/stages`, `/stages/didgeridoo`,
`/stages/musique-improvisee-meditation` et `/stages/speciaux` a été resserré
pour :

- expliquer rapidement ce que propose chaque page ;
- afficher les prix confirmés avec les mêmes conventions ;
- éviter les textes trop génériques ou trop longs ;
- orienter les pages Cours vers le tunnel de réservation ;
- conserver le système existant de publication Stage et de billetterie.

Le CSS associé reste limité aux classes `unisonges-page-intro`,
`unisonges-card-grid`, `unisonges-offer-card`, `unisonges-detail-section` et
`unisonges-price-note`. Les ajustements visuels portent sur la lisibilité des
textes, la hiérarchie des titres, la mise en avant des prix, l'alignement des
CTA et la cohérence des panneaux de contenu.

## Checklist visuelle manuelle

- Vérifier `/accueil` : l'introduction reste courte, les six cartes mènent aux
  bonnes pages et le CTA principal mène à `/reservation-cours`.
- Vérifier `/cours-et-stages` : les cartes mènent à `/cours` et `/stages`, le
  CTA principal mène à `/reservation-cours` et seuls les tarifs confirmés sont
  affichés.
- Vérifier `/ateliers` : les cartes mènent à `/djam`,
  `/orchestre-des-reveurs` et `/services-prestations-artistiques`, sans horaire,
  règle de participation, nom ou tarif ajouté.
- Vérifier `/a-propos` : les quatre cartes mènent à `/association`,
  `/les-artistes-de-l-asso`, `/origine` et
  `/services-prestations-artistiques`.
- Vérifier `/origine` : le texte reste limité aux racines artistiques et
  pédagogiques validées, sans chronologie ni identité inventée.
- Vérifier `/association` : mission et activités restent concises, les cinq
  destinations demandées sont présentes et D’Jam comme l'Orchestre renvoient à
  leurs pages dédiées.
- Vérifier `/cours` : les trois cartes restent visibles, le CTA principal
  « Réserver un cours » mène à `/reservation-cours` et les cartes mènent aux
  pages de discipline.
- Vérifier `/cours/didgeridoo` : les tarifs 25 EUR / 15 EUR étudiant restent
  visibles, le CTA principal mène au deep-link didgeridoo et le CTA essai séparé
  mène au deep-link essai.
- Vérifier `/cours/guimbarde` et `/cours/meditation-improvisation` : les tarifs
  25 EUR / 15 EUR étudiant restent visibles et chaque CTA mène au bon deep-link.
- Vérifier qu'aucun CTA d'achat produit direct ne concurrence le tunnel sur les
  quatre pages Cours.
- Vérifier `/stages` : les trois familles de stages sont lisibles et la zone de
  publication automatique des contenus Stage reste présente.
- Vérifier `/stages/didgeridoo` : les repères débutant et intermédiaire
  mensuels sont mentionnés sans surcharge de texte.
- Vérifier `/stages/musique-improvisee-meditation` et `/stages/speciaux` : la
  réservation passe par les dates Stage publiées ou le contact, sans produit
  Commerce générique.
- Vérifier le menu : cinq liens au premier niveau, deux enfants sous Cours &
  Stages, deux sous Ateliers et trois sous À propos, avec les libellés UTF-8,
  poids, parents et états documentés. Le lien Services doit être conservé mais
  désactivé, et aucun doublon ne doit apparaître.
- Vérifier que `/concerts`, `/djam`, `/orchestre-des-reveurs` et `/contact`
  conservent leurs alias et leurs corps existants.
- Tester desktop, tablette et mobile : pas de chevauchement de texte, CTA
  tappables, titres et prix lisibles sur le fond Uni-Songes.

## Contenu manuel restant

- Publier les dates réelles des stages comme contenus `Stage`.
- Relier les prochaines dates depuis les pages de catégories si l'équipe veut
  des liens explicites en plus de la liste automatique.
- Compléter les biographies, photos, liens et prestations des artistes.
- Finaliser les textes commerciaux et les contraintes techniques des prestations.

## Préservation des hubs existants

La page `/stages` reste le hub de stages. Le script met à jour le corps du nœud
de page, mais ne modifie pas le bloc Views existant qui publie
automatiquement les contenus `Stage` sur `/stages`.

La page `/concerts` et son comportement existant ne sont pas touchés.

## Exécution locale

Dry-run local, sans écriture :

```bash
cd ~/Uni-Songes/repo/drupal
./scripts/apply-content-architecture-2026.sh --dry-run
```

Application locale :

```bash
cd ~/Uni-Songes/repo/drupal
./scripts/apply-content-architecture-2026.sh --apply
```

### Dry-run Codespaces historique du 30 août 2026 (ancien périmètre)

Ce résultat concerne le commit historique `0357d22`, avant l'ajout des quatre
nouvelles pages, de l'arbre de sous-menus et des gardes de création strictes.
Il est conservé comme trace d'exécution de l'ancien périmètre ; il ne valide pas
la version courante du script. Deux invocations préliminaires s'étaient arrêtées
avant l'inspection du contenu Drupal, sans écriture : l'exécution directe depuis
l'hôte utilisait PHP 8.2.33 alors que le projet requiert PHP 8.3, puis
l'exécution DDEV sans dérogation de chemin avait rencontré la garde `/var/www`.
Le dry-run historique avait ensuite été exécuté dans le projet DDEV local avec :

```bash
ddev exec ./scripts/apply-content-architecture-2026.sh --dry-run --allow-vps
```

Dans cette commande locale, `--allow-vps` autorise uniquement le chemin interne
DDEV `/var/www/html` ; aucun VPS n'a été contacté. Drupal 11.3.3 a démarré et le
dry-run s'est terminé avec le statut 0, sans `--apply`.

La base Codespaces utilisée ne contenait aucune des cibles de cet ancien
périmètre. La sortie complète proposait exactement :

- 12 `WOULD_CREATE page`, pour `/accueil`, `/cours`, les trois pages Cours, le
  hub `/stages`, les trois pages Stages, `/association`,
  `/les-artistes-de-l-asso` et `/services-prestations-artistiques` ;
- 12 blocs `BODY_CHANGE_EXACT` avec `node=NEW`, `CURRENT_FORMAT <absent>` et
  `CURRENT_BODY <absent>` ;
- 12 `WOULD_CREATE alias` ;
- 9 `WOULD_CREATE main menu link` ;
- aucun `WOULD_UPDATE page`, aucun `OK page /...`, aucun `OK alias`, aucun lien
  de menu `OK` ou `WOULD_UPDATE`, et aucun `FAIL`.

Les lignes `OK inspected page target` de cet ancien préflight signifiaient
seulement que la résolution n'avait pas levé d'exception ; elles ne prouvaient
pas qu'un nœud existait. La sortie se terminait par `Dry-run completed. No
content, menu links, aliases, config, or Commerce data was changed.` Aucun
marqueur d'écriture réel `CREATED`, `UPDATED` ou `DELETED` n'était présent.

Ce snapshot local vide ne reproduisait pas le contenu actif de production. Il
ne permet de confirmer ni la conservation des pages existantes, ni les deltas
de la version élargie à seize pages et douze liens actifs. Aucun nouveau dry-run
DDEV n'est revendiqué pour cette version. La PR reste en brouillon jusqu'à un
dry-run VPS représentatif, intégral et revu.

## Exécution VPS

Le script refuse les chemins `/var/www` sauf si `--allow-vps` est passé
explicitement.

### Validation active ultérieure en lecture seule

Ce contrôle doit partir d'un checkout VPS déjà revu et contenant exactement le
commit approuvé de la PR. Il ne doit pas servir à basculer ni mettre à jour le
checkout déployé. Si le SHA attendu n'est pas déjà présent, préparer séparément
un checkout autorisé ou différer le contrôle.

```bash
cd /var/www/<site>
git status --short --branch --untracked-files=no
git diff --quiet
git diff --cached --quiet
git rev-parse HEAD

cd drupal
bash -n scripts/apply-content-architecture-2026.sh
./scripts/apply-content-architecture-2026.sh --dry-run --allow-vps
```

La revue doit vérifier la sortie complète selon les critères suivants :

- les douze pages gérées existantes et les quatre pages de référence sont
  retrouvées par leur alias sans changement d'identifiant ;
- `/accueil` et `/association` sont les seules pages existantes qui peuvent
  proposer la mise à jour éditoriale déjà préparée par cette PR ; les dix autres
  pages gérées existantes doivent être `OK` ;
- les quatre nouvelles pages et leurs alias sont les seules créations permises,
  avec `WOULD_CREATE` attendu si elles n'existent pas encore ; si l'une existe
  déjà, son nœud, son alias et tout éventuel bloc `BODY_CHANGE_EXACT` doivent
  faire l'objet d'une revue explicite ;
- les huit liens actifs existants sont retrouvés par destination et seuls les
  renommages, poids et reparentages de l'arbre canonique peuvent apparaître ;
- seuls les quatre liens de menu vers `/cours-et-stages`, `/ateliers`,
  `/a-propos` et `/origine` peuvent afficher `WOULD_CREATE` ;
- chacun des douze liens actifs affiche le libellé, le poids, le parent et
  `enabled=TRUE` prévus, avec cinq liens au premier niveau et sept enfants ;
- le lien existant `/services-prestations-artistiques` affiche exactement
  `WOULD_DISABLE`, ou `OK disabled` s'il est déjà inactif, tout en restant
  conservé au premier niveau et non supprimé ;
- aucun alias ou lien de destination ambigu, aucune modification de contenu
  étrangère à la liste et aucun `FAIL`, `CREATED`, `UPDATED`, `DISABLED` ou
  `DELETED` n'apparaît.

Ne lancer ni `--apply`, ni import de configuration, ni reconstruction de cache.
Toute divergence maintient la PR en brouillon et exige une revue avant une
opération d'écriture distinctement autorisée.

Dry-run VPS :

```bash
cd /var/www/<site>/drupal
./scripts/apply-content-architecture-2026.sh --dry-run --allow-vps
```

Application VPS :

```bash
cd /var/www/<site>/drupal
./scripts/apply-content-architecture-2026.sh --apply --allow-vps
```

Avant toute application VPS, vérifier le dry-run, le chemin courant, la branche
déployée et la sauvegarde de base de données. Ne pas lancer d'import de
configuration pour cette opération.
