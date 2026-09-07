# Fondation du pied de page public — 2026

## État et périmètre

PR #94, branche `codex-add-public-footer-foundation`. Corrections statiques du
7 septembre 2026 depuis `50673a78232c928d6c28928c4fb1ad69cc0f3456`.
Après fetch et vérification distante, `release/prod` reste à
`9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c` : aucun nouveau rebase nécessaire.

Terminal 4 / #106 possède seul DDEV et le checkout servant. Cette reprise
n’utilise ni DDEV, Docker, Drush, navigateur, ni VPS ; aucun autre worktree,
notamment #82, n’est touché. La PR reste **brouillon jusqu’au retest réel**.
La fin de #106 ne déclenche aucun runtime : une attribution explicite est requise.

Le périmètre autorisé est désormais exactement six fichiers :

- `drupal/web/themes/custom/unisonges_theme/templates/page.html.twig`
- `drupal/web/themes/custom/unisonges_theme/templates/page--front.html.twig`
- `drupal/web/themes/custom/unisonges_theme/templates/includes/_footer.html.twig`
- `drupal/web/themes/custom/unisonges_theme/css/public-footer.css` — nouveau
- `drupal/web/themes/custom/unisonges_theme/unisonges_theme.libraries.yml`
- `docs/functional/public-footer-foundation-2026.md`

Aucun changement de CSS global, auth-account.css, JavaScript, PHP, configuration,
route ou contenu Drupal ; aucun Bootstrap supplémentaire. Les entrées d’assets
existantes sont préservées.

## Changement explicite de contrat

L’ancien choix **« région administrée OU navigation de repli » est remplacé**
par **identité + navigation permanente + région complémentaire**. Ce n’est pas
une correction équivalente de l’ancien contrat.

L’include possède l’unique footer et affiche toujours « Uni-Songes », puis :

| Libellé permanent | Destination |
| --- | --- |
| Cours & Stages | `/cours-et-stages` |
| Projets collectifs | `/ateliers` |
| À propos | `/a-propos` |
| Blog | `/blog` |
| Contact | `/contact` |

La zone complémentaire interpole `{{ footer_region }}` une seule fois, par le
pipeline Twig/Drupal normal. Aucun test du tableau ni de son HTML final :
suppression de la capture `|render`, du branchement `trim()` et du repli
conditionnel. Aucun parsing HTML, `striptags`, `raw`, rendu forcé de lazy builder
ou contournement BigPipe. Un wrapper ou lazy finalement vide ne peut donc plus
supprimer les cinq liens. Le code ne filtre ni texte « 0 », ni image, ni
métadonnée ; accès, cache et attachements restent confiés au renderer Drupal.

Aucun bloc administré n’est supprimé ou réécrit. Il n’y a **pas de déduplication
automatique** : un bloc recopiant volontairement les liens reste rendu.
La région complémentaire ne doit pas servir à ajouter une seconde copie de
ces cinq liens ; son état actif réel doit être revu avant déploiement.
Le snapshot local de la passe précédente n’avait aucun bloc Uni-Songes en
footer ; ni cet état local ni la présence de fichiers YAML ne prouvent la
configuration de production.

Ni Ressources, ni Boutique, ni nouvelles destinations. Les liens
`/mentions-legales` et `/politique-confidentialite` restent volontairement
absents : le propriétaire de contenu doit confirmer leurs routes canoniques
Drupal et approuver leur contenu avant publication. Aucun texte juridique ou
organisationnel inventé, aucun contenu légal repris de Cloudflare.

## Shell et compatibilités conservés

```text
#unisonges-bgfx
└── #unisonges-bgfx-scroll
    └── #unisonges-bgfx-layer
site-header
#unisonges-scrollframe.scrollframe
└── .scrollframe__inner
    ├── main#main-content.unisonges-public-main
    │   ├── page.highlighted? / page.help?    shell normal seulement
    │   └── page.content                     une fois
    └── footer.site-footer.unisonges-public-footer
        └── .container
            ├── identité Uni-Songes
            ├── navigation permanente nommée
            └── .unisonges-public-footer__region
                └── page.footer              une fois
```

Le footer est frère après main, hors formulaire, dans le défilement existant.
Le footer extérieur d’origine pouvait rester derrière le frame fixe ou hors
viewport : `html/body` ne défilent pas et ce footer n’était pas son descendant.

La géométrie reste pilotée par `styles.css` : `.container`, `.site-footer`,
frame fixe à hauteur contrainte et `overflow:auto`, inner sans hauteur contrainte.
`auth-account.css` et `editorial-home.css` gardent leur inner overflow-visible
sur leurs routes. La feuille dédiée ne fixe aucune hauteur ni position, ne crée
pas de scroller et ne masque aucun débordement. Header fixe et BGFX autonome
restent inchangés, ainsi que leurs IDs et la cible d’évitement `#main-content`.

#99 : les preuves réelles établissent que les blocs de la région content,
sans wrapper intermédiaire, deviennent enfants directs du nouveau main.
Les utilitaires `d-flex/flex-column` n’étaient pas chargés malgré leur présence
dans les dépendances Composer. La classe dédiée reçoit maintenant
`display:flex; flex-direction:column; min-width:0` **uniquement sous
`.auth-account-page`**. Les règles existantes titre `order:-30` et messages
`order:-20` peuvent ainsi s’appliquer ; aucun poids, titre, message ou formulaire
n’est déplacé ou copié. Le footer suit main et reste hors de tout formulaire ;
il partage toujours le scrollframe peint par les styles auth existants.

#100 : `page.content` conserve le chemin inline unique
`.unisonges-system-messages`, dans main ; aucun rendu de `page.header`, wrapper
fixe/toast ou destination tardive dans le footer.

#103 est fusionnée : bloc d’accueil, liste d’Articles, pager et deux panneaux
restent entièrement dans `page.content`, avant le footer. Aucun composant du
rail, disclosure, module, View ou CSS éditorial n’est modifié. Son CTA Blog et
le lien permanent du footer restent distincts.

Un seul header, une seule source serveur du menu principal et un seul drawer.
Ordre clavier : évitement/header, contenu principal, liens du footer puis
éventuel contenu complémentaire. Aucun tabindex ajouté. Le footer a le rôle
implicite `contentinfo`, sa navigation un nom propre ; pas de titre artificiel.

## Trois défauts reproduits, corrections ciblées

| Défaut de la passe réelle | Correction de cette reprise |
| --- | --- |
| Wrapper métadonnées ou lazy vide/privé : navigation absente malgré une sortie finalement vide | Nouveau contrat permanent, région rendue séparément sans décision sur son HTML |
| Texte/liens configurés Barrio presque blancs sur fond clair : environ 1,06:1 / 1,07:1 | Surface opaque et couleurs locales, y compris `.content`, titres, liens et états interactifs |
| Compte : messages avant titre, car main calculé en block | Flex effectif limité au main auth ; conservation des règles order #99 et du chemin #100 |

`attach_library('unisonges_theme/public-footer')` apparaît une seule fois dans
l’include commun. L’entrée dédiée charge seulement `css/public-footer.css`,
avec une dépendance au layout existant pour l’ordre des feuilles ; la gestion
des attachements reste native Drupal.

Sur le fond opaque proposé `#fffdf8`, calcul sRGB :

| Usage | Couleur | Contraste |
| --- | --- | --- |
| Texte/titres | `#102033` | 16,18:1 |
| Liens ordinaires, visités et classe is-active | `#075b55` | 7,83:1 |
| Hover/focus/active et outline | `#7a3e00` | 8,21:1 |

Les sélecteurs propres au footer dépassent les règles Barrio documentées,
notamment `.site-footer .content` et ses liens. Soulignement, retour à la ligne
et outline 3 px sont explicites, sans opacité ou clipping. L’outline local
prioritaire neutralise aussi celui de la route auth. En couleurs forcées :
Canvas, CanvasText, LinkText, Highlight, avec ajustement automatique.
Ces calculs ne prouvent pas les couleurs réellement peintes de tout bloc
administré possédant ses propres styles : ce contrôle reste au retest.

## Preuves réutilisées et nouvelles

Les audits et le harness initial restent accessibles dans cette documentation
au commit `3cebbbf36ebd26ef5a5d0e04e26b1e0f06a6bfd5`.
Le compte rendu runtime complet et les empreintes de restauration sont conservés
au commit `50673a78232c928d6c28928c4fb1ad69cc0f3456` ; preuves privées :
`/workspaces/Uni-Songes/.git/pr94-runtime-20260907.HD21qnVF/`
(`region-probe.php`, `block-cache-probe.php`, `browser.cjs`, JSON/HTML/captures).
Pas de copie des anciens logs, dumps ou sessions dans Git.

Cette ancienne passe portait sur `3cebbbf` : Twig PHP 3/3, 16 probes de région,
21 BlockViewBuilder, 54 observations par rôles/cache et 25 observations
géométriques, plus image corrigée. Elle a reproduit les trois défauts ci-dessus.
Cache/attachements et absence de fuite inter-rôles étaient vérifiés pour
l’ancienne version, pas pour cette correction. La restauration du snapshot,
des fichiers publics et du checkout, empreintes identiques, ainsi que l’arrêt
DDEV ont été consignés à la fin de cette passe ; aucune nouvelle manipulation
runtime ou restauration ici.

Contrôles statiques affectés, sans installation ni framework ajouté :

- Réutilisation du harness Twig.js 3.0.0 / html-validate 9.7.1, PHP CLI pour les
  seuls tableaux simulés ; trois templates compilés.
- 30/30 fixtures : normal/accueil × 15 états (chaînes vide/visible, tableau vide,
  cache seul, markup vide/blanc, enfant refusé, enfant visible, « 0 », non-texte,
  wrapper vide, placeholder inerte, liens configurés, image, plain_text).
- Une interpolation de région et une attache de bibliothèque ; sortie
  complémentaire conservée, cinq liens exacts toujours présents une fois dans
  leur navigation. Le cas configuré avec deux liens supplémentaires les
  conserve : aucune déduplication implicite.
- Un main/contenu/footer, footer frère suivant hors formulaire ; header, source
  menu, drawer, messages, skip et IDs BGFX uniques. Template #103 fusionné avec
  deux Articles, pager et deux panneaux entièrement dans main.
- HTML : zéro nouveau diagnostic ; quinze diagnostics drawer `unique-landmark`
  strictement identiques à la preuve base/branche conservée. Ce diagnostic
  précis reste visible, sans exclusion générale. Les tolérances historiques
  role explicite/void-style/espaces sont inchangées.
- CSS analysé avec css-tree existant : 32 déclarations, grammaire vérifiée après
  substitution des variables dans les deux palettes, sélecteurs limités au
  footer ou au main auth. Contrastes calculés et ordre -30/-20/0 vérifiés.
- YAML analysé avec js-yaml existant : une entrée CSS supplémentaire seulement,
  déclarations antérieures identiques. UTF-8/NFC, diff exact six fichiers,
  `git diff --check`, scan ciblé des ajouts pour secrets et garde de
  chevauchement des six chemins avec les autres PR ouvertes réussis.
- Revue indépendante du diff : pas de défaut démontré ; couleurs des blocs,
  vrais enfants flex et cache/BigPipe maintenus comme limites runtime.

Scripts adaptés et résultats locaux :
`$(git rev-parse --absolute-git-dir)/pr94-static-corrections-20260907/`
(`fixtures.cjs`, `checks.cjs`, `results.txt`). Ils réutilisent les caches npm
existants. La simulation d’interpolation ne remplace pas le renderer Drupal :
elle ne résout pas les lazy builders et ne prouve pas le bubbling de cache.

## Retest réel restant — attribution explicite requise

Reprendre les probes existantes sur le SHA corrigé, après relevé/snapshot frais
du checkout servant attribué. Aucun import de configuration ni données de
production ; préserver les données préexistantes et utiliser les helpers ciblés
déjà fusionnés. Rejouer uniquement les cellules affectées :

| Groupe | Attendu |
| --- | --- |
| Région vide, métadonnées/wrapper vide, accès refusé, lazy vide/privé | Cinq liens permanents même après résultat final vide ; pas de fuite de contenu privé |
| Région visible, image et texte « 0 » | Région une fois, aucun contenu perdu ; cinq liens permanents séparés |
| Cache froid/chaud, anonyme/membre/admin, BigPipe | Cache/attachements conservés, aucun double build/rendu, placeholder final résolu sans erreur ni fuite |
| Contraste des liens et contenus configurés | Fond réellement peint opaque ; au moins 4,5:1 dans les états pertinents ; focus et couleurs forcées utilisables |
| Connexion/inscription/mot de passe/compte avec messages | Titre → messages → formulaire, vrais enfants flex ; chemin inline unique, footer après main et hors formulaire |
| Smoke accueil, clavier et mobile | Articles/pager et panneaux dans main ; footer atteignable au dernier cran ; skip, drawer ouvert/fermé, focus ; pas d’overflow ni scroll imbriqué |

Grouper ces comportements en desktop, tablette, 390 px, 320 px et reflow 200 %.
Le précédent reflow équivalent ne prouve pas le zoom natif, encore à contrôler.
Réutiliser les parcours non affectés (Basic courte/longue, Blog/Forum, Contact,
réservation, panier) et ne les rejouer qu’en cas d’écart commun de shell.
Comparer le diagnostic drawer base/branche fermé/ouvert s’il évolue :
il était hérité, absent de l’arbre accessible fermé, non nommé ouvert ; ne pas
masquer une régression par exclusion. Vérifier header/BGFX fixes et logs
PHP/console/HTTP 5xx sur les cellules rejouées.

Enfin retirer seulement les fixtures de cette future passe, restaurer snapshot,
fichiers et checkout initial, comparer les empreintes, conserver les preuves et
arrêter/libérer DDEV. Ne passer #94 prête pour revue qu’après ces résultats ;
aucun merge.
