# Évolutions de novembre 2026 (web)

**Mise en production** : déployer les fichiers, puis `php bin/console app:evoluer` (obligatoire : colonnes téléphone/PIN, jetons mobiles), puis vider le cache (`php bin/console cache:clear --env=prod`).

1. **Tableau de bord** : section « Plats du jour » (menu du jour, exceptions de date prises en compte). Visible aussi sur « Mes tickets » pour les employés.
2. **Menu déroulant Horaires** : Vue d'ensemble, Types horaires, Plage hebdomadaire, Affecter un travailleur, Déclarer une absence — chacune sur sa page (`/horaires/types`, `/plages`, `/affectations`, `/absences`).
3. **Menu déroulant Organisation** : Directions, Services, Types de repas — pages distinctes (`/organisation/...`). L'ancienne adresse `/parametres` redirige. Le profil Cantine voit « Types de repas ».
4. **États / exports** : tableau « Montants par jour et cumul sur la période » (page, PDF, onglet « Par jour » de l'Excel) ; entête société (nom, adresse, téléphone, e-mail, identifiant fiscal + logo) sur la page, le PDF et l'Excel. Seuls les jours avec activité sont listés.
5. **Menus programmés** : « Programme hebdomadaire » et « Remplacer le menu d'un jour de semaine » repliables, en première ligne.
6. **Horaires et périodes de travail** : « Plages programmées », « Affectations » et « Absences » repliables (avec compteurs).
7. **Logo** : à côté de « MA CANTINE » et sur la page de connexion ; logo société sinon logo de restauration par défaut (`public/logo-defaut.svg`). Route publique `/logo`.
8. **Mobile** : connexion par téléphone + PIN (voir le LISEZ-MOI de l'application). Côté web : champ téléphone et « Gérer le PIN » dans Employés.

Les menus déroulants et blocs repliables utilisent `<details>` (aucun JavaScript, la politique CSP du site l'interdit).

**Non testé ici** : PHP, Composer et Flutter ne sont pas disponibles dans mon environnement ; le code a été relu et ses parenthèses/blocs vérifiés, mais pas exécuté. Faire une recette avant production (en particulier le logo dans le PDF Dompdf et dans l'Excel, la migration `app:evoluer` sur une copie de la base, et la connexion mobile sur appareil réel).
