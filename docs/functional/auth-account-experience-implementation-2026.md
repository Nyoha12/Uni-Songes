# Présentation des parcours d’authentification et de compte — 2026

## Statut et périmètre

Cette implémentation transpose la direction visuelle approuvée dans la PR #96
sur les formulaires et pages Drupal réels. La branche a été rebasée sur
`origin/release/prod` au commit `48b9eb4`, qui contient la fusion de la PR #100
sur le cycle de vie des messages système.

Le diff reste limité à quatre fichiers :

- cette note fonctionnelle ;
- `css/auth-account.css`, l’unique feuille route-scopée ;
- `unisonges_theme.libraries.yml`, pour l’unique bibliothèque dédiée ;
- `unisonges_theme.theme`, pour les suggestions, classes et présentations de
  formulaires.

La bibliothèque `unisonges_theme/auth-account` est attachée uniquement à :

- `user.login`, `/user/login`, pour une personne anonyme ;
- `user.register`, `/user/register`, pour une personne anonyme ;
- `user.pass`, `/user/password`, dans ses variantes anonyme et authentifiée ;
- `user.reset.form`, l’entrée Core à usage unique ;
- `entity.user.canonical`, uniquement lorsque le profil affiché appartient à
  la personne connectée ;
- `entity.user.edit_form`, avec la même garde propriétaire, y compris après un
  jeton de réinitialisation déjà validé par Core.

Les profils d’autrui, l’administration d’un compte tiers et les routes
Commerce restent hors de ce scope. Aucune URL publique n’est créée ou modifiée.

## Contrats fonctionnels préservés

- La connexion continue d’utiliser exclusivement le nom d’utilisateur et le
  mot de passe du formulaire Core.
- L’inscription reste pilotée par `user.settings`, avec vérification email et
  sans champ mot de passe avant le lien à usage unique.
- Le vrai widget `managed_file` de l’image facultative est rendu sans
  reconstruction, avec Upload/Remove, AJAX et repli sans JavaScript.
- La demande de réinitialisation accepte toujours le nom d’utilisateur ou
  l’adresse email. Sa variante connectée conserve la valeur cachée et
  l’explication Core.
- L’entrée à usage unique, l’édition du mot de passe, l’expiration et le refus
  de réutilisation gardent les formulaires, jetons, validations, redirections
  et messages Core.
- Les liens secondaires conservent un éventuel `destination` local au moyen du
  service Core `redirect.destination`; une destination externe est donc
  neutralisée par Core. Les render arrays varient sur
  `url.query_args:destination`.
- Les champs contribués du profil et du compte restent produits par leurs
  render arrays Drupal. Les contrôles d’accès métier ne changent pas.
- Le render array de l’entité utilisateur et son template Core ne dépendent
  pas de la personne qui consulte la page; aucun fragment propriétaire n’entre
  dans le render cache de l’entité.
- Aucun fournisseur social, connexion par email, nouveau lien magique,
  statistique de compte ou contenu personnel fictif n’est ajouté.

Les alters de formulaires ajoutent seulement des classes, de courtes copies et
des liens vers des routes existantes. Ils n’ajoutent aucun submit, validateur ou
handler de formulaire Drupal.

## Intégration du cycle de vie des messages

La PR #99 conserve intégralement la PR #100 :

- `unisonges_theme_theme_suggestions_status_messages_alter()` reste défini une
  seule fois ;
- la suggestion `status_messages__unisonges_inline` reste active ;
- `status-messages--unisonges-inline.html.twig` reste byte-identique à la base
  (`SHA-256 bebd66b2dfec39369532ad9deed7704dfe763d34dc34e984c3bb8baf8f9103d2`) ;
- un seul bloc `.unisonges-system-messages[data-drupal-messages]` rend les
  messages serveur ou AJAX dans `main` ;
- aucun toast fixe, overlay, second chemin, message BigPipe tardif ou
  positionnement sur l’en-tête n’est réintroduit.

Sur les seules routes auth/compte, la feuille dédiée place visuellement le H1,
le chemin de messages puis le formulaire. L’erreur garde son texte Drupal, son
`role="alert"` et son vrai lien de réinitialisation. Elle reçoit une surface
rose chaude, un texte bordeaux sombre, une bordure de 1 px avec accent gauche
de 4 px, un rythme compact et un bouton de fermeture focalisable de 44 px. Les
variantes succès, avertissement et information conservent leurs rôles et
disposent de traitements distincts.

Le thème attache sur ces seules routes un gestionnaire DOM délégué et
idempotent à ce bouton existant. Il retire l’alerte serveur ou son équivalent
ajouté par `Drupal.Message`, sans créer de second chemin de messages ni modifier
leur cycle de vie côté Drupal.

## Présentation livrée

La coque et le fond publics existants sont conservés. Le scrollframe devient,
sur ces seules routes, une surface chaude opaque sans effet de verre, à ombre
faible et rayon mesuré. La feuille dédiée fournit :

- texte `#102033`, accent principal `#0f766e` et survol `#075b55` ;
- pile de police système lisible et H1 mesuré, produit une seule fois par le
  bloc de titre existant ;
- labels persistants, champs d’au moins 48 px et actions d’au moins 44 px ;
- focus clavier de 3 px, descriptions et erreurs contrastées ;
- largeur maximale de 34 rem pour login/reset, 40 rem pour l’inscription et
  44 rem pour le compte ;
- reflow à 320 px, champs mot de passe Barrio à 100 % et widget image capable
  de revenir à la ligne ;
- un seul conteneur de défilement vertical, le scrollframe existant ;
- règles explicites pour contraste forcé et mouvement réduit.

Les onglets anonymes dupliqués sont masqués au profit des destinations
contextuelles. Les tâches locales Core du compte restent les seules actions de
profil. Le point d’extension futur pour « Mes réservations / Mes commandes »
est documenté dans le helper de scope comme un bloc propriétaire, contrôlé par
accès et lazy-built; aucun panneau vide n’est rendu aujourd’hui.

## Validation DDEV, Mailpit et Chromium

### Préparation reproductible

Le runtime local a été exercé sans VPS, staging ni email externe. Avant toute
écriture, le snapshot DDEV nommé
`pr99-auth-account-pretest-20260901-2043` a été créé. Le checkout servant a été
avancé sur `release/prod` `48b9eb4`, puis la source rebasée de la PR #99 y a été
chargée en conservant les fichiers DDEV ignorés.

Versions observées : Drupal 11.3.3, PHP 8.3.31, Drush 13.7.1, DDEV 1.25.3,
Chromium 140 et Playwright 1.55.0.

État de référence enregistré :

| Élément                             | Référence                                                               |
| ----------------------------------- | ----------------------------------------------------------------------- |
| Dump SQL normalisé                  | `161ef10fa5a32b0075cc19c4abd9a3ec8b9d8e0039be392db83f676397134b4b`      |
| Configuration active                | `07ec23fcbcbab78e48b746283be7ffb12fda49b5c59264fdf0fea31e0ec32702`      |
| Fichiers publics, archive canonique | `fb1121f1100122f262f4e2910627a6241457b5abc157ef2cb96bee860a6da1ba`      |
| Utilisateurs                        | `374162b81e6886c2b4c86a4853ba116dcad9e591c984ad0ff07a6b66e9aa8623` (7)  |
| Alias                               | `d02e6fe25774d1f5e85f53b9bd31e7bade6f64613be4854c082ff1305d955f85` (16) |
| DDEV ignoré, archive canonique      | `d9b3649ca9472695a552afcea78463930072e5b22e87515d2456ab8ca35c0818`      |
| Thèmes / page d’accueil             | Olivero par défaut, Claro admin, `/node`                                |
| Checkout servant                    | `release/prod` `48b9eb4`                                                |

Le clone actif local avait `system.date:timezone.default = Etc/UTC`, valeur
refusée par la contrainte Drupal 11.3.3 lors de la création d’un utilisateur.
Le runtime jetable a été aligné sur la valeur déployable déjà versionnée dans
`config/sync/system.date.yml`, `Europe/Paris`, puis restauré par snapshot. Ce
constat n’entraîne aucun changement dans la PR #99.

Des comptes fixtures nommés `pr99.*` et des adresses exclusivement
`@example.test` ont été utilisés. Mailpit a été vidé entre les scénarios.

### Résultats fonctionnels

| Parcours              | Résultat                                                                                                                           |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Login invalide        | Un POST, une erreur et un `role="alert"`; aucune réapparition après 5 rechargements, `/accueil`, `/user/password` ou back/forward. |
| Login valide          | Nom d’utilisateur accepté, mot de passe requis, erreur obsolète absente et `destination=/reservation-cours` conservé.              |
| Inscription           | Email/nom réels, aucun mot de passe, email invalide natif, doublons Core, destination conservée.                                   |
| Image facultative     | Upload puis Remove réussis en AJAX; même séquence réussie sans JavaScript.                                                         |
| Vérification initiale | Un email Mailpit, lien unique valide, choix du mot de passe, login suivant par nom d’utilisateur, réutilisation refusée.           |
| Reset connu           | Message générique et exactement un email Mailpit.                                                                                  |
| Reset inconnu/inactif | Même gabarit de message, aucun email et aucune fuite d’existence ajoutée.                                                          |
| Reset connecté        | Aucun champ d’identité visible, adresse du compte expliquée, exactement un email Mailpit.                                          |
| Liens de reset        | Lien valide, expiration explicite et réutilisation refusée par Core.                                                               |
| Compte propriétaire   | `/user` vers `/user/7`, vrais champs et tâches locales, édition disponible, aucun faux tableau de bord.                            |
| Isolation             | Profil membre tiers et édition administrative d’un tiers sans classe ni bibliothèque propriétaire.                                 |

Le compte issu de l’inscription a reçu une seule notification locale. Son
image de profil avait le nom accessible « Profile picture for user
pr99.registered ». Aucun envoi externe n’a été effectué.

### Matrice navigateur

Chromium réel a validé :

- desktop 1440×900, tablette 768×1024, mobile tactile 390×844 et largeur
  320 px ;
- reflow effectif à 100 %, 150 % et 200 % ;
- clavier seul, souris, émulation tactile, menu mobile et en-tête fixe ;
- mouvement réduit et couleurs forcées, y compris la croix de fermeture ;
- un H1 visible, un `main`, au plus un chemin de messages, labels associés,
  focus visible, contrôles de 44 px minimum, aucune référence ARIA ou ID
  dupliqué ;
- aucun débordement horizontal, piège de scroll imbriqué, avertissement PHP,
  HTTP 5xx, erreur console ou `pageerror` attribuable à la PR #99 ;
- axe-core, scopé sur la surface auth/compte, sans violation sérieuse ou
  critique.

Captures produites localement : login propre, login invalide, inscription,
erreur d’inscription, demande de mot de passe, confirmation du lien, création
du mot de passe, profil propriétaire, erreur mobile, reflow 200 % et
observation forced-colors.

## Gardes statiques

Les contrôles finaux comprennent :

```bash
php -l drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme
npx --yes --package postcss@8.5.6 --package postcss-cli@11.0.1 \
  postcss drupal/web/themes/custom/unisonges_theme/css/auth-account.css \
  --output /dev/null
npx --yes prettier@3.6.2 --check \
  docs/functional/auth-account-experience-implementation-2026.md \
  drupal/web/themes/custom/unisonges_theme/css/auth-account.css \
  drupal/web/themes/custom/unisonges_theme/unisonges_theme.libraries.yml
git diff --check origin/release/prod --
```

Sont aussi vérifiés : parsing YAML, UTF-8/NFC strict, routes et IDs de formulaire
exacts, absence de nouveau submit/validateur/handler, unicité des hooks,
présence du hook PR #100, template de messages inchangé, périmètre exact de
quatre fichiers, absence de conflit avec une autre PR ouverte et scan de
secrets.

## Nettoyage, déploiement et retour arrière

Les quatre comptes fixtures et leurs cinq images ont été supprimés
explicitement, puis Mailpit a été vidé. Le snapshot nommé et la copie de
référence des fichiers publics ont été restaurés. Toutes les empreintes du
tableau ci-dessus ont été confirmées identiques, avec 7 utilisateurs, 16 alias,
Olivero par défaut, Claro en administration et `/node` en page d’accueil. Le
checkout servant est revenu proprement sur `release/prod` `48b9eb4` et les
services web, base de données et Mailpit DDEV sont arrêtés.

Aucune commande de staging ou de production n’a été exécutée. Après fusion, le
déploiement normal doit installer le commit depuis GitHub puis reconstruire le
cache Drupal selon la procédure approuvée; aucun import de configuration,
migration ou schéma n’est requis par ce diff.

Le retour arrière est le revert de la PR suivi d’une reconstruction du cache du
thème. La bibliothèque, les styles et les alters disparaissent ensemble; aucun
schéma, contenu, compte, message ou URL ne doit être restauré.
