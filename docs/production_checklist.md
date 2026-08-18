# Checklist de pré-livraison eStock

Cette checklist est à exécuter pour chaque nouvelle installation client. Elle
prépare l'application localement ; les actions d'hébergement seront réalisées
quand le serveur final sera disponible.

## 1. Base et comptes

1. Importer `database/estock_db (1).sql` dans une base vide.
2. Exécuter `php database/apply_migrations.php`.
3. Créer le premier Directeur avec `php bin/create_admin.php "Nom" login "mot-de-passe-d-au-moins-12-caracteres"`.
4. Pour une base existante, exécuter `php bin/disable_demo_accounts.php` après la création du nouveau Directeur.
5. Se connecter, créer les comptes nécessaires et vérifier leurs permissions.
6. Ne pas activer les trois comptes d'initialisation de la base.

## 2. Vérifications avant remise

```powershell
# Syntaxe et tests
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php vendor/bin/phpunit
composer audit --no-interaction

# Contrôle de l'installation (code 0 attendu à la livraison)
php bin/preflight_release.php

# Vérifier l'intégrité des factures et les migrations
php bin/verif_integrite.php
php bin/inventaire_tables.php
```

Effectuer aussi une vente test, un paiement multiple, un retour, un inventaire,
une réception fournisseur et une clôture de caisse. Les résultats attendus et
les tickets doivent être contrôlés par le responsable du commerce.

## 3. Sauvegardes

Chaque installation doit lancer quotidiennement :

```powershell
php bin/backup_db.php
```

Sur Windows, créer une tâche planifiée exécutant cette commande avec un compte
de service qui a accès à la base et au dossier `backups/`. Copier ensuite le
fichier ZIP vers un stockage distinct (NAS ou cloud chiffré). Tester au moins
une fois par mois :

```powershell
php bin/test_restore.php
```

`test_restore.php` recrée la base de test `estock_db_test` : ne jamais lui
donner le nom de la base de production.

## 4. E-mails

Avant d'activer les alertes e-mail, choisir un fournisseur SMTP ou API
transactionnelle, configurer son domaine (SPF, DKIM, DMARC), puis exécuter le
worker régulièrement :

```powershell
php bin/emails_worker.php
```

Tester un e-mail de notification et le lien de désinscription. Ne pas promettre
la délivrabilité tant qu'un fournisseur d'envoi n'est pas configuré.

## 5. À faire lorsque le serveur final sera disponible

- définir `APP_ENV=production` dans `.env` ;
- utiliser une clé `SECRET_URL_KEY` propre à l'installation ;
- activer HTTPS et vérifier les cookies `Secure` ;
- créer un utilisateur MySQL dédié, limité à la base eStock ;
- planifier sauvegardes, worker e-mail et test de restauration ;
- valider les obligations fiscales et comptables avec un professionnel local.
