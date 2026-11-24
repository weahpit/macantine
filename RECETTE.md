# Recette de la version intégrée

## Tests automatiques — base de recette UNIQUEMENT

Installer aussi les dépendances de développement avec `composer install`. Le test ci-dessous **supprime le schéma public de la base indiquée**, crée des données fictives puis vérifie le fonctionnement. Il exige une base dont le nom se termine par `_recette`, et une autorisation explicite. Ne jamais utiliser les identifiants de production. La vérification du nom est un garde-fou, pas une sauvegarde.

Exemple Linux/macOS (adapter les identifiants) :

```bash
APP_ENV=test APP_DEBUG=1 \
APP_SECRET=secret-de-recette-uniquement \
APP_TIMEZONE=Africa/Abidjan \
DATABASE_URL='postgresql://cantine_test:MOT_DE_PASSE@127.0.0.1:5432/cantine_recette' \
CANTINE_RECETTE_DESTRUCTIVE=OUI \
php tests/recette_evolution.php
```

Le script termine par `RECETTE TERMINEE` si tout réussit. Il écrit des fichiers PDF/XLSX/QR et une photo dans `var` pour contrôle. Les processus concurrents nécessitent `proc_open` et l'exécution du PHP courant. La base ne doit servir qu'à ces tests. Les comptes fictifs du test ne doivent jamais être déployés.

## Contrôles manuels avant exploitation
1. Sauvegarde et restauration sur une copie ; migration sur les vrais volumes et revue des conflits historiques.
2. Paramétrage des horaires réels : normal, quart de nuit, repos, congés, mutations et dates de fin d'affectation.
3. RH : sélection par image, ticket sans paiement, doublon quotidien refusé, annulation/réactivation, régularisation d'un ticket historique.
4. Cantine : lecteur réel sur ticket/badge imprimé et écran mobile ; plusieurs tickets accumulés ; scan sans validation = aucun encaissement.
5. Refus hors travail/absence ; ticket consommé ; mauvais plat/catégorie ; plat non programmé ; travailleur désactivé ; ancien badge renouvelé.
6. Deux agents sur deux sessions : même ticket, confirmations simultanées = un seul repas et un seul paiement. Les tests automatiques couvrent déjà deux processus PostgreSQL, compléter avec le matériel réel.
7. Vérifier les cinq rôles et les accès directs ; salarié limité à ses tickets et son badge ; requêtes POST sans CSRF refusées.
8. État journalier/semaine/mois ; filtrer par salarié, service, direction, catégorie ; retrouver un ancien ticket payé aujourd'hui ; rapprochement des recettes et modes de paiement.
9. Modifier un tarif après émission : le ticket conserve son prix ; modifier un plat/menu : seul un plat autorisé de même catégorie peut être servi.
10. Impression, ergonomie mobile/tablette, HTTPS, expiration de session, panne réseau, réponse perdue après validation : rescanner et vérifier le serveur avant tout second service.
11. Charge réelle, taille maximale des exports/photos, droits d'écriture sur `var`, confidentialité des fichiers privés et sauvegardes.
12. Validation formelle par la RH, la cantine et la comptabilité avant ouverture.

Les anciennes règles « un ticket par date/type », « futur ou expiré refusé » et « paiement absent » ne s'appliquent plus à cette version. Aucun script de test de l'ancienne API métier ne doit être utilisé comme référence pour les nouvelles règles.
