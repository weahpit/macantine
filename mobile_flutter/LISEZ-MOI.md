# Mes tickets — source Flutter / Android

Ce dossier contient une application Flutter autonome : connexion employé, session stockée de façon sécurisée, récupération des tickets depuis le serveur, actualisation, détail et QR authentifié, identité/logo de la société. Tous les tickets sont récupérés via la pagination de l'API (30 par requête). Le téléphone ne délivre pas de ticket, n'encaisse pas et ne valide pas de consommation. Aucun mot de passe n'est conservé sur le téléphone ; le jeton de session est chiffré par `flutter_secure_storage`.

**Pas d'APK livré et pas de compilation/analyse Flutter exécutée ici : le SDK Flutter n'est pas disponible dans l'environnement de préparation.** Les routes serveur ont été testées. Cette source n'est pas une modification de votre application Flutter existante : aucun projet Flutter existant n'était présent dans `macantine2.zip`. Elle peut être utilisée seule ou adaptée à votre application Android avec les mêmes routes. Le nom du package Android généré est un exemple à remplacer par votre identifiant réel avant publication.

## Préparation sur votre poste

Installer Flutter stable, Android SDK, Python 3 et vérifier `flutter doctor`. Dans ce dossier :

```bash
python prepare_android.py
flutter pub get
flutter analyze
flutter build apk --release
```

Le script génère uniquement le dossier `android` à partir du Flutter installé, conserve les sources de ce dossier et ajoute la permission INTERNET. Il n'écrase pas un dossier Android existant. Pour un projet Android existant, conserver son identifiant, son manifeste, son keystore et sa configuration ; ajouter les dépendances de `pubspec.yaml` et intégrer les écrans/routes à votre navigation.

Pour publication : configurer votre signature Android (ne pas publier avec une clé de débogage), votre vrai identifiant d'application, les versions SDK supportées et les règles de sauvegarde du stockage sécurisé conformément à la documentation de `flutter_secure_storage`.

## Serveur

Sur le serveur Symfony, installer les dépendances, appliquer `php bin/console app:evoluer`, puis créer un utilisateur de rôle `ROLE_EMPLOYE` lié au bon employé actif. Un compte administrateur ne peut pas se connecter à cette application employé.

Au premier lancement, saisir l'adresse HTTPS de votre application (sans `/api/mobile`), l'e-mail et le mot de passe du compte employé. Exemple : `https://cantine.exemple.ci`. Ne pas utiliser `localhost` pour désigner un serveur distant depuis le téléphone. La variante HTTP est réservée au développement local ; Android bloque normalement HTTP en version de production. Ne pas désactiver globalement la sécurité réseau pour la production.

Session valable 30 jours ; invalidée à la déconnexion, lors d'un changement de mot de passe, de rôle, de désactivation du compte ou du salarié. Pour une session expirée, utiliser Quitter puis se reconnecter. Les tickets sont actualisés au retour du serveur ; un QR affiché ne garantit pas le droit au service, qui est revérifié en caisse. Pas de validation hors connexion.

## API

- `POST /api/mobile/connexion` : JSON `email` et `mot_de_passe`, retourne `jeton` et `expire_le`.
- `GET /api/mobile/tickets?page=1` : tickets du salarié authentifié, total, société et indication de disponibilité du logo.
- `GET /api/mobile/tickets/{id}/qr` : PNG du QR, exclusivement pour un ticket du salarié.
- `GET /api/mobile/logo` : PNG du logo société.
- `POST /api/mobile/deconnexion` : révoque le jeton.

Les routes hors connexion utilisent `Authorization: Bearer <jeton>` ; ne jamais placer le jeton dans une URL, un log ou du stockage non sécurisé. L'API est distincte des cookies/CSRF du site web, exige HTTPS en production, limite les tentatives de connexion et ne conserve que l'empreinte du jeton dans PostgreSQL.

## Recette Android à effectuer

Connexion, restauration de session, logo, absence de tickets, plus de 30 tickets, réseau indisponible, session expirée, déconnexion, appareil réel, scan du QR depuis un autre appareil, et protection des données lors d'un changement d'utilisateur. Les appels API ne remplacent pas les essais Android ni la compilation.
