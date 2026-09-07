# Identité navigateur et favicon — 2026

## Statut et décision

La livraison fournit uniquement deux fichiers ICO et cette documentation.
La phase statique initiale a été complétée par le contrôle runtime ciblé du
7 septembre 2026, explicitement autorisé après la libération de DDEV par #94.
Elle fournit un favicon Uni-Songes
sans modifier le logo de l’en-tête, les routes, le CSS, le JavaScript ou les
templates de page.

L’emblème existant des deux animaux est le seul signe de marque graphique
suivi et rendu par le thème dans la configuration locale testée. Il est donc
utilisé sans redessin : son canevas est réduit proportionnellement et centré
dans un carré transparent.
Le détail intérieur devient nécessairement très fin à 16 px, mais la silhouette
ovale, la séparation orange/ivoire et le contour brun restent distinctifs. Le
mot-symbole n’est pas utilisé, car il ne serait pas lisible à cette taille.

Deux copies strictement identiques de l’ICO sont suivies :

- `drupal/web/themes/custom/unisonges_theme/favicon.ico` est détecté par le
  mécanisme favicon natif de Drupal pour le thème public ;
- `drupal/web/favicon.ico` répond au chemin conventionnel `/favicon.ico`, dont
  l’absence et la 404 de production sont le symptôme confirmé de départ.

Les critères HTTP/head/cache de cette reprise sont satisfaits localement dans
un vrai Chromium, avec JavaScript activé. #106 peut passer prête pour revue,
sans autorisation de merge. L’observation visuelle de l’onglet et le HTTP de
production ne sont pas revendiqués ; leurs limites sont précisées ci-dessous.

## Audit initial

### Base Git et chevauchement avec les PR ouvertes

Après `git fetch origin release/prod --prune`, la branche de travail et
`origin/release/prod` pointaient toutes deux sur :

```text
8cc82f9af6899aedc14490931c415293d0bdf0cb
```

Le snapshot GitHub du 2 septembre 2026 comptait 17 PR ouvertes. Aucun de leurs
fichiers ne correspond aux trois chemins de cette phase. En particulier :

- la PR #94 possède `page.html.twig`, `page--front.html.twig`,
  `templates/includes/_footer.html.twig` et sa documentation ;
- la PR #95 possède `css/styles.css` et sa documentation de contraste ;
- la PR #88 possède les fichiers de retrait du site historique sous `public/`.

Cette phase ne touche aucun de ces fichiers. La garde a interrogé chaque PR
ouverte avec `gh api repos/Nyoha12/Uni-Songes/pulls/<numéro>/files` et a comparé
les noms normalisés avec la liste exacte de cette phase.

### Inventaire de marque suivi

L’inventaire de 38 rasters suivis ne contient aucun fichier `logo*`, SVG ou ICO
et aucune image carrée. Les seuls candidats de marque ou de bandeau sont :

| Asset suivi | Nature et dimensions | Transparence | Empreinte SHA-256 |
|---|---|---|---|
| `images/mark-2026-03-01-215937.png` | emblème PNG RGBA8, 2479 × 2039 | alpha complet ; bbox visible 2342 × 1845 | `e707617df3ec97c9e4320793714cd7dc798fead9ee8779eed8bc9288587aee72` |
| `images/mark-latest.png` | lien suivi vers le PNG daté | contenu résolu identique | même empreinte de contenu |
| `images/banner-2026-03-01-184712.png` | photographie d’abeille PNG RGBA8, 2476 × 1259 | fondu alpha | `7588a5e067040a966ddd905b791b6061910129f604c1ce390edf0de48299a249` |
| `images/banner.png` | copie régulière du bandeau daté | identique | même empreinte |
| `images/banner-latest.png` | lien suivi vers le bandeau daté | identique | même empreinte de contenu |
| `images/bgsrc/bannierebasil.JPG` | photographie source probable, JPEG RGB8, 6060 × 3115 | aucune | `4d150f720e34231f6c3d0a14ce81afd9842d9f6660c873975263a3f14ab638bd` |

Les chemins abrégés du tableau sont sous
`drupal/web/themes/custom/unisonges_theme/`. Les photographies ne constituent
pas un signe adapté à un favicon. L’emblème est déjà référencé directement par
`templates/partials/site-header.html.twig` via `mark-latest.png` et testé à
48 px, 44 px et 32 px dans la documentation fonctionnelle du thème. Le nom
« Uni-Songes » est du texte HTML ; aucun mot-symbole raster séparé n’existe.

Le PNG source ne contient que les chunks `IHDR`, `IDAT` et `IEND`. Il ne
contient ni auteur, ni copyright, ni profil externe. Il a été ajouté par le
commit `8f3900a48fffd0b5617a03b50868a5d7e7586a00`, auteur `Uni-Songes Bot`, en
même temps que son utilisation dans l’en-tête. Le dépôt ne contient pas de
licence ou de brand kit propre aux images ; la licence Drupal générique ne doit
pas être présentée comme preuve de droits sur cet asset. La présente dérivation
repose uniquement sur son statut d’asset Uni-Songes suivi, déjà utilisé par le
thème, et sur l’autorisation de dériver un asset suivi approprié. Aucun asset
graphique externe n’a été téléchargé ou incorporé.

### Bloc de marque et configuration

- `system.theme.yml` définit `unisonges_theme` comme thème public et `gin`
  comme thème d’administration.
- `unisonges_theme.info.yml` hérite de Bootstrap Barrio et ne restreint pas la
  feature favicon.
- `system.theme.global.yml` conserve `features.favicon: true` et
  `favicon.use_default: true`.
- Il n’existe pas de `unisonges_theme.settings.yml`; la configuration Barrio
  ne remplace pas le favicon.
- `system.site.yml` définit le nom `Uni-Songes`, mais ne porte aucun réglage
  d’icône.
- Le bloc `unisonges_theme_site_branding` demande le logo et le nom du site.
  Le shell personnalisé rend le nom et `mark-latest.png`; aucune source de
  logo distincte n’est suivie et la documentation fonctionnelle constate que
  `.brand__logo` n’est pas rendu dans la configuration testée.

Aucun fichier de marque source, bloc, titre, H1 ou élément de navigation n’est
modifié par cette phase.

### Chaîne favicon Drupal/Core/Barrio

Les versions verrouillées sont Drupal Core 11.3.3 et Bootstrap Barrio 5.5.20.
L’audit du code correspondant établit la chaîne suivante :

1. `ThemeSettingsProvider` charge les réglages globaux et ceux du thème actif.
2. Avec `favicon.use_default: true`, il cherche
   `<chemin-du-thème>/favicon.ico`.
3. Avant cette phase, ce fichier était absent : Core sélectionnait donc
   `core/misc/favicon.ico`, l’identité Drupal générique.
4. `BareHtmlPageRenderer::systemPageAttachments()` ajoute un unique
   `html_head_link` avec `rel="icon"` et le type
   `image/vnd.microsoft.icon`.
5. Barrio conserve `head_placeholder`, `css_placeholder` et
   `js_placeholder` dans son `html.html.twig`. Le thème Uni-Songes ne surcharge
   pas ce template. Son preprocess HTML ajoute des classes et, sur certaines
   pages de compte, un attachement script nommé ; il ne remplace pas le head.

La présence du nouvel ICO à la racine du thème fait donc pointer l’unique lien
géré par Core vers son URL relative au base path —
`/themes/custom/unisonges_theme/favicon.ico` lorsque Drupal est monté à la
racine. Aucun override Twig, hook head ou réglage YAML supplémentaire n’est
nécessaire. La copie au document root n’ajoute aucune balise : elle est destinée
aux clients qui demandent directement `/favicon.ico`. Les deux fichiers
versionnés contiennent exactement les mêmes octets ; ils ne constituent pas
deux familles contradictoires.

Le `.htaccess` scaffoldé exclut explicitement `/favicon.ico` de la réécriture
vers `index.php`. Le fichier physique au document root est donc requis pour
supprimer la 404 de filesystem confirmée. Le comportement HTTP réel reste à
valider après déploiement.

Le thème Gin, sa configuration et ses assets ne changent pas. L’intégration
Core reste intacte pour les routes d’administration et de connexion.

## Dérivation déterministe

### Résultat livré

L’ICO contient trois images DIB/BGRA 32 bits avec alpha :

| Taille | Format interne | Usage |
|---:|---|---|
| 16 × 16 | DIB 32 bpp + masque AND | onglet et favoris compacts |
| 32 × 32 | DIB 32 bpp + masque AND | onglet HiDPI et raccourcis |
| 48 × 48 | DIB 32 bpp + masque AND | raccourcis et fallback haute densité |

Empreinte attendue des deux copies :

```text
f5afb9d46a4c95190806cec55d53e8598ac7dc5812abaca9563eff1f27056893
```

Le canevas original est réduit avec `fit: contain`, centrage géométrique,
fond RGBA transparent et noyau Lanczos 3. Le ratio est conservé ; aucun crop,
étirement, filtre d’accentuation, ombre, gradient, fonte ou ajout graphique
n’est appliqué. Le contour brun reste présent sur chrome clair et les surfaces
orange/ivoire restent visibles sur chrome sombre.

Aucun favicon SVG n’est créé : il n’existe pas de source vectorielle approuvée.
Aucune icône Apple touch ni manifeste PWA n’est ajouté : l’architecture PWA
n’existe pas et ces éléments ne sont pas requis pour corriger le symptôme.

### Recette exacte de régénération

La génération de référence a utilisé Node.js 24.20.0, `sharp@0.34.3` et libvips
8.17.1. Elle écrit d’abord dans un répertoire temporaire et ne remplace aucun
asset source :

```bash
favicon_build_dir="$(mktemp -d)"
npm install --prefix "$favicon_build_dir" --no-save --no-package-lock \
  sharp@0.34.3

FAVICON_SOURCE="drupal/web/themes/custom/unisonges_theme/images/mark-2026-03-01-215937.png" \
FAVICON_OUTPUT="$favicon_build_dir/favicon.ico" \
NODE_PATH="$favicon_build_dir/node_modules" \
node <<'NODE'
const fs = require('fs');
const sharp = require('sharp');

sharp.cache(false);
sharp.concurrency(1);

const source = process.env.FAVICON_SOURCE;
const output = process.env.FAVICON_OUTPUT;
const sizes = [16, 32, 48];

function dibFrame(width, height, rgba) {
  const xorStride = width * 4;
  const andStride = Math.ceil(width / 32) * 4;
  const dib = Buffer.alloc(40 + xorStride * height + andStride * height);
  dib.writeUInt32LE(40, 0);
  dib.writeInt32LE(width, 4);
  dib.writeInt32LE(height * 2, 8);
  dib.writeUInt16LE(1, 12);
  dib.writeUInt16LE(32, 14);
  dib.writeUInt32LE(0, 16);
  dib.writeUInt32LE(xorStride * height + andStride * height, 20);
  dib.writeInt32LE(0, 24);
  dib.writeInt32LE(0, 28);
  dib.writeUInt32LE(0, 32);
  dib.writeUInt32LE(0, 36);

  const xorOffset = 40;
  const andOffset = xorOffset + xorStride * height;
  for (let dstY = 0; dstY < height; dstY++) {
    const srcY = height - 1 - dstY;
    for (let x = 0; x < width; x++) {
      const src = (srcY * width + x) * 4;
      const dst = xorOffset + dstY * xorStride + x * 4;
      const red = rgba[src];
      const green = rgba[src + 1];
      const blue = rgba[src + 2];
      const alpha = rgba[src + 3];
      dib[dst] = blue;
      dib[dst + 1] = green;
      dib[dst + 2] = red;
      dib[dst + 3] = alpha;
      if (alpha === 0) {
        dib[andOffset + dstY * andStride + Math.floor(x / 8)] |=
          0x80 >> (x % 8);
      }
    }
  }
  return dib;
}

(async () => {
  const frames = [];
  for (const size of sizes) {
    const {data, info} = await sharp(source, {limitInputPixels: false})
      .ensureAlpha()
      .resize(size, size, {
        fit: 'contain',
        position: 'centre',
        background: {r: 0, g: 0, b: 0, alpha: 0},
        kernel: sharp.kernel.lanczos3,
        fastShrinkOnLoad: false,
      })
      .raw()
      .toBuffer({resolveWithObject: true});
    if (info.width !== size || info.height !== size || info.channels !== 4) {
      throw new Error(`Unexpected render metadata: ${JSON.stringify(info)}`);
    }
    frames.push(dibFrame(size, size, data));
  }

  const directorySize = 6 + frames.length * 16;
  const header = Buffer.alloc(directorySize);
  header.writeUInt16LE(0, 0);
  header.writeUInt16LE(1, 2);
  header.writeUInt16LE(frames.length, 4);
  let offset = directorySize;
  frames.forEach((frame, index) => {
    const size = sizes[index];
    const entry = 6 + index * 16;
    header[entry] = size;
    header[entry + 1] = size;
    header[entry + 2] = 0;
    header[entry + 3] = 0;
    header.writeUInt16LE(1, entry + 4);
    header.writeUInt16LE(32, entry + 6);
    header.writeUInt32LE(frame.length, entry + 8);
    header.writeUInt32LE(offset, entry + 12);
    offset += frame.length;
  });

  fs.writeFileSync(output, Buffer.concat([header, ...frames]), {mode: 0o644});
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
NODE

cmp "$favicon_build_dir/favicon.ico" drupal/web/favicon.ico
cmp drupal/web/favicon.ico \
  drupal/web/themes/custom/unisonges_theme/favicon.ico
sha256sum "$favicon_build_dir/favicon.ico" drupal/web/favicon.ico \
  drupal/web/themes/custom/unisonges_theme/favicon.ico
```

Deux exécutions indépendantes de cette recette doivent être identiques avec
`cmp` et produire l’empreinte attendue.

## Validation statique

Les validations finales couvrent :

- parsing du PNG source, dimensions, alpha et bbox visible ;
- parsing strict de l’en-tête ICO, des trois entrées, des offsets, des tailles,
  des BITMAPINFOHEADER, des masques alpha/AND et de la fin de fichier ;
- présence de 16 × 16, 32 × 32 et 48 × 48 en BGRA 32 bits ;
- comparaison bit-à-bit des deux copies et d’une régénération indépendante ;
- absence de référence externe, SVG, script, `foreignObject`, manifeste ou
  nouvelle balise head dans les fichiers livrés ;
- existence du chemin de thème détecté par Core et du chemin physique racine ;
- absence de déclaration favicon manuelle concurrente dans le dépôt ;
- conservation des placeholders head de Barrio, sans override
  `html.html.twig` ;
- empreinte du PNG source identique à celle de `origin/release/prod` ;
- YAML audités lisibles, aucun YAML/Twig modifié ;
- UTF-8/NFC, `git diff --check` sur le fichier texte, garde exacte des fichiers,
  garde de chevauchement des PR ouvertes et scan de secrets ;
- revues indépendantes de l’intégrité de marque, de la compatibilité navigateur
  et de l’accessibilité.

Résultats enregistrés :

| Contrôle | Résultat statique |
|---|---|
| PNG source | 2479 × 2039, RGBA8, bbox visible 2342 × 1845, chunks `IHDR`/`IDAT`/`IEND` |
| Source contre `origin/release/prod` | SHA-256 identique `e707617d…aee72`, aucun diff du mark, du lien ou du header |
| Structure ICO | 15 086 octets, trois frames DIB 32 bpp 16/32/48, offsets contigus, alpha et masques AND cohérents |
| Deux destinations | `cmp = 0`, SHA-256 commun `f5afb9d…6893`, MIME local `image/vnd.microsoft.icon` |
| Régénération indépendante | `cmp = 0`, même taille et même SHA-256 |
| Drupal/Barrio | Core 11.3.3 et Barrio 5.5.20 verrouillés ; fallback de thème et placeholders head confirmés |
| Déclarations concurrentes | aucune balise, hook favicon, URL externe ou manifeste concurrent |
| YAML/Twig | sept YAML audités parsés strictement ; aucun YAML ou Twig modifié |
| Texte | UTF-8 strict, NFC, LF final, aucun CR |
| PR ouvertes | 17 PR et trois chemins comparés, zéro chevauchement |
| Secrets | aucun motif de secret à forte confiance dans le payload exact |
| Revues indépendantes | intégrité de marque PASS ; compatibilité navigateur/Drupal PASS ; accessibilité/périmètre PASS |

Le `.gitattributes` scaffoldé de Drupal force historiquement les ICO en diff
texte. Il reste volontairement inchangé pour ne pas être réécrit par Composer.
La commande suivante passe sur tout le contenu textuel de la phase :

```bash
git diff --cached --check -- . \
  ':(exclude)drupal/web/favicon.ico' \
  ':(exclude)drupal/web/themes/custom/unisonges_theme/favicon.ico'
```

Les deux exclusions binaires font l’objet du parseur ICO strict, de `cmp` et de
la régénération byte-for-byte ci-dessus ; une recherche d’espaces textuels dans
leurs octets de pixels n’aurait pas de sens.

Pendant la phase statique initiale, aucune commande DDEV, Docker, Drush ou
Chromium n’a été exécutée et aucun VPS n’a été contacté. Ces preuves historiques
sont conservées sans nouvelle génération ; le contrôle runtime ultérieur est
consigné séparément ci-dessous. Aucun staging ou déploiement n’a été effectué.

## Matrice runtime ciblée — 7 septembre 2026

La nouvelle autorisation explicite transférait temporairement le runtime à
#106, après restauration et arrêt par #94. Elle remplace, pour ce contrôle
seulement, l’interdiction de démarrage de la reprise statique précédente.

| Contrôle | Résultat observé | État |
|---|---|---|
| `GET /favicon.ico` | 200, `image/x-icon`, 15 086 octets, SHA-256 attendu | PASS local |
| `GET /themes/custom/unisonges_theme/favicon.ico` | 200, `image/x-icon`, corps identique au fichier racine et au commit validé | PASS local |
| Public `/` et connexion anonyme `/user/login` | un seul `rel="icon"` natif, type déclaré `image/vnd.microsoft.icon`, URL du thème accessible ; autres attachements conservés | PASS |
| Administration authentifiée `/admin` | thème effectivement Claro ; un seul favicon natif `/core/misc/favicon.ico`, 200, inchangé par rapport au témoin | PASS Claro uniquement |
| Cache | passage #106 : pages publique/connexion `X-Drupal-Cache: MISS` puis `HIT` ; favicon public réellement reçu hors cache puis depuis le cache disque | PASS |
| Réseau et journaux du passage final | une seule requête favicon native par page/passage, aucune déclaration concurrente, 404 favicon, erreur PHP ou exception JavaScript consignée | PASS |
| Interface d’onglet desktop/mobile, claire/sombre | Chromium headless ne donne pas accès au chrome de la fenêtre | observation manuelle restante |

La connexion et l’administration réutilisaient déjà une icône en cache lors
de leur premier passage : il ne s’agit pas de trois caches favicon froids
indépendants. L’administration est normalement non cacheable côté Drupal.
Les quatre GET explicites des deux fichiers confirment tous le même corps :

```text
f5afb9d46a4c95190806cec55d53e8598ac7dc5812abaca9563eff1f27056893
```

L’agrégation CSS et JS est restée activée ; aucun passage agrégation off n’est
revendiqué. Gin n’était pas le thème actif de cet environnement et n’a pas été
testé. Aucun favicon public ne lui est imposé. Le module éditorial n’était pas
activé : la meta robots conditionnelle de #103 reste vérifiée par lecture du
source fusionné, sans prétendre l’avoir exercée. Aucun changement non fusionné
de #94 n’a été repris.

Une capture du contenu de page ne prouve pas le favicon de l’onglet. Ici les
preuves viennent du DOM et du réseau d’un vrai navigateur, sans observation
visuelle de sa barre d’onglets et sans nouvelle infrastructure. Cette dernière
observation reste manuelle. Les réponses locales ne prouvent pas le HTTP
public après déploiement : statut/MIME/hash et journaux Nginx de production
resteront à contrôler dans le cadre autorisé du déploiement, sans VPS ici.

## Reprise statique du 7 septembre 2026

La référence distante `release/prod`, vérifiée après fetch ciblé et par l’API
GitHub de la branche, vaut `9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c`.
Elle inclut #103 et #104. #94 reste ouverte en brouillon au SHA
`3cebbbf36ebd26ef5a5d0e04e26b1e0f06a6bfd5`. Le worktree de #106 était propre,
sur `codex-add-favicon-site-identity`, au commit initial validé
`74948fabd749ce751d941c08e1d0bc898599fb21`, également présent sur GitHub.
L’audit du 2 septembre ci-dessus reste une preuve historique.

- Les deux ICO locaux ont été comparés avec `cmp`, entre eux, au commit initial
  et à la branche distante. Leur SHA-256 complet reste
  `f5afb9d46a4c95190806cec55d53e8598ac7dc5812abaca9563eff1f27056893`.
  Les preuves de génération et de parsing 16/32/48 sont réutilisées, sans
  nouvelle génération, téléchargement d’asset ou installation.
- Le PNG source conserve son SHA-256
  `e707617df3ec97c9e4320793714cd7dc798fead9ee8779eed8bc9288587aee72`.
  Le lien `mark-latest.png` et le template du header sont identiques au commit
  validé et à la base actuelle.
- `composer.json`, `composer.lock`, les réglages favicon et les mécanismes du
  thème concernés sont inchangés. Les références verrouillées Core 11.3.3 et
  Barrio 5.5.20 restent celles de l’audit initial : la sélection native du
  favicon du thème et la conservation du head restent applicables.
- #103 ajoute dans `EditorialHomeBuilder.php` un attachement `html_head`
  `unisonges_editorial_home_state_robots` (`noindex,follow`) pour certains états
  filtrés ou non canoniques. Il ne déclare aucune icône. Le script de fermeture
  des messages du thème sur les pages de compte reste inchangé. Aucun de ces
  attachements ne doit être remplacé pour intégrer le favicon.
- Les fichiers et le contrat de #94 concernent le `main`, le footer et la
  région de footer dans le scrollframe. Ils ne modifient pas `html.html.twig`
  ni le mécanisme favicon ; aucun attachement head supplémentaire n’est requis.
  La recherche ciblée dans les réglages Metatag n’a trouvé aucune icône
  concurrente.
- Le mapping Scaffold de Core dans le lock ne contient aucun des deux chemins
  ICO. Le projet ne définit que `web-root: web/` pour Scaffold. Le script
  `deploy-staging.sh` conserve la mise à jour Git puis Composer ; aucune règle
  suivie de copie, suppression ou remplacement ne cible ces icônes. Aucune
  modification Scaffold supplémentaire n’est nécessaire sur cette base. Cette
  conclusion ne décrit pas d’éventuels réglages locaux ou serveur non suivis.

Les changements de base ne recoupent aucun des trois fichiers de #106 : aucun
rebase ni force-push n’est nécessaire. Seule cette documentation est actualisée
pour la coordination et le contrôle réel restant. Les contrôles de reprise
restent ciblés : comparaison binaire et hashes, références de thème/head,
Scaffold suivi, diff exact, UTF-8/NFC, espaces du texte et motifs de secrets
dans le seul contenu de #106. Aucun secret ni chantier Composer de #82 n’est
consulté ; aucun autre worktree n’est utilisé pendant cette reprise statique.

## Exécution réelle, preuves et restitution

### Source et préparation

Le contrôle a servi la base fusionnée `release/prod`
`9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c` depuis le checkout servant propre
`/workspaces/Uni-Songes`, avec uniquement les deux ICO copiés depuis le HEAD
#106 `56c7afd40408af07789a50173e464912836c3d94`. Aucun changement de branche ou
rebase n’était nécessaire. Le code applicatif testé est donc celui de la base
actuelle, avec les octets exacts de #106 ; ni #82 ni les preuves #113 n’ont été
utilisés. Le complément documentaire ne change pas ces entrées runtime.

- DDEV était arrêté, sans processus Drush/navigateur actif ni autre propriétaire
  runtime constaté ; un verrou exclusif temporaire a été tenu pendant le test.
- Les fichiers publics, réglages DDEV et fichiers settings ont été sauvegardés
  sans afficher leur contenu ; manifeste des chemins, modes, propriétaires et
  hashes pertinents avant toute mutation. Les deux chemins ICO étaient absents
  du checkout servant initial.
- Démarrage avec `ddev start --skip-hooks`, puis sauvegarde fraîche
  `ddev snapshot --name pr106-favicon-before-20260907-2001 --skip-hooks`
  avant l’activation du thème et les connexions de test.
- Configuration initiale réelle : Olivero public, Claro administration, front
  `/node`, zéro node et zéro fichier géré. Activation temporaire du thème
  Uni-Songes et de sa base Barrio, avec `favicon.use_default=true` et feature
  favicon active. Claro est resté inchangé. Aucun contenu, utilisateur ou bloc
  créé ; seul le compte administrateur existant a servi au contrôle authentifié.
- Aucun import de configuration, Composer, téléchargement d’asset, installation
  d’outil, génération graphique, paiement, appel Google/email ou VPS demandé.

Le premier témoin a déclenché le cron automatique existant. Le zéro affiché
par Drush était son override CLI (`DrupalBoot8.php`), pas la configuration web
stockée à 10 800 secondes. Des avertissements de verrou cron, non PHP, sont
apparus à cette préparation ; le journal confirme la synchronisation Google
désactivée. Une garde temporaire de `system.cron_last` a empêché sa relance.
Cette garde et toutes les conséquences en base ont ensuite été annulées par
restauration du snapshot. Aucun cron n’a été lancé explicitement.

### Navigateur et comparaison

Origine locale : `http://127.0.0.1:8080`. Chromium déjà installé
`140.0.7339.16`, CDP, JavaScript activé, viewport 1440 × 1000. Témoin réussi
20:12:18–20:12:28 UTC ; passage #106 20:13:23–20:13:34 UTC le 7 septembre 2026.
Le pilote initial avec interception Fetch expirait ; le passage probant utilise
le navigateur sans cette interception, avec `--disable-dev-shm-usage` et
résolution DNS externe bloquée. Aucune réponse externe n’est enregistrée.
Il ne s’agit pas d’un correctif des scripts du site.

Le témoin et le passage final utilisent tous deux Uni-Songes/Claro ; seule la
présence des deux ICO change, suivie de `ddev drush cache:rebuild`. Comparaison
des six heads DOM correspondants : tous les éléments restent identiques après
exclusion du seul lien favicon et normalisation étroite du suffixe CSS de cache
`?tl0fri` → `?tl0g5p`, également présent dans deux `noscript` Claro. Les liens
Core, métadonnées, scripts, styles et placeholders résolus sont conservés.
Le script de connexion `unisongesAuthMessageDismiss` garde son SHA-256
`0751b7f2669d33c8126f403deba7b8b70dc532e60ea358e7e1d4e87b40c80b4c`.
Titres et H1 identiques entre témoin et test ; cette base minimale ne rendait
pas de H1 sur public/connexion, sans changement attribuable aux ICO.

L’identité Claro est aussi confirmée par `drupalSettings.ajaxPageState.theme`.
Ce champ n’est pas exposé sur public/connexion ; leurs assets, le script de
connexion et les réglages actifs corroborent le rendu Uni-Songes. La revue
indépendante des deux captures DOM/réseau confirme les six comparaisons :
290 réponses enregistrées au total, 245 HTTP 200 et 45 HTTP 304, aucune erreur
HTTP ni exception JavaScript. Les GET explicites d’ICO sont consignés à part.
Sur le passage final, les seules nouvelles entrées watchdog sont deux notices
de connexion ; zéro nouvelle erreur PHP ou warning. Les journaux PHP-FPM et
Nginx dédiés sont vides, sans erreur dans le journal conteneur de ce passage.

Les quatre corps HTTP sauvegardés ont le hash attendu et une structure ICO
valide, DIB/BGRA32 16/32/48. Les preuves de génération initiales sont réutilisées.
Le PNG source garde `e707617df3ec97c9e4320793714cd7dc798fead9ee8779eed8bc9288587aee72` ;
le lien `mark-latest.png` est inchangé et le template de header garde
`4810c8117e34a9c59bce47a30a6993033db617d61d4306d7d85f20673f36099b`.

### Preuves locales et nettoyage

Les preuves ciblées sont conservées hors Git dans le répertoire privé
`/tmp/pr106-favicon-runtime.KY90e2`, séparé des preuves #113. Les sauvegardes
privées ne doivent pas être jointes à la PR. Principales empreintes SHA-256 :

| Preuve | SHA-256 |
|---|---|
| `baseline-browser.json` | `13c2803da1a7adc0836fc1b26a43ea05985122b0c4e46ac22ac3ddeb7cc006d6` |
| `icons-browser.json` | `fad09a0cf477805c7247d436a7a0262bafb08f839459937632e1c41c04f7f7db` |
| `validation-summary.json` | `d2c870f6159276f4b2dbdbb129c716078ad0a2227c4e59fd64123166aacfad65` |
| État runtime initial et restauré, `cmp = 0` | `64ed907aa79897ccfeb230604114ef09c53a72e92ee06348c82985973c5a14af` |
| Manifeste fichiers initial et final, `cmp = 0` | `3a30a6c51eaf95a39aaf1f0214e5d8ca886a7ed25fd3eed6ef3b00d382002147` |

La commande `ddev snapshot restore pr106-favicon-before-20260907-2001
--skip-hooks` a restauré la base. Le relevé retrouve exactement Olivero/Claro,
les réglages et compteurs initiaux (0 node, 7 lignes users, 0 fichier géré,
8 sessions, dernier watchdog 150), ainsi que l’état cron initial. Les deux ICO
temporaires et les seuls répertoires de cache générés (`css`, `js`, `php/twig`)
ont été déplacés vers les preuves privées, sans suppression de fichier public
préexistant. Les profils navigateur et le lien de connexion de test ont été
retirés. Source, header, pages, fichiers publics, settings et Composer sont
identiques au manifeste initial, modes et propriétaires compris.

`ddev stop --skip-hooks` est terminé à 20:17 UTC ; statut `stopped`, aucun
conteneur web/db ni navigateur de test actif, verrou runtime libéré.
Le checkout servant est propre, toujours sur `release/prod` au SHA initial.
L’environnement est explicitement rendu disponible, sans redémarrage prévu.
Seule cette documentation est actualisée dans la branche #106 ; les deux ICO
restent identiques au commit initial validé. Aucun merge.

Contrôles finaux ciblés : garde exacte des trois fichiers, 22 PR ouvertes
comparées par noms de fichiers sans chevauchement, hashes ICO/source/header,
UTF-8/NFC, `git diff --check` textuel et recherche de motifs de secrets dans
le seul contenu documentaire — PASS. Aucun YAML ou Twig modifié ; les preuves
de parsing et de génération inchangées ne sont pas rejouées inutilement.
