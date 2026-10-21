# Atelier connecté 2.0.0

Backend 0.18.51, schéma 25. Le client Régie reste 3.7.6. Le nouvel Atelier Windows possède ses propres comptes et son propre cookie ; un membre n’a pas besoin d’un compte MJ.

## Activation et droits

La page `/atelier-setup/` vérifie le compte propriétaire exact par la connexion Studio historique, puis appelle `POST /api/v1/image-studio/atelier/auth/setup`. L’activation est unique et protégée par verrou SQL. Un visiteur anonyme ne peut pas créer le premier administrateur. La preuve Régie ne sert qu’à l’activation ; les connexions normales utilisent les identifiants Atelier.

Le cookie `xar_atelier_session` est Secure, HttpOnly, SameSite=Strict, limité à `/api/v1/image-studio/atelier`, et dure 12 heures. Seul son condensat est conservé en base. Les mots de passe utilisent le helper de hachage Régie. Les sessions sont liées à la révision d’authentification effectivement vérifiée ; une révocation ou réinitialisation invalide aussi les mutations concurrentes.

L’inscription publique est fermée. Le premier compte est l’unique propriétaire administrateur ; il crée, suspend et réinitialise les comptes membres. Les routes sont sous le préfixe `/api/v1/image-studio/atelier` :

- `/auth/setup`, `/auth/login`, `/auth/logout`, `/auth/me`, `/auth/password` ;
- `/users`, `/users/{id}` pour le propriétaire ;
- `/capabilities` ;
- `/references` pour les références GPT privées ;
- `/gpt/status`, `/gpt/conversations`, `/gpt/conversations/{id}/messages` ;
- `/local-tickets`, `/local-tickets/{id}`, `/local-tickets/{id}/status`, `/local-tickets/{id}/deliver` ;
- `/media/{id}` pour les téléchargements après contrôle des droits.

## Deux circuits

Les conversations GPT sont propres à leur auteur. La queue Atelier est aiguillée vers le relais Régie existant, avec son singleton, ses baux et son contrat `jobType=image`. Le client Store et le moteur Codex ne changent pas. Le relais du propriétaire doit être connecté et actif ; une demande reste en attente s’il est hors ligne. Aucun jeton Codex n’est placé sur OVH ou dans la distribution Atelier. Une erreur officielle de quota reste une erreur du service, sans estimation de quota fabriquée.

Les tickets locaux sont manuels. L’auteur et le propriétaire accèdent au texte, aux références et aux résultats. La prise en charge, les changements d’état et la livraison utilisent révisions optimistes et clés d’idempotence. L’ouverture dans l’éditeur local prépare un état ; elle ne lance pas de calcul. Un résultat proposé à la livraison attend une confirmation explicite.

## Fichiers et migration

Images PNG/JPEG/WebP effectivement décodables, au maximum 15 MiB par fichier, 40 MiB par requête ; quatre pièces jointes par ticket et cinq références GPT. Les fichiers ont des noms opaques et restent dans le stockage privé. Leur accès par les routes Régie historiques est réservé au propriétaire du relais ; publication publique et ajout au catalogue Régie sont refusés. Les références Atelier comptent pour la rétention et le quota commun. Les PJ de tickets comptent également dans ce quota.

La migration 25 ajoute uniquement les tables Atelier sous le verrou de migration existant. Elle ne modifie pas les fiches, sessions ou comptes de jeu. Le mécanisme habituel de basculement de version backend conserve son drainage des anciennes sessions Régie. `.user.ini` autorise les multipart nécessaires sans réduire le plafond historique des médias bruts de 300 Mo ; les limites spécifiques restent imposées par le code.

## Validation

`node tests/atelier-http-contract.mjs` exécute les vrais appels HTTP/multipart avec un serveur PHP et une base MariaDB locale jetable. `php tests/mysql-atelier-gpt.php` vérifie la queue et les baux concurrents. Les tests refusent les cibles SQL non locales. Les régressions historiques et ces deux suites sont exécutées par `backend-check.yml`. Ces fixtures n’utilisent aucun compte réel et ne déclenchent pas de génération.
