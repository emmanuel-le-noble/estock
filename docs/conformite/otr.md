# Conformité fiscale togolaise (OTR) — état des lieux et e-facturation

## Régime fiscal

eStock supporte deux régimes configurables dans **Paramètres → Devise, régime fiscal & taxes** :

| Régime | Facturation | Mention obligatoire |
|---|---|---|
| **TPU** (Taxe Professionnelle Unique) — défaut | Factures **hors taxes** (HT = TTC) | « TVA non applicable (TPU) » sur facture/ticket |
| **TVA** (régime classique) | TVA appliquée par ligne (taux défaut 18 %) | Ventilation TVA par taux sur facture/ticket |

- Le champ `taux_tva` d'une facture vaut `0` en TPU : les statistiques (CA HT/TVA/TTC)
  sont calculées en conséquence.
- Côté caisse (JavaScript), `param_pos_config()` transmet `taux_tva = 0` en TPU :
  aucune TVA n'est affichée ni calculée au moment du paiement.

## Identifiants exigés

| Entité | Champs | Où |
|---|---|---|
| Boutique | `nif_boutique`, `rccm_boutique` | Paramètres → Boutique / magasin |
| Magasin (point de vente) | `magasins.nif`, `magasins.rccm` | Gestion des magasins |
| Client B2B | `clients.nif`, `clients.rccm`, `raison_sociale` | Fiche client |

- Le NIF boutique est **requis** : sans lui, l'impression d'une facture affiche un
  avertissement « Facture non conforme (OTR) » (voir `facture_view.php`).
- Une alerte de conformité liste les identifiants manquants sur la page Paramètres.

## État : préparé pour l'e-facturation OTR (inactif)

La table `factures` dispose d'une colonne `statut_transmission` :

```sql
ALTER TABLE factures ADD COLUMN statut_transmission
    ENUM('non_transmise','transmise','non_applicable') NOT NULL DEFAULT 'non_transmise';
```

Cette colonne est **prête mais non utilisée** par le flux de vente : aucune écriture
n'est transmise à l'OTR aujourd'hui. Quand le connecteur officiel sera disponible,
il devra :

1. Marquer la facture `transmise` (et stocker l'UID OTR dans une nouvelle colonne)
   après validation côté OTR ;
2. Exposer une file d'attente des factures `non_transmise` via l'API
   (`api/caisse/...`) ;
3. Refuser (ou mettre en attente) l'annulation comptable d'une facture déjà
   `transmise` (voir `db_facture_annuler()`).

### Références officielles

- OTR Togo — Guide de la facturation électronique : https://otr.tg (rubrique e-services)
- CFE — Centre de Formalités des Entreprises (RCCM) : https://cfe.tg

## Export comptable

- `export_syscohada.php` — export SYSCOHADA (plan comptable OHADA, en-tête PCEC
  + écritures), adapté aux entreprises togolaises (OBM) : journaux VT / AC / DG /
  INV (achats, ventes, dépenses, variation de stocks au CUMP).
