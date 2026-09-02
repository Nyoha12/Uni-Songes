# Identité navigateur et favicon — 2026

## Statut et décision

Cette phase est exclusivement statique. Elle fournit un favicon Uni-Songes
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

La PR doit rester en brouillon tant que la matrice HTTP et navigateur différée
n’a pas été exécutée.

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

Aucune commande DDEV, Docker, Drush ou Chromium n’a été exécutée et aucun VPS
n’a été contacté. Aucun staging ou déploiement n’a été effectué. Ces preuves
sont statiques : elles ne remplacent pas la validation différée ci-dessous.

## Matrice runtime différée

À exécuter après déploiement, avec un vrai navigateur et le serveur public. Les
quatre colonnes rendent explicites les combinaisons cache × agrégation :

| Contrôle | Froid / agg. off | Froid / agg. on | Chaud / agg. off | Chaud / agg. on | État |
|---|:---:|:---:|:---:|:---:|---|
| `GET /favicon.ico` retourne 200 | à faire | à faire | à faire | à faire | différé |
| `GET` de l’URL favicon du thème retourne 200 | à faire | à faire | à faire | à faire | différé |
| Content-Type correct pour les ICO | à faire | à faire | à faire | à faire | différé |
| Les deux URL exposent la même identité | à faire | à faire | à faire | à faire | différé |
| Page d’accueil anonyme | à faire | à faire | à faire | à faire | différé |
| Page ordinaire | à faire | à faire | à faire | à faire | différé |
| Page de connexion | à faire | à faire | à faire | à faire | différé |
| Page d’administration authentifiée sous Gin | à faire | à faire | à faire | à faire | différé |
| Onglet navigateur desktop | à faire | à faire | à faire | à faire | différé |
| Onglet navigateur mobile | à faire | à faire | à faire | à faire | différé |
| Aucun 404 Nginx pour le favicon | à faire | à faire | à faire | à faire | différé |
| Aucune requête d’icône dupliquée | à faire | à faire | à faire | à faire | différé |
| Aucun warning PHP | à faire | à faire | à faire | à faire | différé |
| Aucun recul des attachements `<head>` | à faire | à faire | à faire | à faire | différé |

Ne pas passer la PR en « ready » et ne pas la fusionner avant la réussite de
cette matrice.
