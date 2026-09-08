# Fondation du pied de page public — 2026

## État et périmètre

PR #94, branche `codex-add-public-footer-foundation`. Le retest ciblé du
7 septembre 2026 valide les trois corrections : **prête pour revue**, sans merge.

#106 est réellement fusionnée depuis le 7 septembre à 21:08 UTC ; sa fusion
n’est pas une dépendance fonctionnelle du footer. Après fetch, rebase sans conflit
sur la vraie `release/prod` `3e53bc5b3d1b3ded9a207bc2e16ec48aa84b9bdf`.
SHA testé : `3f35e4074aff34fda6cb277629a10200f9aeafe7`. Les six fichiers
étaient strictement identiques à ceux du correctif `3352898c0dc762dc8802519d63b11fc1fe32ca23`
avant/après rebase. Le retest n’a nécessité aucun nouveau correctif ; seule
cette documentation est ensuite actualisée.

Cette passe a reçu l’attribution runtime exclusive après #106. Aucun autre
travail runtime concurrent détecté. DDEV était déjà actif à l’inspection,
contrairement à l’état annoncé ; le checkout servant était propre et son état
a été sauvegardé avant préparation. Il est maintenant restauré, DDEV arrêté et
environnement libéré. Aucun accès aux worktrees #82/#113/#86/#90, aucun VPS,
paiement, Google ou email externe. Aucun runtime ultérieur ne démarre automatiquement.

Périmètre exact, six fichiers :

- `drupal/web/themes/custom/unisonges_theme/templates/page.html.twig`
- `drupal/web/themes/custom/unisonges_theme/templates/page--front.html.twig`
- `drupal/web/themes/custom/unisonges_theme/templates/includes/_footer.html.twig`
- `drupal/web/themes/custom/unisonges_theme/css/public-footer.css`
- `drupal/web/themes/custom/unisonges_theme/unisonges_theme.libraries.yml`
- `docs/functional/public-footer-foundation-2026.md`

Aucun CSS global/auth-account.css, JavaScript, PHP, configuration Drupal ou
contenu livré supplémentaire ; aucun Bootstrap ajouté. Les anciennes déclarations
d’assets sont inchangées.

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
pas de capture `|render`, de branchement `trim()`, parsing, `striptags`, `raw`,
rendu forcé de lazy builder ou contournement BigPipe. Un wrapper ou lazy
finalement vide ne supprime plus les cinq liens. Texte « 0 », images, accès,
cache et attachements restent confiés au renderer Drupal.

Aucun bloc administré n’est supprimé ou réécrit. Il n’y a **pas de déduplication
automatique** : un bloc recopiant volontairement un lien reste rendu, comme
le confirme le probe contenant son propre lien Contact. La région complémentaire
ne doit pas ajouter une seconde copie des cinq liens ; son état actif réel et
ses éventuels styles propres doivent être revus avant déploiement.
Le snapshot local n’avait aucun bloc Uni-Songes en footer ; cet état et la
présence de fichiers YAML ne prouvent pas la configuration de production.

Ni Ressources, ni Boutique, ni nouvelles destinations. Les liens
`/mentions-legales` et `/politique-confidentialite` restent volontairement absents :
le propriétaire de contenu doit confirmer leurs routes canoniques Drupal et
approuver leurs contenus. Aucun texte juridique ou organisationnel inventé,
aucun contenu légal repris de Cloudflare.

## Shell et compatibilités

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
À l’origine, son placement extérieur pouvait le laisser derrière le frame
fixe ou hors viewport, puisque `html/body` ne défilent pas.

La géométrie reste pilotée par `styles.css` : `.container`, `.site-footer`,
frame fixe à hauteur contrainte et `overflow:auto`, inner sans hauteur contrainte.
Les feuilles auth et éditoriale conservent leur inner overflow-visible sur leurs
routes. La feuille dédiée n’ajoute ni hauteur contrainte, ni positionnement
fixe/sticky, ni scroller ou masquage de débordement. Header fixe, BGFX autonome,
leurs IDs et la cible d’évitement `#main-content` sont préservés.

#99 : les vrais blocs content sont enfants directs du nouveau main.
`.auth-account-page #main-content.unisonges-public-main` reçoit un flex colonne
effectif, sans dépendre des utilitaires Bootstrap absents. Les règles existantes
titre `order:-30` et messages `order:-20` rétablissent l’ordre **visuel**
titre → messages → formulaire/profil. L’ordre DOM historique et les poids
−8/−7/−3 ne sont pas réécrits. Le footer reste hors main/formulaire, tout en
partageant le scrollframe peint par les styles auth existants.

#100 : `page.content` conserve le chemin inline unique
`.unisonges-system-messages`, dans main ; pas de rendu de `page.header`,
de wrapper fixe/toast, de copie ou de destination tardive dans le footer.

#103 fusionnée : bloc, liste d’Articles, pager et deux panneaux restent dans
`page.content`, avant le footer. Aucun module, View, rail, disclosure ou CSS
éditorial modifié. Son CTA Blog et le lien permanent du footer restent distincts.

Un seul header, une seule source serveur de menu et un seul drawer. Ordre clavier :
évitement/header, contenu principal, navigation permanente puis éventuel contenu
complémentaire. Aucun tabindex ajouté. Footer implicite `contentinfo`,
navigation nommée, pas de titre artificiel.

## Trois corrections — résultats réels

| Défaut reproduit précédemment | Résultat du retest |
| --- | --- |
| Wrapper/lazy finalement vide supprimant la navigation | Région vide, métadonnées, bloc refusé, lazy vide/privé : cinq liens toujours présents dans leur navigation propre |
| Blanc Barrio sur fond clair, environ 1,06:1 / 1,07:1 | Texte/configuration et liens réellement lisibles sur la surface opaque ; états interactifs et couleurs forcées passent |
| Messages avant titre sur les comptes | Connexion, inscription, réinitialisation et compte propriétaire : titre → message frais → formulaire/profil, avec vrais wrappers flex |

Une seule attache native `unisonges_theme/public-footer` dans l’include.
L’entrée charge uniquement `css/public-footer.css`, après le layout existant.
Chaque page contrôlée charge cette feuille une seule fois.

Sur le fond réellement calculé/composé et observé `rgb(255,253,248)` :

| Usage | Couleur calculée | Contraste |
| --- | --- | --- |
| Identité et texte configuré | `rgb(16,32,51)` | 16,18:1 |
| Liens ordinaires et classe is-active | `rgb(7,91,85)` | 7,83:1 |
| Hover/focus/active | `rgb(122,62,0)` | 8,21:1 |

38 mesures couvrent la navigation et le vrai paragraphe/lien configuré dans
`.content`, l’outline 3 px et les couleurs forcées. En couleurs forcées :
texte noir/blanc 21:1, liens 13,99:1, outline au moins 11,29:1.
Les captures confirment la surface peinte, le soulignement et le focus non clippé.
La classe is-active ajoutée temporairement au lien de test est retirée ensuite.

Les liens visités sont aussi vérifiés par pixels après navigation locale :
couleur verte et fond clair effectivement peints, contrôle positif `:visited`
temporairement magenta dans un profil Chromium isolé, puis retrait du contrôle.
Le lien configuré pointait temporairement vers la page locale visitée ; son
href est restauré. Ce test ne s’appuie pas sur `getComputedStyle(:visited)`.
Aucun H2 configuré n’était présent dans ce probe ; la règle de titres garde
sa preuve statique, et les futurs blocs administrés restent à revoir.

## Preuves et couverture ciblée

Preuves privées de cette reprise :
`$(git rev-parse --absolute-git-dir)/pr94-retest-20260907.6QGGXZw6/`.
Les commandes et points de reprise sont dans `RESUME.md`, les résultats dans
les JSON/JSONL, scripts adaptés et captures. Aucun dump, lien de connexion ou
état de session n’est publié dans Git.

Réutilisation des scripts antérieurs et outils déjà disponibles :
Drupal 11.3.3, PHP 8.3.31, Twig PHP 3.22.2, Chromium 140.0.7339.16,
Playwright 1.62.0 et html-validate 9.7.1 ; aucun nouvel installateur/framework.
Les prérequis locaux ont été préparés par les API ciblées de la passe antérieure,
puis les helpers fusionnés Forum/Blog et accueil #103, gardes intactes, dry-run
puis apply avec sauvegarde et token exact pour #103. Pas de ConfigImporter ni
commande d’import de configuration.

- Twig réel 3/3 ; 16 probes de région avec/sans wrapper ; 21 BlockViewBuilder :
  build/lazy au plus une fois, tags/contextes/cache et bibliothèques/settings
  attachés conservés, accès refusés respectés.
- 32 observations navigateur de région, plus région sans bloc et visible
  explicitement MISS → HIT. Texte « 0 », image chargée 40 × 40 px, lazy
  visible/vide/privé et BigPipe final vérifiés. Séquence membre/admin → anonyme,
  puis répétition membre → anonyme : aucune fuite ni placeholder résiduel.
  Un libellé « cold » n’est pas une preuve de MISS : la reprise du lazy vide
  administrateur était déjà chaude, et les en-têtes réels sont conservés.
- Quatre routes auth : un H1, une vraie sentinelle Messenger, main calculé flex,
  ordre visuel correct, footer hors formulaire ; profil propriétaire UID 2
  contrôlé sans inventer de formulaire.
- Smoke groupé accueil/page longue/connexion : desktop 1440 × 900,
  mobile 320 × 740, reflow 640 × 450. Ce dernier est l’équivalent de 200 %
  d’une fenêtre 1280 × 900, **pas un zoom natif du navigateur**.
  Footer entièrement atteignable, pas d’overflow horizontal ni scroller imbriqué,
  header/BGFX fixes, panneaux ouverts/fermés, drawer/Échap/retour du focus,
  évitement et cinq liens parcourus avec Tab. Compléments : région visible
  à 320 px/reflow et seconde page du pager (10 + 2 Articles).
- Drawer comparé sur la vraie base et la branche, fermé/ouvert : diagnostics
  HTML exactement identiques, dont `unique-landmark`. Fermé, son complément
  est absent de l’arbre accessible ; ouvert, il reste non nommé dans les deux
  versions. Les styles inline/attributs hidden hérités restent signalés,
  sans exclusion générale ; la branche ajoute son unique contentinfo.
- Zéro nouveau HTTP 5xx, erreur console ou exception de page dans ces parcours.
  Aucun nouveau warning watchdog après la préparation (dernier ID 192).
  Les dépréciations ECA/Entity et l’échec initial du probe dans un conteneur CLI
  au namespace périmé sont attribués aux préparatifs, pas au footer.
- Revue indépendante des résultats favorable ; garde six fichiers, UTF-8/NFC,
  diff-check et scan ciblé des secrets à la clôture.

Deux assertions de probe ont été affinées, sans correctif produit :
espaces autour de « 0 » et contrôles contextuels administrateur hors `.content`.
Le premier essai `:visited` en contexte éphémère n’avait pas de contrôle positif ;
le profil isolé a permis de l’établir. Ces limites ne sont pas masquées.

Les comportements non affectés de la matrice précédente restent référencés :
Basic courte, Blog/Forum, Contact, réservation, panier et autres tailles.
Compte rendu complet/restauration précédente au commit `50673a7`, preuves
`/workspaces/Uni-Songes/.git/pr94-runtime-20260907.HD21qnVF/`.
Harness statique initial au commit `3cebbbf` ; corrections statiques
au commit `3352898` et dans `pr94-static-corrections-20260907/`
du répertoire Git de ce worktree. Ces preuves ne remplacent pas les nouveaux
tests des trois corrections.

## Restauration et libération

Snapshot frais `pr94-footer-corrections-before-20260907T2113` restauré.
Checkout servant revenu propre sur sa branche initiale `release/prod`,
SHA `9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c`, sans reset.

Empreintes avant/après identiques :

- SQL normalisé : `ffdcc9fcc3c970ea6b5214a9dace369df7b8f2f62d0beeed00657b1f1914204f`.
- 314 configurations : `e96a6b849b5e15c6e16fde5b6494a9e57fe9f7161dd8398c819963ddfdfc2127`.
- Archive des fichiers publics, modes compris :
  `c5649a085b02074b174726b9f2ffeb16207a44fca39a2b00dbc163c5aec97d93`.
- `settings.php` : `9902e3833a2f962b0775f271cabfb9502840828c318cdc7b062a65f7b3d71ece`.

Comparaison exacte des fingerprints : thèmes Olivero/Claro, front `/node`,
maintenance désactivée, 7 comptes, 16 alias, 4 produits ; zéro node, commande,
soumission et bloc footer, comme au départ. Les seules données de cette passe
disparaissent avec la restauration du snapshot. Probe et fichiers générés déplacés dans les
preuves privées, récupérables ; les deux fichiers publics initiaux sont conservés.
La première extraction avait appliqué l’umask à `files/php` ; l’extraction
avec permissions préservées rétablit l’archive strictement identique.

DDEV et router arrêtés, Chromium fermé : **environnement explicitement libéré**.
Aucun merge ni lancement d’une autre validation.
