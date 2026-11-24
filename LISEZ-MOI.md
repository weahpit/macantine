# MA CANTINE — version intégrée tickets, menus et caisse

Livraison du 24 novembre 2026, construite à partir de votre archive `macantine2.zip`. Projet autonome Symfony 8.1, PostgreSQL, interface française. Lire ce document avant installation : il remplace les règles de la première livraison.

## Fonctionnement
- Cinq profils : administrateur, RH, cantine, comptabilité et employé. Les autorisations sont contrôlées côté serveur, pas seulement dans les menus.
- La RH sélectionne un employé et clique sur la carte/photo du plat, puis confirme la délivrance. **Elle n'encaisse rien.**
- Un seul ticket délivré par employé et par jour, toutes catégories confondues, même après annulation. Réactiver le ticket existant reste possible s'il n'a pas été utilisé.
- Les tickets non consommés se cumulent et n'expirent pas automatiquement. Le plat initial est indicatif : lors du service, choisir un plat du menu du jour appartenant à la même catégorie.
- Catégorie et tarifs figés à la délivrance : standard 700 FCFA public / 500 FCFA employé / 200 FCFA de subvention ; premium 1 000 / 700 / 300. Modifier la grille n'altère pas les anciens tickets déjà tarifés.
- À la cantine : scanner/saisir le ticket ou le badge, choisir le ticket accumulé si nécessaire, choisir le plat et le mode de règlement, confirmer **après réception effective du paiement**. Consommation, encaissement et changement de statut sont enregistrés dans une transaction.
- Les horaires et absences sont vérifiés au moment du service. Hors travail, absent ou désactivé : refus sans paiement. Les quarts passant minuit sont rattachés au jour où ils commencent.
- Menus hebdomadaires récurrents et exceptions datées ; exception sans plat = fermeture du menu pour cette date. Photos téléchargées, vérifiées et converties en JPEG réduit.
- Tableau de bord avec graphique des encaissements sur sept jours. États filtrés par période, employé, service, direction et catégorie ; exports PDF et Excel avec synthèse, tickets et encaissements.

## Installation neuve
PHP **8.4.1 ou supérieur, inférieur à 9**, Composer 2, PostgreSQL. Extensions nécessaires déclarées dans `composer.json`, notamment PDO PostgreSQL, GD, fileinfo, mbstring, ZIP, XML/DOM et celles des dépendances PDF/Excel. Composer vérifie les exigences. Le verrou de dépendances est inclus : utiliser `composer install`, pas une résolution libre en production.

Depuis le dossier du projet :

```bash
composer install
composer check-platform-reqs
```

Créer une base PostgreSQL et un rôle dédiés. Créer `.env.local`, conserver ce fichier privé :

```dotenv
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=REMPLACER_PAR_UN_SECRET_ALEATOIRE_LONG
APP_TIMEZONE=Africa/Abidjan
DATABASE_URL="postgresql://cantine:MOT_DE_PASSE_ENCODE@127.0.0.1:5432/cantine"
```

```bash
php bin/console app:installer
php bin/console app:evoluer
php bin/console cache:clear
php bin/console lint:container
php bin/console lint:twig templates
php bin/console lint:yaml config
php bin/console debug:router
php -S 127.0.0.1:8000 -t public public/router.php
```

L'installation demande le premier compte administrateur ; aucun compte de démonstration n'est livré dans la base. Configurer ensuite directions/services, employés, comptes, plats, menus et horaires. Pour chaque travailleur, ajouter une affectation couvrant sa période de travail et les plages du type horaire correspondant. Sans affectation/plage valide, le service est volontairement bloqué. Une plage 00:00–00:00 couvre 24 heures ; utiliser uniquement si cela correspond réellement à votre organisation.

## Mise à niveau d'une base existante
1. Sauvegarder **le projet, la base et les fichiers privés**, puis tester la restauration. Prévoir une interruption des émissions/services pendant la migration.
2. Déployer cette version sur une copie de recette. Conserver votre `.env.local`, vos secrets et vos fichiers `var/photos` existants. Ne pas remplacer les données par celles des tests.
3. Installer les dépendances et exécuter `php bin/console app:evoluer`. Cette commande ajoute les tables/colonnes/index, conserve les tickets et consommations, retire l'ancienne unicité employé/date-repas/type et impose l'unicité employé/date réelle de création.
4. **Conflits historiques :** si plusieurs anciens tickets ont été créés pour un employé le même jour, la migration s'arrête avant modification et indique les conflits. Faire examiner les cas par la RH ; aucune suppression ni modification arbitraire de date n'est effectuée automatiquement.
5. Les anciens tickets `EXPIRE` sont réactivés `DISPONIBLE` lors du premier passage : ce changement applique la nouvelle règle sans péremption. Les tickets utilisés et annulés conservent leur statut.
6. Les anciens tickets sans catégorie/prix doivent être **régularisés par la RH dans Tickets**, avec une catégorie confirmée. Aucun montant historique n'est inventé ; leur utilisation est bloquée jusque-là.
7. Les anciennes consommations et anciens menus textuels restent dans la base. Les menus visuels nécessitent une programmation explicite : les anciens textes ne sont pas convertis automatiquement en plats/photos. Les anciens repas n'avaient pas d'encaissement enregistré ; ils ne sont pas présentés comme des recettes. Les anciens rôles `ROLE_AGENT` / `ROLE_RESPONSABLE` deviennent `ROLE_CANTINE` ; revoir les droits et créer les comptes de comptabilité.
8. Configurer les horaires/affectations/absences, puis valider la recette et déployer. Ne pas remettre l'ancienne version sur le nouveau schéma sans procédure de restauration.

## Sens des états financiers
Les tickets sont filtrés par **date de délivrance**, les encaissements par **date de paiement**. Un ticket ancien payé aujourd'hui apparaît donc dans les recettes d'aujourd'hui, pas dans les tickets délivrés aujourd'hui. La subvention est une **valeur théorique**, pas un versement employeur constaté. Les encaissements des nouveaux repas conservent le service/direction au moment du repas, même si l'employé est ensuite muté ; les listes de tickets reflètent l'organisation actuelle. Les montants et les noms sont conservés lors du service. Les filtres service/direction utilisent ces identifiants historiques pour les nouvelles consommations ; les anciens enregistrements sans identifiant restent sans reconstitution arbitraire.

## Validation effectuée
Voir `VERIFICATIONS.md` et `tests/RESULTATS-RECETTE.txt`. PHP/Symfony et PostgreSQL ont effectivement été exécutés pour cette version, sur une base dédiée. Les tests comprennent les règles métier, connexions des cinq rôles, restrictions HTTP, photo, exports et **deux processus PHP simultanés** pour l'émission puis le service d'un même ticket. Les totaux Excel/PDF ont été relus et le QR généré a été décodé. Les écrans bureau/mobile ont été ouverts dans Chromium.

## Limites et déploiement
- Source et `composer.lock` inclus ; `vendor`, base de démonstration, caches, logs et outils de préparation ne sont pas livrés. Réinstaller les dépendances sur votre serveur.
- Enregistrement comptable de paiements reçus, **pas de connexion à une passerelle bancaire/mobile money**, de retenue salariale ni de comptabilité générale réglementaire.
- Scanner USB/Bluetooth en mode clavier ou saisie du contenu QR ; pas de caméra intégrée ni de service hors connexion.
- Impression du ticket/badge par le navigateur ; export PDF des états financiers.
- Pas de stock de plats, de rationnement journalier des consommations, d'import Excel ou de notifications. Plusieurs tickets accumulés peuvent être utilisés si les conditions sont remplies ; l'unicité porte sur la délivrance quotidienne.
- Les listes administratives et rapports chargent les résultats en mémoire : tester la volumétrie réelle, préférer des périodes limitées pour les gros exports. Photos : 5 Mo, 6 000 px par côté, 12 mégapixels maximum ; sortie limitée à 1 200 px.
- En production : HTTPS, `APP_ENV=prod`, `APP_DEBUG=0`, secret unique, document root exclusivement `public`, sauvegardes PostgreSQL et `var/photos`, droits d'écriture limités sur `var`. Ne jamais exposer `.env.local`, `vendor`, les tests ou les fichiers privés.
- Tests de charge, imprimante/lecteur réel, procédures de caisse, sauvegarde/restauration et environnement de votre hébergeur restent à valider avant mise en service.
