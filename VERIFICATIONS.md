# Vérifications réellement effectuées — 24 novembre 2026

## Environnement
PHP 8.6 (runtime de préparation), Symfony 8.1.11, Twig 3.31.0, PostgreSQL 18.7, Chromium headless. Résolution des dépendances verrouillée pour PHP 8.4.1 ; pas de test d'exécution avec ce PHP minimal exact. La cible est PostgreSQL ; les nouvelles fonctions ne sont pas validées sur MySQL/SQLite.

## Résultats
- 103 assertions automatiques réussies, dont installation, migration relançable, conflit historique refusé sans altération, ancienne péremption retirée, prix anciens non inventés, régularisation, cumul durable, deux catégories et montants, blocages absence/hors horaire/désactivation/catégorie.
- Connexions des cinq profils et pages principales ; HTTP 403 sur accès salarié interdit et POST directs cantine/comptabilité interdits ; propriété des tickets ; protection CSRF.
- Photo envoyée par formulaire HTTP, conversion JPEG, fichier privé et réponse authentifiée vérifiés.
- Exception de menu daté et retour au programme hebdomadaire testés par formulaires.
- Deux processus PHP réellement simultanés pour l'émission : un succès et un refus. Deux processus pour l'encaissement : un succès et un refus, une seule consommation et un seul paiement.
- États financiers par date de paiement : ancien ticket inclus ; service/direction conservés après mutation. Export PDF et XLSX via les comptes admin/RH/comptabilité.
- Excel rouvert : 3 tickets, 2 paiements, 1 000 FCFA encaissés, 400 FCFA de subvention, 1 400 FCFA de valeur publique. PDF relu : mêmes chiffres, une page sur ce jeu de données.
- QR PNG extrait de la réponse authentifiée et décodé : correspond exactement au jeton du ticket. Les jetons ne figurent pas dans les résultats livrés.
- Syntaxe PHP, 17 modèles Twig, configuration YAML, injection du conteneur et routes : succès. Composer validation, exigences plateforme et audit : voir résultats ci-dessous.
- Navigateur Chromium : tableau de bord, tickets, menus, horaires, états, impression et tickets mobile. Contrôle visuel effectué ; pas de chevauchement ni de contenu illisible constaté sur ces écrans. Aucun débordement horizontal de page à 1 360 px et 390 px dans les pages contrôlées. Les photos/illustrations ne sont plus recadrées.

## Composer
Validation stricte : succès. Exigences plateforme : succès. Audit : aucune vulnérabilité signalée au moment du contrôle.

## Ce qui reste à valider
PHP et extensions de votre serveur, droits de fichiers, migration de votre vraie base, schémas éventuellement modifiés localement, historique incomplet, grandes volumétries, charge, paiement réel/rapprochement caisse, matériel QR/imprimante, sauvegarde/restauration et disponibilité réseau. Ces vérifications de recette ne constituent pas une certification de production.

Les détails des assertions sont dans `tests/RESULTATS-RECETTE.txt`. Les anciennes vérifications non exécutées ne décrivent plus l'état de cette livraison.
