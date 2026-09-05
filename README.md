# Backend OVH — Régie du Seuil 0.14.8

La 0.14.8 annonce le MSIX `3.1.8` comme unique version applicative autorisée ; toute version antérieure ou supérieure non annoncée reçoit `426 client_update_required`. Elle porte le schéma de session 15 et la migration MySQL 017, qui initialise le journal privé et borné des actions joueur. `token.attack` contrôle toujours côté serveur le propriétaire de l’attaquant, la visibilité de la cible, la formule, la statistique, les modificateurs et l’armure. `token.attack.oppose` réserve la réponse au contrôleur courant de la cible ou au MJ, compare les degrés critiques puis le résultat effectif et ne lance les dégâts qu’après une opposition perdue. En combat, les PV sont modifiés dans la transaction ; hors combat, aucun PV ne change avant `token.attack.resolve` et son `confirmed=true` explicite. Les répétitions sont idempotentes et deux réponses concurrentes ne peuvent pas appliquer deux fois une attaque. Discord reçoit les résultats résolus avec de vraies lignes, mais jamais l’armure chiffrée, les dégâts finaux ou les PV. Les vues Joueur ne reçoivent ni le journal MJ ni l’armure des adversaires. Le masque de collision autoritaire, la vision avec corps de mur visible, le recul après contact, les masques à 512 cellules, le relais SSE mutualisé et la fusion atomique des domaines restent inchangés.

Chaque nouvelle génération backend ouvre une fenêtre de transfert de trente secondes pour les sessions déjà actives. Leur flux SSE demande au MJ ou au joueur de synchroniser ses données puis de se déconnecter ; après cette fenêtre, les sessions de l’ancienne génération sont supprimées. Une nouvelle connexion reste soumise immédiatement à la version applicative exacte annoncée.

Elle conserve les calques Stream de PV des personnages joueurs ajoutés en 0.12.9 : chaque URL-capacité reste fixe jusqu’à sa régénération manuelle, ne révèle que le nom, les PV et l’état calculé, et cesse de répondre si la fiche n’est plus un personnage joueur. Elle conserve aussi le jet de **Chance** strictement borné à un unique `1d100` sans modificateur ni avantage/désavantage, les jets propriétaires avec ou sans token, le déplacement hors tour soumis à une autorisation MJ temporaire et la variante visuelle `elite` en cuivre.

Ce dépôt est l’autorité PHP/MySQL de l’application autonome « Xar Tsaroth — Régie du Seuil ». Il est distinct du site public `xar-tsaroth.fr` et se déploie uniquement depuis `https://github.com/Innotastream/regiexar-backend.git`, en HTTPS, sur `main`.

## Autorités

- Microsoft Store : programme Windows et mises à jour ;
- cette API : comptes, sessions, rôles, état partagé, réglages, scènes, fiches, playlists, médias et présences ;
- PC utilisateur : copies de fiches, préférences de chemins, choix graphique et coffre `safeStorage` facultatif.

API publique : `https://regie-xar-tsaroth.fr/api/v1`.

## Contrat 0.7 conservé

Le schéma 007 remplace l’état JSON monolithique par des documents révisionnés :

- `table`, index de scènes et roster ;
- métadonnées, map et initiative par scène ;
- un document par token et par fiche ;
- activité, audio, bibliothèque, Forge et instantané de présentation tactique séparés.

La première requête 0.7 transforme une seule fois l’ancien `application_state` 1.15 en domaines, sous transaction et verrou MySQL. L’ancien `PUT /api/v1/state` répond ensuite `426 domain_client_required` : il ne constitue plus une voie d’écriture.

Routes principales :

- `GET /api/v1/state` : vue complète pour un MJ, vue filtrée et ciblée sur la scène active pour un joueur ; `?since=N` renvoie un non-changement léger sans présence lorsque la révision est déjà connue ;
- `GET|PATCH /api/v1/state/domains` : deltas et écritures MJ avec révision optimiste par document ;
- `GET /api/v1/state/domains/history` et `POST .../history/restore` : historique/restauration administrateur ;
- `POST /api/v1/state/command` : commandes joueur ciblées sans reconstruction de l’état global ;
- `/api/v1/connections`, `/api/v1/events` et `/api/v1/events/stream` : présence et SSE authentifié ;
- `/api/v1/media` : médias privés, diffusion en flux et publication contrôlée ;
- `/api/v1/settings` et `/api/v1/bridge-settings` : réglages et secrets chiffrés ;
- `GET|HEAD /api/v1/integrations/discord` : statut `configuré/activé` sans URL pour une session MJ ; `POST` conserve l’envoi arbitré côté serveur.

## Studio d’images 0.9

Le schéma 008 ajoute :

- une session web limitée au studio, distincte de la session de partie et incapable d’accéder aux scènes, fiches ou réglages ;
- des conversations appartenant à un seul compte MJ ;
- un journal de demandes avec opérations `generate`, `edit` et `regenerate`, qualité `high` demandée (niveau appliqué laissé à Codex), références et états bornés ;
- un catalogue de références nominatives approuvées par l’administrateur ;
- une galerie où chaque MJ ne voit que ses images, tandis que l’administrateur voit l’historique complet ;
- une seule génération active par compte et le classement automatique d’une exécution interrompue après trente minutes.

Le schéma 009 ajoute :

- le choix explicite `local` ou `regie` sur chaque demande ;
- un identifiant de demande unique par MJ pour empêcher un doublon de quota après répétition réseau ;
- une file partagée FIFO, une seule prise active, un bail renouvelé par heartbeat et une seule reprise avant échec explicite ;
- une pause globale initialement active, modifiable uniquement par Innota administrateur ;
- l’annulation d’une demande en attente ou en cours, avec conservation du journal ;
- le transfert transactionnel du média calculé par le worker vers le compte MJ auteur.

Routes principales :

- `/api/v1/image-studio/auth/*` : session web MJ limitée ;
- `/api/v1/image-studio/conversations` et `.../{id}/messages` : conversations et journal ;
- `/api/v1/image-studio/messages/{id}/start|complete|fail` : transitions contrôlées par le processus local ;
- `/api/v1/image-studio/regie/status|access` : état partagé et pause/reprise propriétaire ;
- `/api/v1/image-studio/regie/worker/heartbeat` et `/regie/jobs/*` : présence minimale, prise sérialisée et clôture du worker privé ;
- `/api/v1/image-studio/gallery` et `/api/v1/image-studio/media/{id}` : collection privée filtrée côté serveur ;
- `/api/v1/image-studio/references` : catalogue partagé, modifiable uniquement par un administrateur.

« Retirer » masque une entrée au propriétaire sans supprimer l’audit administrateur. Un média encore attaché au résultat ou aux références d’un message conservé reste protégé par cet audit. Le fichier privé réellement orphelin, lorsqu’il n’est ni publié, ni utilisé par un domaine, ni actif dans le catalogue, entre dans la rétention média de trente jours. Une image ne devient publique qu’après l’action distincte de publication.

Le heartbeat transporte uniquement le booléen `ready`, un bail aléatoire de processus et, pendant une exécution, la référence de la demande déjà connue du backend, avec la session MJ d’Innota. Le bail n’identifie ni un PC ni un utilisateur : il sert uniquement à clôturer l’ancien processus lors d’un relais et disparaît avec lui. Aucun identifiant matériel, cookie ChatGPT, mot de passe, fichier `auth.json`, jeton Codex ou secret OpenAI n’est accepté ni stocké. Mettre l’accès en pause refuse les nouvelles demandes et les nouvelles prises, mais ne détruit pas le travail déjà lancé.

Chaque écriture modifie uniquement les domaines concernés, incrémente l’horloge globale et conserve l’ancienne valeur dans l’historique. Une collision de révision renvoie `409 domain_revision_conflict`. Les deltas sont conservés sur 2 000 révisions et l’historique trente jours.

Plusieurs MJ utilisent des comptes et sessions distincts. Le verrou transactionnel de l’horloge sérialise brièvement les validations MySQL, puis la révision attendue de chaque document empêche tout écrasement silencieux. Deux modifications de domaines différents sont acceptées indépendamment ; deux modifications du même document déclenchent le rebasage client. La contrainte de session unique s’applique par compte, jamais globalement à tous les MJ.

Les domaines sont bornés avant écriture : profondeur et nombre de nœuds, longueur des chaînes, 256 scènes, 2 000 tokens par scène, 1 000 fiches, 300 minuteurs et collections secondaires limitées. Les nombres, identifiants, textes, booléens, statistiques, difficultés de Touché, capacités et visibilités des tokens et jets sont également validés avant mutation. Une commande qui atteindrait une limite est refusée explicitement sans évincer silencieusement une donnée existante.

## Médias

Les images persistantes sont des références `/media/{id}` ; les `data:image/...` sont refusés dans les domaines. L’audio est reçu et retransmis en flux. Le quota actif par défaut est de 20 Gio ; le plafond physique incluant la rétention est de 25 Gio. Les deux contrôles sont revérifiés sous verrou au moment de l’insertion.

Une suppression retire immédiatement la publication mais conserve le fichier trente jours. Un média encore référencé par l’état courant ou l’historique n’est pas détruit. Une restauration de domaine réactive automatiquement les médias concernés.

## Configuration privée

Le fichier réel reste dans `regie-private/config.php`, à côté de la racine publique `regie/`. Il ne doit jamais entrer dans Git, un build, un journal, un skill ou une conversation.

La 0.7 exige une clé de chiffrement indépendante pour toute nouvelle écriture de secrets :

```php
'security' => [
    'settingsEncryptionKey' => '<32 octets encodés en base64 ou 64 caractères hexadécimaux>',
    'previousSettingsEncryptionKeys' => [],
],
'media' => [
    'maximumTotalBytes' => 20 * 1024 * 1024 * 1024,
    'maximumRetainedBytes' => 25 * 1024 * 1024 * 1024,
],
```

La lecture essaie la clé courante, jusqu’à quatre anciennes clés, puis l’ancien dérivé du mot de passe SQL uniquement pour migrer les valeurs existantes. La prochaine écriture rechiffre avec la clé indépendante. Sans clé indépendante valide, une écriture de réglages est refusée ; aucune nouvelle donnée n’est chiffrée avec le mot de passe SQL.

La politique de version de l’application n’est plus pilotée par le bloc privé `client`. Son autorité unique est `XAR_RELEASE_ANNOUNCEMENT_VERSION`, reflétée par `announcedApplicationVersion` dans le manifeste public du dépôt. La santé expose toujours `enforce=true`, `exactVersion=true` et la même version dans `minimumVersion` et `latestVersion`.

Règle de livraison permanente demandée par le propriétaire : tout MSIX remis à l'utilisateur devient immédiatement l'unique version exploitable en production, qu'il ait indiqué vouloir l'utiliser ou non. Avant cette remise, mettre `XAR_RELEASE_ANNOUNCEMENT_VERSION` et `announcedApplicationVersion` à la version applicative exacte, incrémenter la version backend, exécuter les contrôles, déployer le backend dans la même livraison et vérifier publiquement la matrice ancienne/exacte/future `426/401/426`. Cette annonce et son verrou exact sont effectués même si Partner Center ne contient pas encore le paquet. Le patch précédemment annoncé devient immédiatement interdit après le déploiement ; il est interdit de remettre un MSIX plus récent que la santé publique.

## Comptes et sessions

- Argon2id lorsqu’il est disponible, avec repli sur l’algorithme sûr de PHP ;
- un seul jeton de session actif par compte ;
- plusieurs comptes MJ actifs et visibles simultanément dans la présence ;
- transfert demandé à l’ancienne instance avant révocation ;
- lors d’un changement de version backend, transfert de sauvegarde demandé aux sessions actives puis révocation forcée après trente secondes ;
- niveau permanent et mode effectif séparés ;
- un MJ sans `can_administrate` ne peut pas gérer les comptes ;
- le dernier MJ et le dernier administrateur actifs sont protégés.

L’initialisation et la récupération reposent sur des jetons aléatoires, expirants et stockés uniquement sous forme SHA-256. Les pages `/initialisation` et `/recuperation` les lisent depuis le fragment d’URL puis l’effacent immédiatement.

## Déploiement et contrôles

Arborescence publique minimale :

```text
regie/
├── .htaccess
├── index.php
├── initialisation.php
├── recuperation.php
├── confidentialite.html
├── studio.php
└── api/v1/
    ├── index.php
    ├── online.php
    ├── domains.php
    └── image-studio.php
```

Avant déploiement : analyse syntaxique de toutes les entrées PHP publiques, tests de contrat statiques, contrôle qu’aucun secret n’est présent, puis vérification publique récente de `/api/v1` et `/api/v1/health`. Une analyse statique réussie ne remplace pas une suite fonctionnelle PHP/MySQL.
