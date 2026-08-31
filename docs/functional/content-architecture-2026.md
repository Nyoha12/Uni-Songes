# Architecture de contenu 2026

Ce document décrit la préparation de l'architecture publique Uni-Songes 2026 :
accueil, hubs d'orientation, association, cours, stages, projets collectifs,
Forum, Blog, artistes partenaires, origine, prestations artistiques et arbre du
menu principal. La mise en place est portée par
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
| Projets collectifs | `/ateliers` | Hub des projets collectifs avec cartes vers D’Jam, l'Orchestre des Rêveurs, le Forum et les services et prestations artistiques. L'alias historique est conservé pour compatibilité. |
| Forum | `/forum` | Espace éditorial d'échange d'idées autour de la musique, des pratiques collectives et des projets de l'association, avec un repère éditorial identifié ; après activation ciblée, la PR #80 ajoute ses blocs fonctionnels séparément dans la région Drupal de contenu. |
| À propos | `/a-propos` | Hub d'orientation vers l'association, les artistes et partenaires, l'origine, le Blog et les services et activités artistiques. |
| Blog | `/blog` | Point d'entrée pour les actualités de l'association, les articles artistiques et pédagogiques, les réflexions et les ressources ; après activation ciblée, la PR #80 rend la liste dynamique dans un bloc séparé de la région Drupal de contenu. |
| L’Association | `/association` | Mission et activités musicales, pédagogiques et collectives, avec orientation vers les cours, stages, concerts, artistes, prestations et pages dédiées de D’Jam et de l'Orchestre des Rêveurs. |
| Artistes et partenaires | `/les-artistes-de-l-asso` | Repères sur l'environnement artistique et collaboratif d'Uni-Songes, ses pratiques et les formes possibles de projets, sans annuaire biographique ni liste de partenaires. |
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
- Les hubs, la page Origine, le Forum et le Blog utilisent les mêmes classes
  éditoriales que les pages existantes. Ils ne modifient ni thème, ni template,
  ni CSS, ni JavaScript, ni configuration synchronisée. La PR #78 fournit les
  données de pages et de menu ; les blocs Forum/Blog de la PR #80 apparaissent
  seulement après activation de son installeur ciblé.
- Les résumés de D’Jam et de l'Orchestre restent limités aux pratiques
  collectives, au didgeridoo, à l'écoute et à l'improvisation déjà documentés.
  Ils n'ajoutent ni horaire, ni règle d'adhésion, ni nom, ni tarif.
- La page Origine ne fournit aucune date fondatrice, aucun nom de fondateur,
  jalon juridique, chronologie, statistique ou partenaire non documenté. Elle
  reste dans le périmètre factuel de la pratique et de la transmission.
- La page Artistes et partenaires ne constitue ni un annuaire biographique ni
  une liste de collaborations en cours. Elle présente seulement les pratiques
  confirmées, les formes possibles de projets et quatre destinations
  canoniques, sans nom, affiliation, qualification ni parcours individuel.
- Toutes les pages publiques et tous les alias existants sont conservés.
  `/ateliers` reste l'alias stable du hub renommé « Projets collectifs ». Les
  seules pages et alias que le script peut créer sont `/cours-et-stages`,
  `/ateliers`, `/forum`, `/a-propos`, `/blog` et `/origine`.
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
  Association, Cours, Stages, les hubs, Origine, Forum et Blog. Les templates de
  thème ne doivent pas injecter de sections éditoriales hardcodées qui
  réintroduisent l'ancienne structure. `#forum-mvp` et `#blog-articles` sont des
  repères éditoriaux documentés de ces Basic pages. Après activation ciblée, les
  blocs de la PR #80 coexistent comme éléments frères dans la même région Drupal
  de contenu ; ces repères ne prétendent pas que cette configuration est active.

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
| 20 | Projets collectifs | `/ateliers` |
| 30 | À propos | `/a-propos` |
| 40 | Contact | `/contact` |

### Enfants de Cours & Stages

| Poids | Libellé | Destination |
| ---: | --- | --- |
| 0 | Cours particuliers | `/cours` |
| 10 | Stages | `/stages` |

### Enfants de Projets collectifs

| Poids | Libellé | Destination |
| ---: | --- | --- |
| 0 | D’Jam | `/djam` |
| 10 | Orchestre | `/orchestre-des-reveurs` |
| 20 | Forum | `/forum` |

### Enfants d'À propos

| Poids | Libellé | Destination |
| ---: | --- | --- |
| 0 | L’Asso | `/association` |
| 10 | Partenaires | `/les-artistes-de-l-asso` |
| 20 | Origine | `/origine` |
| 30 | Blog | `/blog` |

Le lien principal existant vers `/services-prestations-artistiques` est
désactivé sur place. Il n'est ni supprimé, ni recréé, ni déplacé, et son
libellé, son poids ainsi que son parent sont conservés. La page reste accessible
depuis les cartes des hubs Projets collectifs et À propos.

Le préflight retrouve les liens par destination normalisée, jamais par
identifiant numérique de base de données. Il bloque les destinations multiples,
les libellés déjà pris par un autre lien, les alias dupliqués, les alias
canoniques qui convergent vers une même cible et tout lien existant requis qui
manquerait. Seuls les liens vers `/cours-et-stages`, `/ateliers`, `/a-propos`,
`/forum`, `/origine` et `/blog` peuvent être créés. Les huit autres liens actifs
doivent déjà exister et sont renommés, pondérés ou reparentés en place. Le lien
Services doit être retrouvé au premier niveau avant d'être désactivé ; une
parenté inattendue bloque l'opération.

Les enfants stockent comme parent le plugin ID retourné par
`MenuLinkContent::getPluginId()`, au format UUID
`menu_link_content:<uuid>`. Aucun identifiant numérique de contenu n'est utilisé
pour la hiérarchie. Le dry-run affiche pour chaque lien son libellé, son poids,
son parent et son état actif ou désactivé, avant et après lorsqu'ils diffèrent.
Le script ne supprime aucun lien et préserve tous les liens non concernés.

## Contenu réconcilié par le script

Le script réconcilie les dix-huit nœuds Drupal de type `page` listés dans la
carte des pages, leurs alias et les liens de l'ordre canonique ci-dessus. Seules
les six pages `/cours-et-stages`, `/ateliers`, `/forum`, `/a-propos`, `/blog` et
`/origine` peuvent être créées. Les douze autres pages gérées doivent déjà
exister sous leur alias canonique ; leur absence bloque le script. Les corps de
page utilisent les classes CSS contractuelles suivantes pour la PR CSS
parallèle :

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

L'alias est l'identifiant strict de résolution des dix-huit pages : le script ne
reprend jamais un nœud sur la seule correspondance de son titre. Pour l'une des
six pages créables, un titre déjà présent sans l'alias attendu bloque aussi
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

## Préflight fermé et application atomique

Le défaut transactionnel reproduit lors de l'intégration locale provenait de la
portée d'une variable PHP. Drush charge le fichier généré avec `include` depuis
la méthode `PhpCommands::script()`. La variable de niveau supérieur `$failed`
était donc locale à cette méthode, tandis que la fonction de contrôle déclarait
`global $failed` et modifiait `$GLOBALS['failed']`, une autre variable. Les
gardes continuaient à lire `FALSE` : sur une base vide, le script avait pu
enregistrer le premier lien « Cours & Stages », rencontrer ensuite une cible
manquante, valider la transaction et afficher un succès erroné.

La commande est désormais divisée en deux phases explicites :

1. **Phase A — découverte et validation en lecture seule.** Elle résout tous
   les nœuds existants et de référence par alias, contrôle les alias et les
   destinations de menu ambigus, valide les entités prévues avec les contraintes
   Drupal, vérifie la cohérence entre entités de menu et définitions de plugins,
   réserve les UUID des nouvelles entités et calcule tous les parents au format
   `menu_link_content:<uuid>`. Elle produit ensuite un plan composé uniquement
   de valeurs scalaires, scellé par une empreinte SHA-256. Chaque état attendu,
   état prévu, dépendance et opération y figure avant toute écriture.
2. **Phase B — application atomique.** Elle refuse une transaction englobante,
   ouvre une transaction dédiée seulement après le succès complet de la phase A,
   puis revalide le plan entier, les UUID, les libellés et les plugins parents
   avec `writes=0`. Elle exécute exclusivement les opérations du plan, vérifie
   pages, alias, références, liens et arbre de menu dans la transaction, puis
   appelle explicitement `commitOrRelease()`. Les messages `CREATED`, `UPDATED`
   et `DISABLED` restent en mémoire jusqu'au commit.

Toute exception avant la finalisation demande un rollback et ne peut produire
un message de succès. Un rollback effectivement terminé affiche
`ROLLBACK_CONFIRMED`; un échec de finalisation dont l'état ne peut être prouvé
affiche au contraire `ROLLBACK_UNCONFIRMED` et demande une inspection. Les
échecs de phase A affichent `BLOCKED`, `FAIL`,
`transaction_started=FALSE; writes=0` et terminent le processus avec le statut
1. Le script utilise `exit(1)`, et non la valeur de retour d'un fichier inclus,
afin que Drush et le shell propagent bien l'échec. Aucun nettoyage destructif
ne tente de compenser une écriture partielle : la phase A l'empêche, et la
transaction constitue la seconde protection. Les remises à zéro de cache du
processus restent limitées au cache mémoire d'entités.

## Décisions de contenu confirmées

- Le hub `/cours-et-stages` reprend seulement les tarifs confirmés : essai
  didgeridoo 10 EUR, cours particulier 25 EUR / heure ou 15 EUR / heure
  étudiant, et 20 EUR pour les stages réguliers concernés. Aucune modalité non
  confirmée n'est ajoutée.
- Le hub « Projets collectifs » conserve l'alias `/ateliers`. Il résume D’Jam
  comme pratique conviviale autour du didgeridoo ouverte à d'autres instruments
  et l'Orchestre comme création collective autour du didgeridoo, de l'écoute et
  de l'improvisation. Il oriente aussi vers le Forum et les services, sans
  ajouter d'horaire, de nom, de prix ou de fonctionnement d'adhésion.
- Le hub `/a-propos` oriente vers les cinq sources canoniques : association,
  artistes et partenaires, origine, Blog, services et activités artistiques.
- La page `/forum` présente le cadre éditorial des échanges et leur modération.
  `#forum-mvp` reste son repère éditorial ; après activation ciblée, le bloc
  fonctionnel de la PR #80 apparaît séparément dans la même région Drupal de
  contenu. Aucun compte, formulaire de publication ou fil de discussion n'est
  déclaré disponible par le contenu de la Basic page.
- La page `/blog` annonce seulement les catégories de contenus prévues. Le
  repère `#blog-articles` reste identifié ; après activation ciblée,
  la PR #80 rend la liste dynamique dans un bloc frère séparé. Aucun article
  n'est inventé ni présenté comme déjà publié par le contenu de la Basic page.
- La page `/origine` reste limitée aux racines dans le souffle, le didgeridoo,
  l'improvisation, l'écoute et la pratique collective, à la transmission
  artistique et pédagogique, puis aux activités actuelles déjà documentées.
- La page `/les-artistes-de-l-asso`, titrée « Artistes et partenaires »,
  suit quatre sections : une introduction, « Pratiques et approches »,
  « Projets et collaborations » et « Découvrir et prendre contact ». Elle
  présente le didgeridoo, la guimbarde, l'écoute, l'improvisation musicale, la
  pratique collective et la transmission artistique et pédagogique. Les
  concerts, projets collectifs, ateliers ou interventions pédagogiques et
  prestations artistiques et sonores y sont décrits uniquement comme des
  formes possibles d'activité. Ses quatre liens mènent à `/concerts`,
  `/ateliers`, `/services-prestations-artistiques` et `/contact`.
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
- Vérifier `/ateliers` : le titre visible est « Projets collectifs » et les
  quatre cartes mènent à `/djam`, `/orchestre-des-reveurs`, `/forum` et
  `/services-prestations-artistiques`, sans horaire, règle de participation,
  nom ou tarif ajouté.
- Vérifier `/forum` : l'introduction couvre les échanges, les propositions des
  membres et la modération ; `#forum-mvp` est identifiable et le CTA de repli
  mène à `/contact`, sans annoncer de fonctionnalité non installée.
- Vérifier `/a-propos` : les cinq cartes mènent à `/association`,
  `/les-artistes-de-l-asso`, `/origine`, `/blog` et
  `/services-prestations-artistiques`.
- Vérifier `/blog` : les catégories éditoriales sont présentes,
  `#blog-articles` annonce une future liste dynamique et aucun article existant
  n'est inventé.
- Vérifier `/origine` : le texte reste limité aux racines artistiques et
  pédagogiques validées, sans chronologie ni identité inventée.
- Vérifier `/association` : mission et activités restent concises, les cinq
  destinations demandées sont présentes et D’Jam comme l'Orchestre renvoient à
  leurs pages dédiées.
- Vérifier `/les-artistes-de-l-asso` : le titre Drupal est « Artistes et
  partenaires » ; les intertitres « Pratiques et approches », « Projets et
  collaborations » et « Découvrir et prendre contact » sont présents ; les
  quatre liens mènent à `/concerts`, `/ateliers`,
  `/services-prestations-artistiques` et `/contact`. Aucun texte d'attente,
  annuaire, biographie, identité, affiliation, qualification ou partenariat en
  cours n'est affirmé.
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
  Stages, trois sous Projets collectifs et quatre sous À propos, avec les
  libellés UTF-8, poids, parents UUID et états documentés. Le lien Services doit
  être conservé mais désactivé, et aucun doublon ne doit apparaître.
- Vérifier que `/concerts`, `/djam`, `/orchestre-des-reveurs` et `/contact`
  conservent leurs alias et leurs corps existants.
- Tester desktop, tablette et mobile : pas de chevauchement de texte, CTA
  accessibles, titres et prix lisibles sur le fond Uni-Songes ; valider en
  particulier la typographie et le comportement du menu avec ses neuf enfants.

### État rebasé et validation locale de la PR #87 — 2 septembre 2026

La fusion de la PR #95 a été vérifiée sur GitHub puis par ascendance Git : son
commit de fusion `2ffa2538204f0705dadf6faebceef8c77ebcbfc2` est la base
`release/prod` de la PR #87. Le rebase n'a produit aucun conflit. Le diff reste
strictement limité aux deux fichiers suivants :

- `drupal/scripts/apply-content-architecture-2026.sh` ;
- `docs/functional/content-architecture-2026.md`.

Dans le script, seuls le titre et le corps de la définition résolue par
`/les-artistes-de-l-asso` diffèrent de cette base. La définition complète de
`/accueil`, son titre, son corps, le format de texte commun et la conservation
de son résumé restent identiques. Les définitions de `/blog`, `/forum`, de
toutes les autres pages, du menu, des listes de création et des gardes de
transaction restent elles aussi byte-for-byte identiques.

Les PR #99 et #100 sont incluses dans cette base. La PR #87 reste compatible
avec leur présentation des comptes, leurs titres sémantiques et leur cycle de
vie des messages : elle ne modifie aucun fichier de thème, d'authentification
ou de compte, n'ajoute aucun message et ne crée aucun second chemin de message
système. Le corps Artistes et partenaires ne contient aucun H1 : le bloc de
titre de page reste son unique source H1, puis les trois sections de contenu
utilisent des H2 logiques.

La PR #103 reste non fusionnée et son périmètre de dix-sept fichiers demeure
indépendant : aucun n'est touché. Comme la définition `/accueil` et toute
l'architecture de menu restent strictement identiques à `release/prod`, la PR
#87 n'invalide pas le pré-état exact que le futur installeur de la PR #103
reconnaît pour cette page. L'ordre compatible est de livrer la PR #87 avant la
première installation de la PR #103. En effet, le contrat de rollback de cet
installeur retient aussi l'empreinte du script d'architecture complet : si la
PR #103 avait déjà été appliquée, la modification ultérieure du seul bloc
Artistes ferait échouer volontairement la revalidation de ce contrat, bien que
le corps `/accueil` soit inchangé, et imposerait une nouvelle revue de son état
retenu.

Les affirmations suivantes sont délibérément absentes du contenu Artistes et
partenaires : nom d'artiste ou de partenaire, biographie, portrait, annuaire,
appartenance ou affiliation, instrument attribué à une personne, qualification,
prix ou récompense, expérience professionnelle, date, lieu, collaboration
nommée, contrat ou accord, tarif, horaire, disponibilité, partenariat actuel et
URL externe. Les formats d'activité restent généraux et modalisés ; aucune
relation avec une personne ou un partenaire non documenté n'est sous-entendue.

### Validation locale représentative — Artistes et partenaires

La PR #87 a disposé d'une fenêtre runtime exclusive pour cette validation.
Aucun VPS n'a été utilisé, aucune donnée de production n'a été importée, aucun
appel externe applicatif n'a été déclenché par la fixture ou le site testé et
aucune entité Commerce n'a été créée.
Le script testé avait l'empreinte SHA-256
`9c92531dbde7141ac80107d0202e419cfd2695f027c44369207a4fe164980cdb`.
Avant toute écriture, le snapshot nommé
`pr87-artists-pre-runtime-20260902T132158Z` a été créé et son archive contrôlée
avec l'empreinte
`7fffb7decc95d13a6bb048ac30c8c68ad4702ba7feaef40c9b053f8cbdded01f`.

La base locale standard ne contenant aucun nœud, une fixture jetable a été
créée uniquement avec les API d'entités Drupal et des marqueurs uniques. Elle a
d'abord reproduit l'état historique requis, puis le script exact de la base
`2ffa2538204f0705dadf6faebceef8c77ebcbfc2` l'a fait converger vers
l'architecture représentative : 22 pages, 38 alias au total, 15 liens de menu
principal dont 14 actifs et le lien Services conservé désactivé. Toutes les
pages sans rapport avec Artistes correspondaient byte-for-byte à la base ; le
corps `/accueil` de 2 385 octets avait l'empreinte
`e0ceb05c22f5bdbcc05bb949bb37e2258965261376271cdc1ca60b5898ce2817`,
le format `full_html` et un résumé nul, soit le pré-état attendu par la PR #103.

| Contrôle local exécuté | Résultat |
| --- | --- |
| Premier dry-run | Sortie 0, phase A complète et plan immuable `6bc10f75e7458d6e68168cbfd53317ef617624a261b1e80ed558546d70d98953` : exactement une opération `page_update` pour `/les-artistes-de-l-asso`, zéro création de page ou d'alias, zéro mutation de menu, de configuration ou de Commerce. Les corps actuel et prévu ont été capturés intégralement. |
| Première application | Sortie 0 et exactement une mise à jour du titre et du corps du nœud cible dans la transaction. Une seule révision a été ajoutée ; bundle, publication, propriétaire, langue, alias, format et résumé ont été conservés. Aucun autre nœud ni aucune autre révision existante n'a changé. |
| Alias et menu | Les 38 alias et les 15 liens sont restés identiques. « Partenaires » reste actif, de poids 10, sous le parent `/a-propos`, vers `/les-artistes-de-l-asso` ; toutes les autres définitions sont inchangées. |
| Idempotence | Le second dry-run et la seconde application ont chacun terminé avec la sortie 0, le plan vide `be964804613d200c23fca76d918fda400c4880dc78ba496110a5236e660f4f7f`, zéro mutation et aucune révision supplémentaire. |
| Rollback tardif | Un trigger temporaire, limité à l'insertion de la révision Artistes, a fait échouer la phase B avec la sortie 1 et `ROLLBACK_CONFIRMED`. L'état complet, les configurations et l'empreinte normalisée durable `925e43307361cffd98d60d2a7ac0ec401a254cae60316b411348c0e2c0fc17a5` sont restés identiques ; aucun message de mutation ni aucune révision partielle n'a été validé. Le trigger a été supprimé. |
| Entité résultante | Le titre est exactement « Artistes et partenaires », l'alias reste `/les-artistes-de-l-asso`, le corps reste en `full_html`, ne contient aucun H1 et comprend l'introduction puis les trois H2 documentés et les quatre liens canoniques exacts. |
| Navigateur | Playwright 1.62.1 et Chromium 151 ont validé les profils bureau 100 %, tablette, mobile 390, mobile 320, reflow 150 % et reflow 200 %, puis un membre local et l'administrateur local. Les quatre liens répondent en 200, le H1 est unique, les trois H2 sont ordonnés, le menu mobile, le clavier, les couleurs forcées, le mouvement réduit, le cycle des messages et l'absence de débordement ou de texte masqué sont validés. Aucun appel externe, erreur de page, 5xx ou message console attribuable n'a été observé. |
| Fond autonome | Le fichier servi correspond au source, d'empreinte `7dba25e81613ca6c45d9f0920db5656f20b89db30605112c23f94dc0bcde33f0`, sans couplage au défilement. Une trace de 90,5 s a mesuré 359 échantillons, une excursion de 13,965 px, un écart de phase de 0,003 px entre 44 s et 88 s et aucun saut ; la période reste 44 000 ms. |
| Journaux | La passe navigateur finale ne produit aucune entrée Drupal de sévérité erreur, aucun avertissement ou fatal PHP dans le journal web et aucune télémétrie navigateur en échec. |
| Nettoyage | La fixture, le trigger, les helpers, les paquets et profils Chromium/Playwright, les agrégats publics temporaires et le snapshot représentatif ont été supprimés. Le snapshot initial a été restauré une seconde fois après les contrôles ; la checkout de service est revenue propre sur `release/prod@2ffa253`, puis DDEV a été arrêté et la fenêtre runtime libérée. |

Après restauration, les empreintes correspondent exactement au baseline :

- base de données normalisée :
  `161ef10fa5a32b0075cc19c4abd9a3ec8b9d8e0039be392db83f676397134b4b` ;
- configuration active brute :
  `07ec23fcbcbab78e48b746283be7ffb12fda49b5c59264fdf0fea31e0ec32702` ;
- configuration active canonique :
  `afbb00cf0ab8f303c2e0d1cf133eb35f2fe3b31003ebd1f5b06120b488011d6b` ;
- manifeste et arborescence des fichiers publics :
  `b79f7d91d5b07e1112de44d3f5bcf792be013e2ef34e3aa2acd64fa552298645`
  et `b2da5f8ecc26524ecd92508fc57d1153225ef671062f15229fb170e0aa82deb2` ;
- 43 entrées DDEV ignorées :
  `f7debf29b6f48b28b8c6ab510a24b97a8682df380a1561b342abac2f5c3110a2` ;
- état des comptes, thèmes, page d'accueil et nombres d'entités :
  `0015a5f732c4cffbfafe17b9b223a847063c3d0e5cbf9dbaed0a6a38d460c990`.

### Matrice encore différée au déploiement

La validation ci-dessus est locale et représentative ; elle ne vaut pas
validation de production. Sans accès au VPS, les contrôles suivants restent à
exécuter dans la procédure de déploiement autorisée :

| Contrôle différé | Résultat attendu |
| --- | --- |
| Dry-run sur l'état représentatif de production | Une seule différence de titre et de corps pour `/les-artistes-de-l-asso`, sans création ni opération d'alias, de menu, de configuration ou de Commerce. |
| Application et idempotence | Une seule première mise à jour, puis un second dry-run et une seconde application sans opération ni révision supplémentaire. |
| Alias et menu | Tous les alias et liens restent inchangés ; « Partenaires » demeure actif, de poids 10, sous À propos, vers `/les-artistes-de-l-asso`. |
| Contrôle public | Titre, H1/H2, quatre liens, rendu bureau/mobile et absence de texte d'attente sont vérifiés sur le site déployé. |
| Restauration | Les fingerprints avant/après et la procédure de rollback sont conservés afin de pouvoir rétablir exactement l'état préalable en cas d'échec. |

## Contenu manuel restant

- Publier les dates réelles des stages comme contenus `Stage`.
- Relier les prochaines dates depuis les pages de catégories si l'équipe veut
  des liens explicites en plus de la liste automatique.
- Finaliser les textes commerciaux et les contraintes techniques des prestations.

## Préservation des hubs existants

La page `/stages` reste le hub de stages. Le script met à jour le corps du nœud
de page, mais ne modifie pas le bloc Views existant qui publie
automatiquement les contenus `Stage` sur `/stages`.

La page `/concerts` et son comportement existant ne sont pas touchés.

## Archive des validations locales de l'architecture

Les validations DDEV consignées ci-dessous concernent les anciennes PR #78 et
#72 ; la validation actuelle de la PR #87 est consignée séparément ci-dessus.
Aucun VPS ni aucune donnée de production n'ont été utilisés pour la PR #87.
Les anciennes commandes restent documentées comme références historiques pour
une opération ultérieure distinctement autorisée.

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

### Validation locale PR #78 et intégration PR #80 — 31 août 2026

La validation a porté sur le commit rebasé `1e585eb` de la PR #78, au-dessus de
`release/prod` au commit `233896619e6f74904927fbb62073a00962881069`, qui
contient la PR #80. Avant toute écriture Drupal, le snapshot DDEV nommé
`pr78-content-architecture-baseline-20260831T1027Z` a été créé. La base locale
standard ne contenant aucun nœud, une fixture représentative strictement
limitée aux seize pages, seize alias et neuf liens de menu historiques requis a
été créée avec les API d'entités Drupal, avec marqueurs et manifeste dédiés.
Aucune écriture SQL brute de contenu et aucun import de configuration n'ont été
effectués. Les journaux et captures de cette exécution ont été conservés sous
`/tmp/pr78-content-runtime.Zzs65h`.

Le comportement fermé et l'atomicité ont été contrôlés avant l'application
valide :

- sur l'état sans prérequis, `--apply` a terminé avec le statut 1 et 31 blockers,
  avant la phase B, avec `transaction_started=FALSE; writes=0` et l'état
  canonique des nœuds, alias et liens de menu inchangé ;
- avec deux liens normalisés vers `/concerts`, `--apply` a terminé avec le
  statut 1 et a affiché les deux plugin IDs en conflit,
  `menu_link_content:fe10325a-86e0-4126-be61-1637264f5483` et
  `menu_link_content:e5778993-82b0-4af2-a6a5-0ee54fd7c8f0`, sans écriture ;
- un défaut tardif local injecté sur la première insertion de lien de menu a
  produit `ROLLBACK_CONFIRMED` après le début de la phase B. Aucun message de
  mutation n'a été libéré et les empreintes avant/après sont restées identiques.

Le dry-run représentatif a été entièrement en lecture seule. Son plan immuable,
d'empreinte
`6bcbf60362e42716736832c6a4a3a87f7eda70107c340329e6e9c6cfb1248831`,
contenait exactement 39 opérations : 12 mises à jour de pages, 6 créations de
pages, 6 créations d'alias, 8 mises à jour de liens, 6 créations de liens et la
désactivation sur place du lien Services. Le plan de la première application,
d'empreinte
`cdb0fafc5697f1856e4402bf79c38cc4119e25c1dd704a91daf99d9670797660`,
a exécuté ces 39 opérations dans une transaction puis a vérifié 22 pages, 38
alias et 15 liens de menu, dont 14 actifs et le lien Services conservé et
désactivé. Les identifiants des seize pages et alias historiques, dont les
quatre pages de référence, sont restés inchangés ; les corps des quatre
références sont eux aussi restés identiques. Un second dry-run puis une seconde
application ont produit le même plan sans opération, uniquement des constats
`OK`, sans marqueur de mutation ni variation des empreintes d'entités, de base
durable ou de configuration :
`96ba6c0c4dce113d7da0e7de7c693d2eb65a6c840154a576826f11342d1eec19`.

L'arbre obtenu correspond exactement aux tableaux de ce document : cinq
racines, Cours particuliers et Stages sous Cours & Stages, D’Jam, Orchestre et
Forum sous le plugin parent UUID de `/ateliers`, puis L’Asso, Partenaires,
Origine et Blog sous le plugin parent UUID de `/a-propos`. Le chemin public
final reste `/ateliers`, créé dans cette fixture locale où il était absent ;
aucun alias `/projets-collectifs` n'a été créé. Le titre Drupal et le libellé de
menu visible sont « Projets collectifs ». Les pages `/forum`, `/blog` et
Services répondaient en HTTP 200 ; le lien Services restait absent du menu
visible.

Après la création de `/blog` et `/forum`, l'installeur ciblé
`apply-forum-blog-mvp-2026.sh` de la PR #80 a été exécuté dans l'ordre prévu. Son
premier dry-run était sans écriture. L'application a créé exactement les
quatorze configurations attendues et a limité le format autorisé de la
configuration existante `field.field.comment.comment.comment_body` à
`basic_html` dans la transaction indépendante de durcissement prévue. Le
dry-run suivant a signalé ce réglage et les quatorze cibles `MATCH`. Les nœuds,
alias, corps et identifiants des deux Basic pages sont restés identiques. Aucun
`config:import` n'a été lancé. Les contrôles intégrés ont confirmé :

- les introductions Basic page, les repères éditoriaux et les blocs
  dynamiques Blog/Forum coexistent une seule fois ;
- les états vides « Aucun article publié pour le moment. » et « Aucun sujet de
  discussion publié pour le moment. » s'affichent correctement ;
- deux articles publiés apparaissent du plus récent au plus ancien avec leurs
  liens canoniques ; l'article non publié reste absent de la liste et son
  canonique retourne 403 à l'anonyme ;
- un sujet Forum publié apparaît avec son lien canonique, tandis qu'un sujet
  non publié reste absent et son canonique retourne 403 à l'anonyme comme au
  membre de test ;
- la proposition est visible pour un membre authentifié, absente pour un
  visiteur anonyme, et son accès direct anonyme retourne 403.

Chromium/Playwright a validé les six pages ciblées sur desktop, tablette et
tiroir mobile, chacune à 100 %, 150 % et 200 % de reflow effectif, soit neuf
profils. Les destinations exactes des liens parents et enfants, la navigation
par clic vers `/ateliers` et `/forum`, l'accès 200 à `/blog`, les bascules de
sous-menu, le tiroir, les libellés `É`, `À`, `é`, `&` et `’`, l'alignement du
logo à droite du titre et l'absence de collision, débordement horizontal, ID
dupliqué ou menu dupliqué ont tous été confirmés. Aucun avertissement PHP ni
aucune erreur de console attribuable à cette PR n'a été observé.

Les cinq nœuds fonctionnels temporaires et l'utilisateur de test ont ensuite
été supprimés par leur manifeste ; aucun commentaire ni aucune soumission
n'avaient été créés. Les quatorze configurations PR #80 ont été retirées par
son rollback ciblé ; le durcissement du format de commentaire est resté en
place comme prévu jusqu'à la restauration finale du snapshot, qui a rétabli
l'ensemble de la configuration de référence. Les preuves finales montrent zéro
nœud, alias ou lien de fixture, zéro commentaire, soumission ou utilisateur de
test, et le retour exact aux références suivantes :

- base normalisée :
  `161ef10fa5a32b0075cc19c4abd9a3ec8b9d8e0039be392db83f676397134b4b` ;
- configuration active :
  `314:0c24d19541a7cc33d5ea118e715fe62f3515c8dcf848aaa62cf66f2e259de1be` ;
- fichiers publics, 245 fichiers et 838007 octets :
  `4b9fcb5393cc709f13690da12854baa5be2931836e2bdacf6448e9bf218d0dcd`.

Le checkout DDEV principal a été rendu propre sur `release/prod` au commit
`233896619e6f74904927fbb62073a00962881069`, les thèmes Olivero/Claro et la page
d'accueil `/node` ont été restaurés, et DDEV a été arrêté et libéré.

### Archive PR #72 — dry-run Codespaces du 30 août 2026

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
du périmètre PR #72 à seize pages et douze liens actifs. La matrice locale et
le dry-run de production consignés ci-dessous restent eux aussi des preuves
historiques de la PR #72 : ils ne valident pas les dix-huit pages et quatorze
liens actifs de la présente évolution.

### Archive PR #72 — matrice d'intégration atomique du 30 août 2026

La matrice a utilisé Drupal 11.3.3 dans le projet DDEV local, sans donnée de
production, sans import de configuration et sans accès VPS. Chaque scénario a
été isolé par snapshot. L'empreinte DB couvre les lignes des 95 tables durables
dans un export déterministe ; elle exclut uniquement les tables de cache et
d'exécution volatiles, les triggers de test, le schéma et les compteurs
`AUTO_INCREMENT`. L'empreinte de configuration active couvre les 314 objets,
triés et normalisés. L'empreinte de configuration est restée
`314:e96a6b849b5e15c6e16fde5b6494a9e57fe9f7161dd8398c819963ddfdfc2127`
pendant toute la matrice.

| Scénario | Résultat | Empreinte DB durable avant → après |
| --- | --- | --- |
| 1. Base vide | `--apply` termine avec le statut 1, 29 blockers de phase A, `transaction_started=FALSE; writes=0` ; 0 nœud, 0 alias géré et 0 lien principal après l'échec. | `95:4216ba9c6ed358615568e9fad2f474edd4f28c5f1ed8d6bf9b3185b4bef3da88` → identique |
| 2. Base partielle | Fixture de 4 pages et 2 liens ; `--apply` termine avec le statut 1, 19 blockers explicites, sans démarrer la phase B ; les comptes restent 4 nœuds, 20 alias totaux et 2 liens. | `95:fe43ffeca5b9f7112df02e5e4097fe61c0b5f5c0be3525c9872dd8253016cc03` → identique |
| 3. Destination ambiguë | Fixture complète avec deux liens normalisés vers `/concerts` ; statut 1, les deux plugin IDs sont affichés, 0 écriture ; les comptes restent 16 nœuds, 32 alias et 10 liens. | `95:96db503d9adaea931125da7ae2c631a2f60bbd4e3d02bb2a4110edac9b32bb6e` → identique |
| 4. Base représentative locale | Le dry-run, sans écriture, prévoit 4 pages, 4 alias, 4 liens, 8 mises à jour de liens et 1 désactivation. La première application crée l'arbre exact de 5 racines et 7 enfants ; les 16 identités nœud/alias existantes et les 4 pages de référence restent identiques. La seconde application ne contient aucune opération et ne change aucune empreinte. | dry-run : `95:d77ab85144f37815c94212adf7d8b8491cde2e2aa64f417d5f16160914de0738` → identique ; première application : cette empreinte → `95:397021c6f060706717caf1e729ddddc53c66ca9ba723ef3d4b8c3dd43e2132ca` ; seconde application : empreinte finale identique |
| 5. Échec tardif forcé | Un trigger local `BEFORE INSERT` sur `menu_link_content_data` bloque le premier lien créé, après les tentatives d'écriture des pages et alias. Le statut est 1, `ROLLBACK_CONFIRMED` est affiché, aucun message de mutation n'est libéré et les comptes restent 16 nœuds, 32 alias et 9 liens. | `95:d77ab85144f37815c94212adf7d8b8491cde2e2aa64f417d5f16160914de0738` → identique |

Pour le scénario 4, seules les tables de nœuds et révisions, corps, alias et
révisions, liens et révisions de menu ainsi que `menu_tree` ont changé. Les
quatre pages de référence ont conservé exactement le même SHA-256 sérialisé
avant et après. Les 13 liens finaux comprennent les 12 liens actifs canoniques
et le lien Services conservé au premier niveau mais désactivé.

Le premier essai de mécanisme de faute utilisait un trigger `BEFORE UPDATE` ;
Drupal réécrit cette table par insertion lors de la sauvegarde et ce probe n'a
donc pas provoqué d'échec. Le snapshot représentatif a été restauré avant le
test valide `BEFORE INSERT` décrit ci-dessus. Enfin, le snapshot de base vide a
été restauré : aucun trigger, nœud, alias géré ou lien de fixture ne subsiste.
Ces résultats démontrent le comportement fermé et le rollback local, mais la
base de fixtures ne reproduit pas le contenu actif de production. Cette limite
avait été couverte pour le seul périmètre PR #72 par le dry-run en lecture seule
documenté ci-après ; ces résultats ne sont pas réattribués à la présente
évolution.

## Archive PR #72 — validation de production

Le script refuse les chemins `/var/www` sauf si `--allow-vps` est passé
explicitement.

### Dry-run historique représentatif du 30 août 2026

Le dry-run en lecture seule a été exécuté contre le contenu actif de production
avec exactement le script du commit
`7a7d0583eab2714d2d8480a89b75f9aee9cb76e9`. Le checkout déployé est resté sur
`release/prod` pendant toute l'opération ; aucun basculement de branche, aucune
application et aucune écriture Drupal n'ont eu lieu. La phase A s'est terminée
avec succès et a produit le plan immuable SHA-256 suivant :

```text
7ebf43ce832dd500c8b80be1944f7374b49c9213da8eb21dd833975b1d3777c8
```

La sortie intégrale confirme :

- aucun `BLOCKED`, `FAIL`, `ROLLBACK_UNCONFIRMED`, `CREATED`, `UPDATED`,
  `DISABLED`, `DELETED` ou autre marqueur d'écriture réel ;
- les quatre pages de référence conservées en lecture seule : `/concerts` vers
  le nœud 6, `/djam` vers le nœud 10, `/orchestre-des-reveurs` vers le nœud 9
  et `/contact` vers le nœud 7 ;
- la résolution correcte de toutes les pages gérées et de leurs alias ;
- `WOULD_UPDATE page /accueil` sur le nœud 14 et
  `WOULD_UPDATE page /association` sur le nœud 13, leurs corps actifs étant
  actuellement vides ;
- exactement quatre créations de pages et quatre créations d'alias :
  `/cours-et-stages`, `/ateliers`, `/a-propos` et `/origine` ;
- la conservation des pages existantes de cours, stages, artistes et services,
  sans modification étrangère au périmètre ;
- les cinq futurs liens de premier niveau, dans l'ordre : « Cours & Stages »,
  « Concerts & Événements », « Ateliers », « À propos » et « Contact » ;
- les enfants exacts : « Cours particuliers » et « Stages » sous « Cours &
  Stages » ; « D’Jam » et « Orchestre » sous « Ateliers » ; « L’Asso »,
  « Partenaires » et « Origine » sous « À propos » ;
- la désactivation sur place du lien Prestations existant, sans suppression,
  tandis que `/services-prestations-artistiques` reste accessible depuis les
  cartes des hubs ;
- aucune opération Commerce, produit, import de configuration, suppression ou
  modification de contenu non liée.

Le dry-run s'est terminé avec succès et sans écriture. Après suppression du
script temporaire, le checkout VPS est resté propre et inchangé. Le journal
complet de l'exécution a été conservé sur l'hôte sous :

```text
/tmp/pr72-production-dry-run-20260830-130753.log
```

Ce contrôle avait levé la condition de brouillon de la PR #72 liée à la
validation du contenu actif. Il ne valide pas la présente évolution et ne
constitue pas une autorisation d'appliquer ou de déployer son plan. Le commit de
documentation qui consigne ce résultat ne modifie pas
`drupal/scripts/apply-content-architecture-2026.sh` : le script validé reste
exactement celui du commit `7a7d0583eab2714d2d8480a89b75f9aee9cb76e9`.

## Procédure de validation ultérieure en lecture seule

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

- les douze pages gérées qui doivent préexister et les quatre pages de référence sont
  retrouvées par leur alias sans changement d'identifiant ;
- les six pages et alias `/cours-et-stages`, `/ateliers`, `/forum`,
  `/a-propos`, `/blog` et `/origine` sont les seules créations permises, avec
  `WOULD_CREATE` attendu s'ils n'existent pas encore ; si l'un existe déjà, son
  nœud, son alias et tout éventuel bloc `BODY_CHANGE_EXACT` font l'objet d'une
  revue explicite ;
- toute mise à jour reste limitée aux dix-huit pages gérées ; `/ateliers`
  conserve son alias tout en prenant le titre « Projets collectifs », et
  `/a-propos` conserve ses quatre cartes existantes en ajoutant Blog ;
- les huit liens actifs existants sont retrouvés par destination et seuls les
  renommages, poids et reparentages de l'arbre canonique peuvent apparaître ;
- seuls les six liens de menu vers `/cours-et-stages`, `/ateliers`,
  `/a-propos`, `/forum`, `/origine` et `/blog` peuvent afficher
  `WOULD_CREATE` ;
- chacun des quatorze liens actifs affiche le libellé, le poids, le parent et
  `enabled=TRUE` prévus, avec cinq liens au premier niveau et neuf enfants ;
- Forum est placé après D’Jam et Orchestre sous le plugin parent UUID de
  `/ateliers`, et Blog après Origine sous le plugin parent UUID de
  `/a-propos` ;
- le lien existant `/services-prestations-artistiques` affiche exactement
  `WOULD_DISABLE`, ou `OK disabled` s'il est déjà inactif, tout en restant
  conservé au premier niveau et non supprimé ;
- aucun alias ou lien de destination ambigu, aucune modification de contenu
  étrangère à la liste et aucun `FAIL`, `CREATED`, `UPDATED`, `DISABLED` ou
  `DELETED` n'apparaît.

Ne lancer ni `--apply`, ni import de configuration, ni reconstruction de cache.
Toute divergence maintient la PR en brouillon et exige une revue avant une
opération d'écriture distinctement autorisée. Cette procédure n'a pas été
exécutée pour la présente évolution.

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

### Ordonnancement intentionnel du déploiement

La dépendance fonctionnelle Forum/Blog est désormais présente dans
`release/prod`. L'ordre validé localement reste intentionnel : appliquer d'abord
l'architecture de contenu de la PR #78, puis l'installeur ciblé de la PR #80,
qui exige que `/blog` et `/forum` existent déjà. Le déploiement attend encore la
validation conjointe de la typographie et du menu dans l'environnement ciblé,
ainsi qu'une autorisation d'écriture distincte. Cette PR ne déploie et ne
fusionne rien.
