# Backend OVH — autorité en ligne de la Régie

Ce dossier contient le serveur hébergé 0.6.5 de la Régie. MySQL fait autorité pour les comptes, droits, réglages, scènes, synchronisation, présences et références de médias. Le PC conserve uniquement les fiches personnelles et les préférences de dossiers qui n’ont de sens que sur cet ordinateur.

## Périmètre

- racine publique OVH : `regie` ;
- API : `https://regie-xar-tsaroth.fr/api/v1/` ;
- politique de confidentialité publique : `https://regie-xar-tsaroth.fr/confidentialite` ;
- test HTTPS + MySQL : `GET /api/v1/health` ;
- authentification : `POST /api/v1/auth/login`, `GET /api/v1/auth/me`, `POST /api/v1/auth/logout` ;
- changement de mot de passe : `POST /api/v1/auth/password` ;
- comptes : `GET`, `POST` et `PATCH /api/v1/accounts`, réservés à un administrateur connecté en mode MJ ;
- état partagé : `GET` et `PUT /api/v1/state`, commandes Joueur et jets de tokens validés par `POST /api/v1/state/command` ;
- présences réelles : `/api/v1/connections` et `/api/v1/events` ;
- réglages chiffrés : `/api/v1/settings` et `/api/v1/bridge-settings` ;
- médias privés : `/api/v1/media` ; publication d’image : `POST /api/v1/media/{id}/publish` ;
- galerie administrateur : `GET /api/v1/shared-media` ; page publique non indexée : `/share/{code}` ;
- création unique du premier compte MJ : `POST /api/v1/auth/bootstrap` ;
- formulaire non indexé : `/initialisation` ;
- configuration privée : dossier `regie-private`, placé à côté de `regie` et jamais associé à un domaine ;
- schéma initial idempotent : `migrations/001_initial_schema.sql` ;
- limitation persistante des tentatives : `migrations/002_auth_rate_limits.sql` ;
- jeton d’initialisation unique et expirant : `migrations/003_one_time_bootstrap.sql` ;
- migration d’autorité en ligne : `migrations/004_online_authority.sql`.
- session unique et transfert sauvegardé : `migrations/005_single_active_session.sql`.

## Arborescence OVH attendue

```text
racine SFTP
├── regie/
│   ├── .htaccess
│   ├── confidentialite.html
│   ├── index.php
│   └── api/v1/index.php
└── regie-private/
    └── config.php
```

Le dossier de travail garde le code web dans `public/`. Le dépôt Git de déploiement publie ce contenu directement à sa racine (`.htaccess`, `index.php`, `api/…`) afin que le déploiement OVH arrive immédiatement dans `regie/`, sans copie ni branche intermédiaire. Pour une installation manuelle, copier le contenu de `public/` dans `regie/`. Copier ensuite `private/config.example.php` vers `regie-private/config.php`, puis remplacer uniquement le marqueur du mot de passe sur l'ordinateur du propriétaire avant l'envoi SFTP.

Le fichier réel `regie-private/config.php` ne doit jamais entrer dans Git, un ZIP public, une capture, un journal ou une conversation.

## Schéma initial MySQL

La migration `migrations/001_initial_schema.sql` crée les tables cœur : comptes, sessions authentifiées, état applicatif, fiches et connexions réellement ouvertes. `migrations/002_auth_rate_limits.sql` ajoute seulement les compteurs temporaires anti-bruteforce. Les jetons de session sont stockés exclusivement sous forme de condensat SHA-256. Le niveau permanent reste dans `accounts.permanent_role` et le mode effectif de chaque connexion dans `auth_sessions.effective_mode`. Le backend refuse qu’une ancienne application remplace un état portant un schéma plus récent ; les commandes ciblées conservent le schéma courant.

Les migrations sont rejouables sans suppression métier ni écrasement. À partir du schéma 3 déjà initialisé, le backend 0.6.3 applique lui-même les versions 4 et 5 sous un verrou MySQL lors de sa première requête : aucune nouvelle manipulation phpMyAdmin n’est nécessaire. Les colonnes sont détectées dans `information_schema` avant leur création pour rester compatibles avec MySQL et reprendre un essai partiel. La migration 005 conserve la session historique la plus récente, pose l’index unique avant de retirer l’ancien index requis par la clé étrangère, puis impose l’unicité SQL par compte. Elles ne migrent aucune donnée locale et ne créent aucun compte.

## Initialisation du premier MJ

L’initialisation n’est possible que si `accounts` est vide et si la requête contient un code à usage unique encore valide. Seul son SHA-256 est placé temporairement dans `bootstrap_tokens` ; le code brut ne doit apparaître ni dans Git, ni dans la base, ni dans un journal. La page `/initialisation` reçoit ce code dans le fragment de l’adresse, le retire immédiatement de la barre puis envoie le formulaire en HTTPS. Dès que le premier compte existe, la route se verrouille définitivement et le jeton est marqué comme consommé.

Les nouveaux vérificateurs de mot de passe utilisent Argon2id lorsque PHP le fournit, avec repli sur l’algorithme sûr par défaut de PHP. Un changement de mot de passe augmente `auth_revision` et invalide toutes les sessions précédentes. Un compte MJ connecté en mode Joueur reçoit strictement le mode Joueur.

Un login acquiert un verrou MySQL propre au compte. Si une session existe, l’API lui signale le transfert et attend sa sauvegarde/déconnexion ; elle supprime ensuite tout ancien jeton et crée le nouveau dans la même transaction. Une contrainte unique sur `auth_sessions.account_id` interdit aussi tout doublon en cas de concurrence.

## Création des comptes joueurs

Une session ouverte explicitement en mode MJ et possédant `can_administrate` peut lister, créer et modifier les comptes. Un compte comme Goldark peut donc être MJ sans pouvoir administrer. Le serveur valide l’identifiant, le nom affiché, le niveau permanent et le mot de passe, calcule le vérificateur côté serveur et refuse les doublons. Toute modification invalide les anciennes sessions ; le dernier MJ et le dernier administrateur actifs sont protégés contre un verrouillage accidentel. Une session Joueur, y compris celle d’un compte de niveau MJ, ne peut jamais appeler ces routes.

## Déploiement Git OVH

Sur l'offre OVH Startup utilisée par la Régie, l'API refuse la création de variables d'environnement (`this account is not allowed to create EnvVar`). Le déploiement Git transporte donc uniquement le code public ; la configuration réelle doit rester dans `regie-private/config.php`, hors du dossier `regie` et hors de Git.

Les variables suivantes restent un mécanisme de compatibilité pour un hébergement qui les autoriserait ultérieurement :

- `XAR_REGIE_DB_DSN`, type `string` ;
- `XAR_REGIE_DB_USER`, type `string` ;
- `XAR_REGIE_DB_PASSWORD`, type `password`.

Le mot de passe doit être saisi directement dans OVH. Il ne doit jamais être copié dans Git, une commande partagée, une capture ou une conversation. Le fichier privé reste compatible et prioritaire s'il est installé plus tard.

## Résultats attendus

- `/` répond par une page neutre et ne liste aucun fichier ;
- `/confidentialite` répond par la politique publique de la Régie ;
- `/api/v1/health` répond `503 configuration_required` tant que la configuration privée ou les variables OVH manquent ;
- une fois la configuration installée, la même route répond `200` et `status: ok` si MySQL est joignable ;
- sans session, `/api/v1/auth/me` répond `401` sans révéler d’information de compte ;
- une connexion valide ne stocke en base que le SHA-256 du jeton aléatoire ;
- aucune erreur PDO, adresse MySQL ou valeur secrète n'est renvoyée au client.
