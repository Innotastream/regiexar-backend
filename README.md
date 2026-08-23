# Backend OVH — Régie du Seuil 0.7.0

Ce dépôt est l’autorité PHP/MySQL de l’application autonome « Xar Tsaroth — Régie du Seuil ». Il est distinct du site public `xar-tsaroth.fr` et se déploie uniquement depuis `https://github.com/Innotastream/regiexar-backend.git`, en HTTPS, sur `main`.

## Autorités

- Microsoft Store : programme Windows et mises à jour ;
- cette API : comptes, sessions, rôles, état partagé, réglages, scènes, fiches, playlists, médias et présences ;
- PC utilisateur : copies de fiches, préférences de chemins, choix graphique et coffre `safeStorage` facultatif.

API publique : `https://regie-xar-tsaroth.fr/api/v1`.

## Contrat 0.7

Le schéma 007 remplace l’état JSON monolithique par des documents révisionnés :

- `table`, index de scènes et roster ;
- métadonnées, map et initiative par scène ;
- un document par token et par fiche ;
- activité, audio, bibliothèque, Forge et instantané de présentation tactique séparés.

La première requête 0.7 transforme une seule fois l’ancien `application_state` 1.15 en domaines, sous transaction et verrou MySQL. L’ancien `PUT /api/v1/state` répond ensuite `426 domain_client_required` : il ne constitue plus une voie d’écriture.

Routes principales :

- `GET /api/v1/state` : vue complète pour un MJ, vue filtrée et ciblée sur la scène active pour un joueur ;
- `GET|PATCH /api/v1/state/domains` : deltas et écritures MJ avec révision optimiste par document ;
- `GET /api/v1/state/domains/history` et `POST .../history/restore` : historique/restauration administrateur ;
- `POST /api/v1/state/command` : commandes joueur ciblées sans reconstruction de l’état global ;
- `/api/v1/connections`, `/api/v1/events` et `/api/v1/events/stream` : présence et SSE authentifié ;
- `/api/v1/media` : médias privés, diffusion en flux et publication contrôlée ;
- `/api/v1/settings` et `/api/v1/bridge-settings` : réglages et secrets chiffrés.

Chaque écriture modifie uniquement les domaines concernés, incrémente l’horloge globale et conserve l’ancienne valeur dans l’historique. Une collision de révision renvoie `409 domain_revision_conflict`. Les deltas sont conservés sur 2 000 révisions et l’historique trente jours.

Plusieurs MJ utilisent des comptes et sessions distincts. Le verrou transactionnel de l’horloge sérialise brièvement les validations MySQL, puis la révision attendue de chaque document empêche tout écrasement silencieux. Deux modifications de domaines différents sont acceptées indépendamment ; deux modifications du même document déclenchent le rebasage client. La contrainte de session unique s’applique par compte, jamais globalement à tous les MJ.

Les domaines sont bornés avant écriture : profondeur et nombre de nœuds, longueur des chaînes, 256 scènes, 2 000 tokens par scène, 1 000 fiches, 300 minuteurs et collections secondaires limitées. Une commande qui atteindrait une limite est refusée explicitement sans évincer silencieusement une donnée existante.

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
'client' => [
    'minimumVersion' => '1.16.0',
    'latestVersion' => '1.16.0',
    'enforce' => false,
],
```

La lecture essaie la clé courante, jusqu’à quatre anciennes clés, puis l’ancien dérivé du mot de passe SQL uniquement pour migrer les valeurs existantes. La prochaine écriture rechiffre avec la clé indépendante. Sans clé indépendante valide, une écriture de réglages est refusée ; aucune nouvelle donnée n’est chiffrée avec le mot de passe SQL.

`client.enforce` doit rester à `false` tant que la 1.16.0 n’est pas réellement installable depuis Microsoft Store.

## Comptes et sessions

- Argon2id lorsqu’il est disponible, avec repli sur l’algorithme sûr de PHP ;
- un seul jeton de session actif par compte ;
- plusieurs comptes MJ actifs et visibles simultanément dans la présence ;
- transfert demandé à l’ancienne instance avant révocation ;
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
└── api/v1/
    ├── index.php
    ├── online.php
    └── domains.php
```

Avant déploiement : analyse syntaxique des quatre entrées PHP publiques, tests de contrat 0.7, contrôle qu’aucun secret n’est présent, puis vérification publique récente de `/api/v1` et `/api/v1/health`. Une analyse PHP réussie ne remplace pas une suite fonctionnelle contre MySQL.
