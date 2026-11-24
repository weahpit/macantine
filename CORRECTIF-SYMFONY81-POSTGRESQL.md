# Symfony 8.1 / PostgreSQL — correctif conservé

Cette livraison intègre la signature `checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void`, la connexion DBAL PostgreSQL et les colonnes d'identité PostgreSQL. Elle remplace les instructions de l'ancienne archive.

Suivre `LISEZ-MOI.md` pour installation ou migration ; conserver `.env.local` et les données. L'évolution des tables est appliquée par `app:evoluer`, pas par suppression/recréation de la base. L'ancien verrou d'unicité date/type de repas est remplacé par la règle de délivrance quotidienne.

`composer.lock` est inclus. L'exécution a été testée sur Symfony 8.1.11, PHP 8.6 et PostgreSQL 18.7. La configuration des dépendances vise PHP 8.4.1 minimum. QR Code est verrouillé sur la branche 6.1 compatible avec ce minimum, et les exports utilisent Dompdf et PhpSpreadsheet.
