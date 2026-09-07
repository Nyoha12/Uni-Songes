# Retrait du frontend Cloudflare Pages historique — 2026

## Statut et périmètre

Ce document prépare le retrait progressif du site statique historique sans
modifier Drupal, le DNS, les domaines personnalisés ni la configuration du
compte Cloudflare. Le site public canonique est `https://unisonges.fr`. L'origine
historique confirmée est `https://uni-songes.pages.dev`.

Cette première étape est une couche de compatibilité : elle redirige les anciens
liens dont la destination Drupal est certaine, empêche l'indexation du contenu
restant et préserve les pages qui nécessitent encore une décision métier ou
juridique. Elle ne déploie rien et ne supprime pas l'ensemble de `public/`.

**Gate obligatoire : cette PR doit rester en draft et ne doit pas être mergée
avant l'approbation explicite du propriétaire du site sur la table de redirection,
les cinq routes préservées, le retrait du sitemap historique et la procédure de
mise en production.**

Audit initial effectué le 31 août 2026, puis branche rebasée et validation de
preview effectuée le 1er septembre 2026 sur la base
`a8582ad691673c4096193bf3fb0f2a741739792c` de `origin/release/prod`.

## Nature des preuves

### Preuves dans le dépôt

- Les 19 fichiers HTML versionnés portent une balise canonical vers
  `https://uni-songes.pages.dev`; les 18 pages normales figuraient aussi dans le
  sitemap historique.
- `public/robots.txt` annonçait ce sitemap sur la même origine.
- Les JSON-LD de l'accueil et de l'association emploient aussi cette origine.
- Aucun fichier HTML ne contient de propriété Open Graph `og:url`.
- Les pages normales répètent l'ancienne navigation, un lien « Mon compte »
  sans destination, les anciens CTA de réservation/stages et le pied de page
  légal/confidentialité/contact.
- L'ancien contenu publie des tarifs et offres désormais contradictoires, un
  parcours Google Schedule, et un formulaire Contact relié à un endpoint Google
  Apps Script. Les URLs externes correspondantes ne sont pas reproduites ici et
  ne doivent pas être promues.
- Il n'existait aucun `public/_redirects`. Le seul contrat de build versionné
  était le README : racine `/`, sortie `public`, commande vide ou `exit 0`.
  Aucun fichier Wrangler, Pages Functions, `_routes.json`, workflow ou manifeste
  de build ne fixe les paramètres du projet Cloudflare.
- Tous les fichiers de `public/` sont valides en UTF-8 et normalisés NFC.

### Observations HTTP publiques en lecture seule

- Sur l'origine historique, `/`, `/cours/`, `/reserver-un-cours/` et
  `/mentions-legales/` répondaient `200`; une route inexistante répondait `404`.
  Les corps représentatifs étaient identiques aux fichiers locaux. Les réponses
  ne portaient pas de `X-Robots-Tag`.
- Les réponses Pages observées conservaient
  `Referrer-Policy: strict-origin-when-cross-origin` et
  `X-Content-Type-Options: nosniff`. Ces deux en-têtes ne sont pas définis dans
  le dépôt et doivent donc être recontrôlés en preview puis après rollout.
- Pages normalisait actuellement `/cours`, `/cours/index`, `/cours/index/`,
  `/cours/index.html`, `/index`, `/index/` et `/index.html` avec un `308` avant
  d'atteindre le HTML. Les variantes sont rendues explicites dans `_redirects`
  afin d'éviter un futur enchaînement `308` puis `301`.
- `https://unisonges.fr/` répondait `301` vers `/accueil`; les destinations
  retenues ci-dessous répondaient directement `200`, avec une canonical HTTPS
  auto-référente et un titre cohérent.
- `https://unisonges.fr/mentions-legales`,
  `https://unisonges.fr/politique-confidentialite` et
  `https://unisonges.fr/videos` répondaient `404`.
- Sur `/reservation-cours`, une query inconnue synthétique était reprise dans
  les réglages/form actions Drupal, tandis qu'un paramètre synthétique
  `destination` provoquait un `301` supplémentaire vers l'URL nettoyée. La
  transmission indifférenciée des queries de l'ancienne réservation n'est donc
  pas approuvée sans test de preview.
- Le sitemap Drupal répondait `200` en XML valide, mais ne contenait qu'une
  seule URL, `https://unisonges.fr/`, elle-même redirigée vers `/accueil`. Il ne
  constitue donc pas encore un inventaire canonique complet.

### Comportement seulement inféré avant preview

- Le projet Pages semble connecté à GitHub et produit des previews, mais sa
  commande de build, sa branche de production et son alias de production sont
  des réglages externes non vérifiés.
- La présence de `_redirects` et `_headers` dans la sortie `public/` devrait
  activer les règles statiques Pages. Seule une preview peut confirmer l'ordre
  entre ces règles, la normalisation des URLs, les requêtes avec query string et
  les en-têtes effectivement servis.
- La suppression de `public/sitemap.xml` devrait faire répondre le mécanisme de
  404 statique à `/sitemap.xml`; ce statut doit être confirmé en preview.
- Un check Cloudflare Pages vert prouve seulement qu'une preview a été
  construite. Il ne prouve ni la promotion sur l'origine de production, ni le
  routage d'un domaine personnalisé.

## Inventaire des routes historiques

| Route historique | Risque ou contenu constaté | Décision de cette étape |
| --- | --- | --- |
| `/` | Accueil avec ancien tarif d'essai, ancienne masterclass et ancienne navigation | Redirection vers `/accueil` |
| `/cours/` | Anciens tarifs et packs, ancien essai et ancien parcours de paiement/réservation | Redirection vers `/cours` |
| `/cours-bien-etre-respiration/` | Allégations bien-être/respiration, dont une mention de l'apnée du sommeil | Préservation temporaire, noindex; pas d'équivalent approuvé |
| `/cours-musique-impro-compo/` | Ancien axe didgeridoo, techniques, improvisation et composition | Redirection vers `/cours/didgeridoo` |
| `/stages/` | Hub avec anciens prix, fréquences et masterclass | Redirection vers `/stages` |
| `/stages-debutant/` | Ancien prix, fréquence, capacité et horaires | Redirection vers `/stages/didgeridoo`, qui présente le rendez-vous débutant actuel |
| `/stages-intermediaire/` | Ancien prix, fréquence, capacité et horaires | Redirection vers `/stages/didgeridoo`, qui présente le rendez-vous intermédiaire actuel |
| `/masterclass-avancee/` | Offre Montargis, repas/hébergement et anciens tarifs | Préservation temporaire, noindex; aucune masterclass actuelle équivalente confirmée |
| `/stages-sur-demande/` | Ancienne règle de cinq demandes et interventions sur mesure | Redirection vers `/services-prestations-artistiques`, page actuelle des interventions adaptées |
| `/concerts-dates/` | Ancien agenda vide et renvoi vers D'Jam | Redirection vers `/concerts` |
| `/association-unisonges/` | Ancienne présentation de l'association | Redirection vers `/association` |
| `/djam/` | Anciennes fréquence, gratuité, don et adhésion | Redirection vers `/djam` |
| `/orchestre-des-reveurs/` | Ancien prix mensuel et ancienne adhésion | Redirection vers `/orchestre-des-reveurs` |
| `/videos/` | Page d'attente annonçant des vidéos futures | Préservation temporaire, noindex; `/videos` est `404` sur Drupal |
| `/contact/` | Formulaire historique actif vers Google Apps Script | Redirection vers `/contact`, afin de ne plus présenter l'ancien formulaire |
| `/reserver-un-cours/` | Iframe et lien direct Google Schedule historiques | Redirection vers `/reservation-cours`, sans promouvoir l'ancien service |
| `/mentions-legales/` | Informations uniques d'éditeur, responsable, e-mail et hébergeur | Préservation temporaire, noindex; la route Drupal candidate est `404` |
| `/politique-confidentialite/` | Politique statique unique, devenue inexacte sur les formulaires et Google Schedule | Préservation temporaire, noindex; la route Drupal candidate est `404` |
| `/404.html` | Page d'erreur historique, déjà munie d'un meta `noindex` | Conservée; le header global renforce le noindex et robots autorise désormais sa lecture |

Les actifs `styles.css` et `contact/contact-form.js` restent versionnés. Le
JavaScript Contact n'est plus chargé dans le parcours normal une fois la page
Contact redirigée. Aucune route inconnue n'est rabattue vers l'accueil : la 404
reste préférable à une destination trompeuse.

## Table exacte des redirections

Les 64 règles sont statiques, permanentes (`301`), absolues et HTTPS. Chaque
destination répondait directement `200` lors de l'audit. Aucun target ne pointe
vers l'origine historique ou vers une autre redirection connue.

| Sources exactes | Destination absolue | Raisonnement sémantique |
| --- | --- | --- |
| `/`, `/index`, `/index/`, `/index.html` | `https://unisonges.fr/accueil` | Accueil canonique direct; évite le saut par la racine Drupal |
| `/cours`, `/cours/`, `/cours/index`, `/cours/index/`, `/cours/index.html` | `https://unisonges.fr/cours` | Hub actuel des cours, sans reprendre les anciens tarifs |
| `/cours-musique-impro-compo`, `/cours-musique-impro-compo/`, `/cours-musique-impro-compo/index`, `/cours-musique-impro-compo/index/`, `/cours-musique-impro-compo/index.html` | `https://unisonges.fr/cours/didgeridoo` | Le contenu actuel couvre la technique et la composition au didgeridoo |
| `/stages`, `/stages/`, `/stages/index`, `/stages/index/`, `/stages/index.html` | `https://unisonges.fr/stages` | Hub actuel des stages |
| `/stages-debutant`, `/stages-debutant/`, `/stages-debutant/index`, `/stages-debutant/index/`, `/stages-debutant/index.html` | `https://unisonges.fr/stages/didgeridoo` | Page actuelle regroupant explicitement le stage débutant |
| `/stages-intermediaire`, `/stages-intermediaire/`, `/stages-intermediaire/index`, `/stages-intermediaire/index/`, `/stages-intermediaire/index.html` | `https://unisonges.fr/stages/didgeridoo` | Page actuelle regroupant explicitement le stage intermédiaire |
| `/stages-sur-demande`, `/stages-sur-demande/`, `/stages-sur-demande/index`, `/stages-sur-demande/index/`, `/stages-sur-demande/index.html` | `https://unisonges.fr/services-prestations-artistiques` | Page actuelle des formats adaptés pour structures et écoles |
| `/concerts-dates`, `/concerts-dates/`, `/concerts-dates/index`, `/concerts-dates/index/`, `/concerts-dates/index.html` | `https://unisonges.fr/concerts` | Agenda canonique actuel |
| `/association-unisonges`, `/association-unisonges/`, `/association-unisonges/index`, `/association-unisonges/index/`, `/association-unisonges/index.html` | `https://unisonges.fr/association` | Même entité et même finalité éditoriale |
| `/djam`, `/djam/`, `/djam/index`, `/djam/index/`, `/djam/index.html` | `https://unisonges.fr/djam` | Page Drupal du même projet |
| `/orchestre-des-reveurs`, `/orchestre-des-reveurs/`, `/orchestre-des-reveurs/index`, `/orchestre-des-reveurs/index/`, `/orchestre-des-reveurs/index.html` | `https://unisonges.fr/orchestre-des-reveurs` | Page Drupal du même projet |
| `/contact`, `/contact/`, `/contact/index`, `/contact/index/`, `/contact/index.html` | `https://unisonges.fr/contact` | Contact canonique et retrait du formulaire historique |
| `/reserver-un-cours`, `/reserver-un-cours/`, `/reserver-un-cours/index`, `/reserver-un-cours/index/`, `/reserver-un-cours/index.html` | `https://unisonges.fr/reservation-cours?utm_source=legacy-pages` | Tunnel Drupal actuel; la query fixe remplace les anciens paramètres au lieu de les transmettre |

Pages conserve les queries entrantes lorsqu'une destination n'en définit pas.
Cette conservation est acceptée pour les pages informatives. Les cinq règles de
réservation définissent au contraire la query fixe, non sensible,
`utm_source=legacy-pages` : la preview corrigée confirme qu'elle remplace toute
query entrante, y compris un paramètre inconnu ou de redirection synthétique.
Le target contrôlé répond directement `200`; aucun paramètre de réservation
historique n'est transmis à Drupal.

## Stratégie de désindexation

`public/_headers` applique globalement :

```text
X-Robots-Tag: noindex, nofollow, noarchive
```

Le motif `/*` couvre les URLs propres (`/route/`), les variantes `index.html`,
la 404 et les actifs résiduels. L'objectif critique est que toute page HTML qui
reste servie ne soit ni indexée, ni archivée, ni utilisée comme source de liens
historiques. Le type de contenu explicite de robots.txt est conservé; la règle
XML devenue sans objet est retirée avec le sitemap. Aucun en-tête de sécurité
n'est retiré ou remplacé.

`public/robots.txt` conserve `Allow: /` et ne déclare plus le sitemap historique.
La suppression de `Disallow: /404.html` permet aux robots de lire aussi le
noindex de la page d'erreur. Un `Disallow: /` global serait contre-productif :
il pourrait empêcher les moteurs déjà au courant des URLs d'observer le header
de désindexation.

Les canonical historiques restent versionnées dans les 19 fichiers HTML; elles
ne sont pas massivement réécrites. Si la priorité des règles Pages est conforme
à l'attente, seuls les cinq contenus préservés et la 404 peuvent encore les
exposer dans une réponse HTML : les pages avec équivalent quittent l'origine via
un `301` avant d'être rendues. Le header noindex global est la politique de
retrait des réponses résiduelles. La preview doit confirmer ce comportement
effectif.

Le noindex n'est pas un contrôle d'accès : les cinq pages préservées restent
publiquement lisibles jusqu'à leur décision propriétaire. Leurs anciennes
métadonnées Open Graph peuvent encore produire un aperçu lors d'un partage
direct, même si elles ne définissent aucun `og:url`; elles ne doivent donc pas
être considérées comme du contenu courant.

## Préservation légale et confidentialité

Les mentions légales et la politique de confidentialité ne sont ni supprimées,
ni redirigées vers Contact ou Accueil. Leurs candidates Drupal évidentes
répondent actuellement `404`, et aucune page juridique n'a été découverte dans
la navigation publique auditée. Une redirection silencieuse ferait perdre des
informations uniques et pourrait tromper l'utilisateur.

Décision propriétaire requise : publier et valider les contenus juridiques
canoniques dans Drupal, notamment l'identité de l'éditeur, l'hébergement, les
traitements réellement actifs et les prestataires, puis fournir leurs URLs
exactes. Une PR ultérieure pourra alors ajouter deux `301` et supprimer les
copies historiques après validation juridique.

## Retrait du sitemap et dépendance à la PR #82

Le sitemap historique est supprimé et n'est plus annoncé par robots.txt. Il ne
doit pas continuer à promouvoir 18 pages obsolètes. Aucune redirection vers le
sitemap Drupal n'est ajoutée dans cette étape : malgré son statut `200` et son
XML valide, celui-ci ne listait que la racine redirigée lors de l'audit.

La PR #82 reste le gate fonctionnel pour un sitemap Drupal complet. Après son
merge et son rollout séparés, un owner doit vérifier que
`https://unisonges.fr/sitemap.xml` est stable, contient les URLs publiques
attendues et ne crée pas de chaîne. Une PR de suivi pourra alors ajouter un
`301` exact depuis le sitemap Pages si cette compatibilité est encore utile.
Jusque-là, `/sitemap.xml` sur la preview historique doit répondre avec un vrai
statut d'absence, attendu `404`, et ne doit pas servir l'ancien XML.

La suppression dans le dépôt ne retire pas une éventuelle soumission externe du
sitemap historique, par exemple dans un outil de moteur de recherche. Le
propriétaire doit inventorier puis retirer séparément ces soumissions après le
rollout; aucun accès à ces comptes n'est réalisé dans cette PR.

## Vérification de la preview Cloudflare Pages

Cette procédure est en lecture seule. Elle doit être exécutée par un reviewer
autorisé sur l'URL de preview fournie par le check GitHub, sans modifier le
dashboard Cloudflare :

1. confirmer que le check Pages correspond au commit exact de la PR et que la
   sortie publiée est bien `public/`;
2. parser les 64 lignes de `_redirects`, puis requêter chaque source sans suivre
   automatiquement les redirections;
3. exiger `301`, un unique `Location` HTTPS absolu identique à la table et aucun
   target sur l'origine de preview ou l'origine historique;
4. suivre ensuite chaque `Location` et exiger une seule arrivée `200` sur
   `https://unisonges.fr`, sans chaîne intermédiaire;
5. tester une query d'attribution sur une page informative, une discipline
   reconnue sur la réservation, un faux paramètre sensible avec une valeur
   factice et un paramètre `destination` vers un domaine factice; consigner la
   conservation/suppression exacte, refuser toute fuite du paramètre inconnu,
   toute `Location` externe et toute chaîne supplémentaire;
6. exiger un `200` et `X-Robots-Tag: noindex, nofollow, noarchive` sur les cinq
   routes préservées; ne soumettre aucun formulaire et ne charger aucun service
   de réservation externe;
7. exiger `404` sur une route inconnue et sur `/sitemap.xml`, avec le header de
   désindexation sur la réponse HTML de 404;
8. vérifier que `/robots.txt` répond `200 text/plain`, autorise le crawl et ne
   contient ni `Disallow: /` ni directive `Sitemap:`;
9. vérifier que les réponses conservent au minimum les observations publiques
   `Referrer-Policy: strict-origin-when-cross-origin` et
   `X-Content-Type-Options: nosniff`, et que les types de contenu attendus ne
   régressent pas;
10. inspecter les logs du check pour toute règle ignorée, limite dépassée ou
    configuration de build différente du README.

Une preview verte sans ces contrôles runtime n'autorise pas le merge.

### Résultats de la validation de preview — 1er septembre 2026

GitHub a associé la preview immuable
`https://fc32e04e.uni-songes.pages.dev` au head fonctionnel de la PR #88
`dbc371194b3cb7c4082447503cef962fedeea93a` et au déploiement Pages
`fc32e04e-0a24-41ee-add9-fa69be73f456`. Les requêtes étaient des GET/HEAD sans
cookie, authentification, soumission ni chargement de sous-ressource. Le commit
de documentation qui consigne ces résultats ne modifie pas la surface Pages;
sa propre preview doit néanmoins être recontrôlée et son head final consigné
dans la PR avant approbation.

| Contrôle | Résultat observé |
| --- | --- |
| Redirects déclarés | `64/64` passés, `0` échec; chaque source répond `301` avec un unique `Location` absolu HTTPS exactement conforme à la table |
| Destinations Drupal | `12/12` répondent directement `200` en HTML UTF-8, sans second `Location`, boucle ni chaîne |
| Queries informatives | Le paramètre bénin testé est conservé et le target répond directement `200` |
| Queries de réservation | Les cinq variantes remplacent les queries reconnue, inconnue et de redirection synthétiques par la seule query fixe `utm_source=legacy-pages`; target direct `200` |
| Headers des `301` | `64/64` portent `X-Robots-Tag: noindex, nofollow, noarchive`, `Referrer-Policy: strict-origin-when-cross-origin` et `X-Content-Type-Options: nosniff` |
| Routes préservées | `5/5` répondent `200`, HTML UTF-8, avec les mêmes headers de retrait/sécurité; les corps correspondent octet pour octet aux fichiers non modifiés |
| Robots | `200 text/plain` UTF-8, corps exact `User-agent: *` + `Allow: /`, aucun `Disallow: /`, aucune directive `Sitemap:` |
| Sitemap historique | `/sitemap.xml` répond `404` avec le fallback HTML 404 et les headers de retrait/sécurité; aucun XML ni ancienne liste d'URLs |
| Route inconnue | `404`, aucun `Location`, fallback 404 et headers attendus; aucun wildcard vers l'accueil ou Drupal |
| Cookies et confidentialité | Aucun `Set-Cookie`; aucune donnée réelle, aucun formulaire et aucun endpoint externe appelé |

Les cinq routes temporairement disponibles restent exactement :

- `/cours-bien-etre-respiration/`;
- `/masterclass-avancee/`;
- `/videos/`;
- `/mentions-legales/`;
- `/politique-confidentialite/`.

Leurs HTML individuels n'ont pas changé : la PR ne leur ajoute ni prix, ni
achat, ni lien Google Schedule. Leur contenu historique reste publiquement
lisible mais globalement noindexé, dans l'attente des décisions déjà décrites.

Deux comportements Pages sont consignés sans les masquer :

- des variantes synthétiques avec caractères percent-encodés ne correspondent
  pas toujours aux règles exactes; certaines normalisent d'abord par `308`,
  d'autres servent le HTML historique en `200`. Toutes les réponses testées
  portaient le noindex et Referrer-Policy; les `200` portaient aussi `nosniff`,
  contrairement aux `308` générés par la plateforme. Sans preuve de liens
  entrants encodés ni mapping fini, aucune règle synthétique arbitraire n'est
  ajoutée;
- `/404.html` normalise par `308` vers `/404`. Ce `308` porte X-Robots-Tag et
  Referrer-Policy mais ni `nosniff` ni Content-Type; `/404` répond `200` avec
  `Content-Type: text/html; charset=utf-8`, tous les headers, et reste noindexé.
  Les routes réellement inconnues répondent bien `404` avec tous les headers.

Ces deux limites, les cinq préservations et la table corrigée restent soumises à
l'approbation explicite du propriétaire; une réussite de preview ne rend pas la
PR prête à merger.

## Procédure de rollout production

Cette procédure est un runbook pour le propriétaire; elle n'est pas exécutée
par cette PR.

1. obtenir l'approbation explicite du propriétaire sur les redirects, les cinq
   préservations et la suppression du sitemap;
2. terminer la vérification de preview ci-dessus et archiver les statuts,
   `Location` et en-têtes observés dans la PR;
3. confirmer que les PR parallèles, notamment #82, ne modifient pas ce périmètre
   et qu'aucun changement DNS/custom-domain n'est nécessaire;
4. merger seulement la PR approuvée dans `release/prod` selon le processus
   GitHub normal, consigner le SHA du merge approuvé et laisser l'intégration
   Pages existante construire la version;
5. avant tout contrôle fonctionnel, vérifier dans les métadonnées GitHub que le
   check/déploiement Pages de production annonce exactement ce SHA; un check
   vert rattaché à un autre commit bloque le rollout;
6. ne promouvoir aucun autre domaine et ne modifier ni Drupal ni le dashboard
   Cloudflare dans ce rollout;
7. exécuter immédiatement la matrice publique complète : 64 sources et 12
   targets, queries de réservation, cinq préservations, headers, robots,
   sitemap, 404 et route inconnue.

## Rollback

Pour une destination Drupal isolée en panne, privilégier une correction ciblée :
retirer ou corriger toutes et uniquement les règles qui la ciblent dans une PR
dédiée, en conservant le noindex et le retrait du sitemap.

Pour une régression globale du moteur de redirects, des headers ou de la 404,
créer un revert GitHub du SHA de merge/squash enregistré pour l'ensemble de
cette PR et faire repasser ce revert par le même pipeline Pages. Ne pas réécrire
l'historique, ne pas toucher au DNS et ne pas compenser par une redirection
globale. Ce revert atomique restaure aussi le sitemap historique, retire le
header noindex et réexpose les anciens parcours Google présents dans les HTML
Contact/Réservation : cette réexposition temporaire doit être explicitement
acceptée par le propriétaire, puis vérifiée sur l'origine avant d'ouvrir une
correction réduite.

Consigner le SHA du revert, attendre un check/déploiement Pages GitHub rattaché
exactement à ce SHA, puis vérifier au minimum la racine, une réservation, une
route préservée, robots.txt, sitemap.xml et une route inconnue. En cas de revert
global, confirmer explicitement l'état attendu du sitemap restauré et la perte
du noindex plutôt que de déclarer le rollback réussi sur le seul check vert.
La réservation doit inclure une query inconnue/de redirection entièrement
synthétique : consigner le statut et le `Location` attendus, et refuser toute
transmission inattendue, destination externe ou chaîne supplémentaire.

Un `301` peut rester mémorisé par les navigateurs et les robots après le revert.
Le rollback rétablit le serveur, mais ne garantit pas un retour immédiat pour
les clients qui ont déjà mis la réponse en cache.

## Vérification publique post-rollout

Depuis un environnement autorisé, utiliser des requêtes HTTP en lecture seule
sur `https://uni-songes.pages.dev` et `https://unisonges.fr` uniquement :

- contrôler les 64 sources, leur `301`, leur `Location` exacte, le nombre de
  sauts et le `200` final;
- confirmer que chaque query entrante sur les cinq sources de réservation est
  remplacée par la seule attribution fixe et ne crée aucun saut supplémentaire;
- contrôler les cinq routes conservées, la 404, le header X-Robots et les deux
  en-têtes de sécurité observés avant rollout;
- contrôler robots.txt et confirmer l'absence du sitemap historique;
- confirmer que le formulaire Google Apps Script et Google Schedule ne sont
  plus atteints depuis les parcours redirigés;
- recontrôler les targets Drupal, sans authentification, soumission, panier ni
  route privée;
- consigner l'heure UTC, le commit Pages annoncé par GitHub et les résultats
  exacts dans le ticket de rollout.

Rejouer aussi les observations percent-encodées consignées ci-dessus. Une
différence par rapport à la preview, ou l'apparition d'une réponse indexable,
bloque la poursuite et demande une décision ciblée plutôt qu'un catch-all.

Le retrait définitif des cinq pages préservées, des actifs statiques et du projet
Pages lui-même exige une décision ultérieure distincte, ainsi que la validation
des remplacements juridiques et fonctionnels.
