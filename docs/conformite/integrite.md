# Intégrité et traçabilité des ventes (eStock)

> Statut : **implémenté et vérifiable** (audit CLI `bin/verif_integrite.php`).
> Objectif : garantir la **fiabilité de la piste d'audit** des ventes —
> **inaltérabilité**, **conservation** et **archivage** des données de caisse.
> Ces mécanismes (chaînage, immuabilité, archives signées) préparent les
> exigences de traçabilité fiscale (e-facturation OTR, contrôles comptables).

## 1. Exigences couvertes

| Exigence | Implémentation eStock |
|---|---|
| Inaltérabilité des données de caisse | Chaînage cryptographique SHA-256 de chaque facture (le hash inclut le hash de la précédente) + triggers SGBD interdisant toute mise à jour ou suppression de factures/lignes/paiements/clôtures validés |
| Horodatage | Colonnes `factures.horodatage_certifie` / `clotures_caisse.horodatage_certifie` (horodatage UTC ISO-8601 écrit dans la même transaction que le hash) |
| Journal des clôtures immuable | Chaque clôture est chaînée à la précédente (`hash_chaine`, `hash_chaine_precedent`, `empreinte_journal`) et protégée par trigger `DELETE`/`UPDATE` |
| Conservation ≥ 6 ans | Module d'archivage périodique : export JSON signé (HMAC-SHA256), durée configurable `archives_conservation_annees` (défaut 6) |
| Piste d'audit vérifiable | Page `conformite.php` (rapport d'intégrité, historiques d'archives) + audit CLI `bin/verif_integrite.php` (sortie JSON) |

## 2. Architecture du chaînage cryptographique

Chaque facture validée (`statut = 'Payee'`) est chaînée **dans la même
transaction** que son insertion (web : `valider_facture.php` ; API offline :
`api/index.php` `caisse/sync`).

```
facture N : hash = SHA256( canonic(facture N, lignes N) | hash_facture N-1 )
facture 0 : hash = SHA256( canonic(facture 0, lignes 0) | GENESIS-ESTOCK-V1 )
```

- `canonic()` : chaîne déterministe `numero|date|total_ht|tva_taux|total_ttc|
  montant_paye|monnaie_rendue|magasin_id|utilisateur_id|statut|JSON lignes`.
- Le prédécesseur est la dernière facture **committée** (`hash_chaine` non
  NULL, `id` le plus petit) : la continuité est garantie même avec des ventes
  concurrentes multi-caisses.
- **Annulations** : jamais de `DELETE` — passage en `statut='Annulee'` avec
  `date_annulation`, `motif_annulation`, `annulee_par` (la facture et son
  hash restent dans la chaîne ; le hash porte le statut).

### Clôtures de caisse

Chaque clôture valide est chaînée à la précédente (genesis
`GENESIS-ESTOCK-CLOTURES-V1`) et porte `empreinte_journal` = SHA-256 de
l'ensemble des paires `id:hash_chaine` des factures de la journée.

### Immuabilité au niveau SGBD (triggers, `database/integrite_ventes.sql`)

- `trg_factures_immutable_delete` / `_update` : `SIGNAL 45000` si suppression
  ou modification d'un champ protégé d'une facture validée.
- `trg_lignes_facture_*` / `trg_paiements_*` : aucune suppression ni
  modification d'une ligne/paiement rattaché à une facture chaînée.
- `trg_clotures_immutable_*` : clôtures immuables.

## 3. Vérification d'intégrité

Page web `conformite.php` (permission `conformite_archives`) :

- re-vérifie toute la chaîne (hash attendu vs hash stocké, `hash_equals`) ;
- compte les factures manquantes / en erreur ;
- propose le **backfill** (rattrapage) des factures antérieures à
  l'activation du chaînage (idempotent, limité à 5 000 par exécution).

Audit CLI (intégrable en cron / CI) :

```bash
php bin/verif_integrite.php              # rapport texte, code retour 0/1/2
php bin/verif_integrite.php --json       # rapport JSON exploitable
php bin/verif_integrite.php --backfill   # rattrapage puis vérification
```

Contrôles effectués : 8 triggers présents, chaîne factures, chaîne clôtures,
aucune facture non chaînée, continuité de numérotation par jour, échéance de
conservation des archives.

## 4. Archivage périodique (conservation ≥ 6 ans)

Depuis `conformite.php` → « Créer une archive » :

- période au choix (dates), magasin ou global ;
- agrégation `db_archives_periode_data` : totaux HT/TVA/TTC, nombre de
  factures et paiements, **hash_sommet** = sommet de chaîne de la période ;
- fichier `archives/archive_AAAAMMJJ_AAAAMMJJ.json` : données + signature
  HMAC-SHA256 (`SECRET_URL_KEY`) — export signé téléchargeable ;
- enregistrement en base (`archives_caisse`, statut `CONSERVE`) avec
  échéance `conserve_jusqua` (paramètre `archives_conservation_annees`,
  défaut 6 ans) ;
- toute période déjà archivée est un miroir : création refusée si un
  doublon existe (immuabilité de l'historique d'archivage).

## 5. Procédure de mise en place

1. Appliquer les migrations : `php database/apply_migrations.php`
   (contient `integrite_ventes.sql`).
2. Rattraper le chaînage historique : `php bin/verif_integrite.php --backfill`.
3. Archiver les périodes closes : UI `conformite.php`.
4. Piste d'audit : sortie `bin/verif_integrite.php --json` + exports
   d'archives signés + présentation de la présente note.

## 6. Limites connues

- L'horodatage est produit par le serveur d'application (pas de TSA tierce) ;
  acceptable pour la piste d'audit interne, à réévaluer si une certification
  notariale est exigée.
- Les archives JSON sont stockées dans `archives/` (web racine) : y ajouter
  un `.htaccess` interdisant l'accès direct en production et planifier leur
  copie hors-site (voir stratégie de sauvegarde).
