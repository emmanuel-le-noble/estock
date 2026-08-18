# ADR-001 — Paiement Mobile Money (détail au Togo)

**Statut** : Adopté  
**Date** : 2026 · **Contexte** : caisse POS, multi-magasins, marché togolais (XOF)

## Décision

Le paiement **Mobile Money** (TMoney, Flooz, Moov Money…) est traité comme un
**mode de paiement de type « encaissement manuel »** : il n'existe aucun
connecteur opérateur ; le vendeur enregistre un paiement Mobile Money comme un
paiement espèces, avec confirmation visuelle du client.

## Implémentation

- `mode_paiement = 'Mobile_Money'` dans `paiements_facture` (multi-paiements
  sur une même facture pris en charge).
- Export comptable : contrepartie au compte **58 / à définir par le comptable**
  (voir `db_journal_ventes()` — map `Mobile_Money => '58'` ; compte « X — autre »).
- Écritures SYSCOHADA : ligne débit « 58 — Caisse, régies d'avances ».
- API `api/caisse/sync` : le mode est préservé hors-ligne puis synchronisé.

## Alternatives écartées

1. Lien API opérateur (collecte/entité partenaire) : nécessite des démarches
   commerciales avec l'opérateur, paiement par transaction (USSD server /
   HTTP API), maintenance continue — hors périmètre MVP.
2. Acompte séparé « mobile money wallet » : complexité de rapprochement,
   pas de demande signalée.

## Impact

- Aucun risque de fraude côté application (le paiement est validé en caisse).
- Comptabilité : l'entreprise devra consolider les règlements mobile money
  (relevés d'exploitation) en fin de journée — documenté dans les exports.

---

# ADR-002 — Journal de caisse (balance) & tenue des comptes

**Statut** : Adopté · **Date** : 2026

## Décision

La « balance » est remplacée par deux journaux **non mutables** :
- **Journal des ventes** = table `factures` (chaînage cryptographique, voir
  `docs/conformite/integrite.md`)
  enrichi des paiements (`paiements_facture`, immuables par triggers) ;
- **Journal de caisse / Z** = table `clotures_caisse` (chaînée, stockée en
  `archives_caisse` signées).

La tenue des comptes est **hors application** : export SYSCOHADA pour
le comptable ; le CUMP/prix de revient alimente les marges dans le compte de
résultat intégré.

## Pourquoi pas une table « balance » dédiée

- Un cache de soldes dériverait à terme de la réalité sans procédure de purge ;
- Les triggers d'inaltérabilité garantissent la confiance sur les données
  sources ; tout rapport dérivé est réinterrogeable à la volée.

## Impact

- Pas de nouvelle table ; les statistiques et le compte de résultat restent
  calculés à la demande (`db_stats_benefice_net()`).
- Les archives signées servent de preuve d'audit (période de conservation 6 ans).

---

# ADR-003 — Scalabilité multi-caisses & performances

**Statut** : Adopté · **Date** : 2026

## Décision

L'architecture reste **PHP procédural mono-serveur** (WampServer/LAMP), avec
des mécanismes ciblés pour la charge des points de vente :

1. **Numérotation atomique** : table `sequences` (verrou en ligne, `FOR
   UPDATE` sur les compteurs) — testée sous concurrence réelle
   (`tests/Integration/ConcurrenceNumerotationTest.php`, 8 processus × 5).
2. **Transactions courtes** : toute vente est une transaction unique
   `BEGIN…COMMIT` avec `SELECT … FOR UPDATE` sur stock ; pas de `sleep` ni de
   verrou applicatif long.
3. **Cache paramètres** : `config/parametres.php` (session) — zéro requête par
   affichage pour les entêtes de ticket.
4. **Caisse hors-ligne** : queue `localStorage` → `api/caisse/sync`
   (idempotent via `client_sale_id`) pour les pannes réseau partielles.

## Limites assumées

- Pas de clustering MySQL, pas de Redis (évalué puis écarté : usages limités,
  2 caisses max par magasin) ;
- Les exports comptables sont régénérables à la demande (aucune table
  dérivée persistante fragilisante).

## Évolution possible

- Réplication de lecture (réplica MySQL) pour les exports lourds ;
- Partitionnement journalier de `mouvements_stock` / `logs_activite` au-delà
  de ~2 M de lignes (seuil estimé 3 ans d'activité pour un magasin moyen).

---

# ADR-004 — File d'attente des e-mails (asynchrone)

**Statut** : Adopté · **Date** : 2026

## Décision

Tous les e-mails applicatifs passent par la table `emails_queue` :
`envoyer_email_enqueue()` insère le message ; `bin/emails_worker.php` (cron
toutes les minutes) les envoie avec retries (3 max). Repli rétrocompatible sur
`mail()` si la table est absente.

## Justification

- La vente au comptoir ne doit jamais être bloquée par un SMTP lent/défaillant ;
- Opt-in consentement vérifié au dépôt (types `MARKETING` refusés sans
  consentement) — testé (`tests/Integration/EmailsConsentementsTest.php`).

## Impact

- `emails_queue.statut` : `EN_ATTENTE / ENVOYE / ERREUR / REFUSE`;
- Rétractation par lien signé (anti-énumération) implémentée.