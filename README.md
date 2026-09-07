# Backend OVH — Régie du Seuil 0.15.3

La projection Joueur conserve maintenant la vision et le brouillard entre les niveaux de la même scène, y compris pour les cartes enregistrées par la 3.2.2. Un niveau sans observateur reste hors vision ; un niveau encore non protégé reçoit un masque complet si le brouillard est actif ailleurs. Les révélations explicitement préparées restent conservées. Le correctif complet de l’interface MJ et du Stream est embarqué dans la 3.2.3.

La **0.15.3 / client annoncé 3.2.3** est un candidat en cours de qualification. Build : `client-3-2-3-map-visibility-continuity-and-dual-login-release-20260907-2`. Le propriétaire autorise exceptionnellement les connexions des seules versions **3.2.2 et 3.2.3**. La dernière base de production contrôlée est 0.15.2 / 3.2.2 ; la santé publique a confirmé cette base avant le basculement.

Ce candidat ajoute les sources de lumière par scène et niveau : 40 au maximum, portée de 1 à 40 cases (8 par défaut), relais déclenché uniquement par la vision stricte, hors murs et brouillard. Les chaînes sont déterministes ; les vues publiques et les attaques utilisent le même calcul et ne révèlent aucune source hors vue. Le MJ peut traverser les murs pendant un placement ; la destination doit rester libre pour toute l’empreinte du pion ou du groupe. Le trajet Joueur demeure soumis aux collisions. La taille par défaut passe à 40, sans modifier les tailles explicites. Les schémas session **16**, fiche **4**, domaines **1** et MySQL **18** restent inchangés.

PHP est indisponible dans l’environnement de préparation. L’autorisation du propriétaire permet de publier les sources pour exécuter la syntaxe PHP réelle et les suites PHP dans le workflow `backend-check` du commit exact ; les tests Node ne les remplacent pas. Cette validation ne déploie pas OVH. La remise du MSIX exige sa qualification puis le contrôle public **3.2.1 / 3.2.2 / 3.2.3 / 3.2.4 = 426 / 401 / 401 / 426** après le déploiement demandé. Statut conservé : **livraison incomplète — alerte launcher non recettée**.

### Historique 0.15.1

La 0.15.1 conserve le niveau avec les coordonnées lors des éditions concurrentes et contrôle l’occupation et les bords pour les groupes. La 3.2.1 associée corrige les scènes neuves et les fermetures de dialogues lors d’une sélection de texte. Son workflow PHP est validé et sa production a été contrôlée avant la continuation.

### Historique 0.15.0

La 0.15.0 accompagne le client **3.2.0**, annoncé comme unique version autorisée. Les schémas session 16, fiche 4, domaines 1 et MySQL 18 sont conservés. La portée `visionDistance` est une statistique individuelle entière de 1 à 40 cases, à 8 par défaut. Une fiche autoritative la propage à ses pions dans toutes les scènes ; un clone ou une invocation garde sa portée propre. L'ancien réglage global ne remplace pas cette statistique. Le masque binaire reste seul arbitre de la visibilité des pions et des actions ; un champ `opacity` en base64url contient un octet par cellule pour assombrir progressivement le décor sur les quatre cases suivantes, sans traverser les murs ni révéler de pions. Le mode Stream de combat applique le POV du pion courant depuis la projection locale du MJ.

La commande MJ atomique `tokens.layers` accepte `{sceneId, targetLayerId, mapRevision, tokens:[{id,x,y,layerId}]}`. Elle fonctionne aussi carte déverrouillée et depuis un autre niveau, sans changer le niveau affiché. Elle vérifie toute la sélection, la révision de carte et les positions de départ, puis cherche des places libres sur le niveau de destination ; l'échec d'un seul pion refuse toute mutation. Un pion déjà à destination conserve ses valeurs. L'accusé contient la révision globale et les révisions de domaines des pions.

Les variations effectives de PV et les dégâts appliqués propagent le même `resourcePulse` à tous les pions synchronisés, avec son identifiant et son âge, en excluant les clones. Les signaux de carte transportent le niveau et leur heure de création ; une requête issue d'une ancienne scène ou d'un ancien niveau est refusée. La projection masque pions, signaux et dés des autres niveaux. La sélection collective des initiatives est filtrée au niveau actif par le client MJ, qui garde ses jets individuels explicites.

L'annonce de cette version dans les sources ne prouve pas un déploiement OVH. Le basculement et la remise du MSIX restent soumis à la qualification de l'artefact et à la matrice publique `ancienne/exacte/future = 426/401/426`. Statut conservé : **livraison incomplète — alerte launcher non recettée**.

### Historique 0.14.13

La 0.14.13 ajoute `tokens.transform` et `token.clone`, réservées au MJ. Un groupe exige une carte verrouillée, le niveau actif, la révision de la carte et les positions de départ de chaque pion ; la transaction calcule toute la formation avant toute écriture. Les murs de destination et le gabarit des pions déterminent les places libres ; un trajet arrêté est replacé au contact accessible du mur. Aucune position n’est écrite si un pion ne trouve pas de place. Les recherches sont bornées à 4 096 positions par tentative.

Les champs additifs `layerId`, `cloneSourceCharacterId` et `cloneSourceTokenId` conservent session 16, fiche 4, domaines 1 et MySQL 18. Les pions sans niveau sont rattachés au niveau précédent avant un changement de carte. Le niveau filtre les pions et leur vision pour les joueurs, l’aperçu et Streamlabs ; les déplacements et attaques entre niveaux sont refusés. Une sauvegarde de fiche, y compris par domaines MJ, propage ses valeurs à tous les pions synchronisés des scènes. Position et niveau restent propres aux scènes. Un clone possède un nouvel identifiant, copie les valeurs autoritatives courantes et conserve ensuite ses propres PV, effets et statistiques.

La 0.14.13 était une préparation de sources sans MSIX, publiée avec `[skip ci]` ; la production restait backend 0.14.12 / client exact 3.1.12. La demande suivante autorise la qualification et le lancement de la 3.2.0.

La 0.14.12 ajoute les effets ordinaires et personnalisés synchronisés entre fiche et tokens, avec deux commandes atomiques accessibles au propriétaire ou au MJ : `token.conditions.update` (`sceneId`, `tokenId`, `condition`, `active`) et `character.conditions.update` (`characterId`, `condition`, `active`). Un ajout/retrait réel produit une entrée privée MJ ; répéter la même demande ne change pas les données et ne double pas l’audit. Une sauvegarde de fiche qui remplace sa liste d’effets doit fournir `conditionsBase` pour une fusion à trois voies ; sans base, une liste différente est refusée afin de préserver les modifications concurrentes.

Les PV peuvent devenir négatifs, avec une borne technique de −1 000 000 000. Une créature meurt sous zéro ; un personnage joueur meurt strictement sous −25 % de ses PV maximum. À zéro et jusqu’à cette limite, il est KO. Les états de santé restent séparés des effets ordinaires ; l’ancien « Mort » manuel est conservé par `healthOverride`, réservé au MJ. Un attaquant KO ou mort ne peut pas attaquer ; une cible KO peut subir une attaque sans jet d’opposition, avec validation explicite MJ hors combat. Les dégâts après réduction sont appliqués intégralement, même au-delà des PV positifs, et seule la perte réellement appliquée devient publique. Les compensations conservent les actions ultérieures.

Les dés transportent seulement leur couleur et celle des chiffres : couleur de la fiche du joueur, apparence normale pour une créature, noir/blanc pour un boss. Chaque événement identifie sa scène, son attaque et son ancre près de l’attaquant. Les jets d’opposition gardent la couleur du défenseur. La projection exige la visibilité des deux pions et masque toujours les dégâts présumés. Les 24 effets classiques sont des marqueurs descriptifs, sans pénalité ou dégât périodique inventé.

La 0.14.13 annonce le MSIX `3.1.13` comme unique version applicative autorisée ; toute version antérieure ou supérieure non annoncée reçoit `426 client_update_required`. Les ajouts restent compatibles avec les schémas session 16, fiche 4, domaines 1 et MySQL 18 : aucune migration SQL 019. Les variations de PV et de mana acceptent une valeur fixe ou une formule de dés positive, tirée côté serveur et protégée pendant 24 h par un reçu lié au compte, dans un registre privé borné à 1 024 entrées et indépendant du journal visible. Chaque variation effective et chaque dégât d’attaque appliqué produit dans le journal privé MJ une opération compensable une seule fois ; `action.undo` applique atomiquement la variation inverse à la fiche et à tous ses tokens synchronisés, conserve les actions ultérieures et inscrit son propre audit. Un MJ peut en outre porter une attaque avec une créature visible non assignée vers un pion joueur visible, sans pouvoir usurper le pion d’un joueur ni frapper une autre créature par ce raccourci.

Les garanties de la 0.14.9 restent actives : `token.attack` revérifie côté serveur contrôleurs, cible, formule, statistique, modificateurs et armure ; les oppositions et reçus longs restent atomiques ; les dégâts hors combat exigent toujours `token.attack.resolve` avec `confirmed=true`. Discord reçoit seulement la perte réellement appliquée, jamais l’armure chiffrée, les dégâts présumés ou les PV restants, et les vues Joueur ne reçoivent ni le journal MJ ni l’armure des adversaires. Le masque de collision autoritaire, la vision avec corps de mur visible, le recul après contact, les masques à 512 cellules, le relais SSE mutualisé et la fusion atomique des domaines restent inchangés.

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

La politique de connexion est définie exclusivement par `XAR_RELEASE_ALLOWED_CLIENT_VERSIONS` et la version annoncée `XAR_RELEASE_ANNOUNCEMENT_VERSION`, toutes deux reflétées dans le manifeste. Le bloc privé `client` ne peut pas désactiver cette protection. L’exception explicite de cette livraison admet seulement `3.2.2` et `3.2.3`, avec `enforce=true`, `exactVersion=false`, `minimumVersion=3.2.2`, `latestVersion=3.2.3` et `allowedVersions=["3.2.2","3.2.3"]`. Ce n’est pas une plage ouverte : les versions antérieures, futures et suffixées restent refusées.

Cette exception remplace pour cette livraison le verrou habituel sur une version unique. Le launcher 3.2.2 peut se reconnecter et propose la mise à jour 3.2.3 ; ses anciens défauts locaux restent présents jusqu’à son remplacement. L’authentification, les droits et le renouvellement de génération des sessions demeurent obligatoires pour les deux versions.

Avant toute remise du MSIX, qualifier l’artefact original, déployer le backend demandé puis vérifier la santé et la matrice `3.2.1 / 3.2.2 / 3.2.3 / 3.2.4 = 426 / 401 / 401 / 426`. Il reste interdit de remettre un MSIX plus récent que la santé publique. La prochaine évolution de la politique doit redéfinir explicitement sa liste ; l’exception n’autorise aucune version supplémentaire.

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
