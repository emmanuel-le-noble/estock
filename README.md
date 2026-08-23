# AUDIT FONCTIONNEL COMPLET — eStock v2.0.0

**Date de l'audit :** 23 août 2026
**Version du logiciel :** 2.0.0
**Type d'application :** Gestion de stock et Point de Vente (POS)
**Technologies :** PHP 8.x, MySQL, Twig 3, Bootstrap 5.3, TCPDF, JsBarcode
**Base URL de production :** https://stockpro.goodhealthforever.net

---

## TABLE DES MATIÈRES

1. Vue d'ensemble du système
2. Authentification et Gestion des Utilisateurs
3. Gestion des Articles
4. Gestion du Stock
5. Point de Vente (Caisse)
6. Gestion de la Fidélité Clients
7. Commandes Fournisseurs
8. Gestion des Péremptions et Lots
9. Transferts Inter-Magasins
10. Retours et Avoirs (SAV)
11. Promotions et Règles de Vente Flash
12. Dépenses
13. Conformité et Export Comptable
14. Statistiques et Tableau de Bord
15. Exports de Données
16. Impression (Étiquettes, Tickets, Cartes)
17. Paramétrage de l'Application
18. Journal d'Audit et Traçabilité
19. Sécurité
20. Annexes

---

## 1. VUE D'ENSEMBLE DU SYSTÈME

### 1.1. Description générale

eStock est une application de gestion de stock et de point de vente (POS) conçue pour les commerces et boutiques. Elle permet de gérer l'ensemble du cycle commercial : des articles et fournisseurs aux ventes en caisse, en passant par le suivi des stocks, les commandes fournisseurs, la fidélité client et la conformité comptable.

### 1.2. Fonctionnalités principales

- Gestion des articles avec codes-barres EAN-13
- Gestion multi-magasins avec stock indépendant par magasin
- Point de vente avec scan de codes-barres, modes de paiement multiples
- Vente hors-ligne avec synchronisation automatique
- Suivi des stocks en temps réel avec alertes de stock bas
- Gestion des lots et péremptions (méthode FEFO - First Expired First Out)
- Inventaire physique avec comptage et ajustements
- Commandes fournisseurs avec workflow de validation
- Transferts inter-magasins
- Système de fidélité clients avec points
- Promotions et ventes flash automatiques
- Statistiques et tableaux de bord avec graphiques
- Exports CSV et export comptable SYSCOHADA
- Impression d'étiquettes, tickets thermiques et cartes fidélité
- Journal d'audit complet de toutes les actions

### 1.3. Architecture technique

| Composant | Technologie |
|---|---|
| Langage | PHP 8.x |
| Base de données | MySQL (PDO, requêtes préparées) |
| Moteur de templates | Twig 3 |
| Interface | Bootstrap 5.3.3 |
| PDF | TCPDF |
| Codes-barres | JsBarcode 3.11.6 (CDN) |
| Graphiques | Chart.js 4 (CDN) |
| Icônes | Bootstrap Icons 1.11.3 |
| Testing | PHPUnit 11.5 |

### 1.4. Rôles utilisateurs

| Rôle | Description | Niveau hiérarchique |
|---|---|---|
| Directeur | Accès total, peut switcher entre magasins | 4 (le plus élevé) |
| Admin | Gestion des utilisateurs, paramètres, statistiques | 3 |
| Magasinier | Gestion des stocks, inventaire, transferts, étiquettes, commandes | 2 |
| Vendeur | Caisse, factures, retours, promotions | 1 (le plus bas) |

### 1.5. Table de correspondance Rôle / Module

| Module | Directeur | Admin | Magasinier | Vendeur |
|---|---|---|---|---|
| Tableau de bord | OUI | OUI | OUI | OUI |
| Articles | OUI | OUI | OUI | NON |
| Stock / Mouvements | OUI | OUI | OUI | NON |
| Caisse / Ventes | OUI | OUI | NON | OUI |
| Factures | OUI | OUI | NON | OUI |
| Clôture caisse | OUI | OUI | NON | OUI |
| Clients / Fidélité | OUI | OUI | NON | OUI |
| Promotions | OUI | OUI | NON | OUI |
| Retours / SAV | OUI | OUI | NON | OUI |
| Inventaire | OUI | OUI | OUI | NON |
| Commandes fournisseurs | OUI | OUI | OUI | NON |
| Transferts inter-magasins | OUI | OUI | NON | NON |
| Péremptions / Lots | OUI | OUI | OUI | NON |
| Étiquettes | OUI | OUI | OUI | NON |
| Statistiques | OUI | OUI | NON | NON |
| Dépenses | OUI | OUI | NON | NON |
| Utilisateurs | OUI | OUI | NON | NON |
| Magasins | OUI | OUI | NON | NON |
| Paramètres | OUI | OUI | NON | NON |
| Export CSV | OUI | OUI | OUI | NON |
| Export SYSCOHADA | OUI | NON | NON | NON |
| Journal d'audit | OUI | OUI | OUI | OUI |
| Conformité | OUI | NON | NON | NON |

---

## 2. AUTHENTIFICATION ET GESTION DES UTILISATEURS

### 2.1. Connexion

**Fichier :** auth/login.php

Procédure de connexion :
1. L'utilisateur accède à la page de connexion
2. Saisie de son identifiant (login) et mot de passe
3. Vérification du code CSRF (protection anti-CSRF)
4. Vérification du rate limiting (max 5 tentatives par IP en 15 minutes)
5. Vérification des identifiants (bcrypt via password_verify())
6. Régénération de la session (protection anti-fixation de session)
7. Journalisation de la connexion
8. Redirection :
   - Directeur → Page de sélection du magasin
   - Autres rôles → Tableau de bord

Sécurité :
- Anti-timing : délai aléatoire (100-300ms) sur les réponses succès et échec
- Hash bcrypt des mots de passe
- Protection contre l'énumération d'utilisateurs

### 2.2. Déconnexion

**Fichier :** auth/logout.php

- Uniquement via POST (méthode sécurisée)
- Validation CSRF obligatoire
- Nettoyage complet de la session
- Régénération de l'ID de session
- Expiration du cookie de session
- Journalisation de l'action

### 2.3. Gestion des sessions

| Paramètre | Valeur |
|---|---|
| Durée du cookie | Session (expire à la fermeture du navigateur) |
| Flag Secure | Activé si HTTPS |
| Flag HttpOnly | Oui (pas d'accès JavaScript) |
| SameSite | Lax |
| Timeout d'inactivité | 30 minutes |
| Timeout absolu | 8 heures |

### 2.4. Gestion des utilisateurs

**Fichier :** utilisateurs.php
**Accès :** Directeur et Admin uniquement

Fonctionnalités :
- Création d'utilisateur avec choix de rôle et magasin assigné
- Modification des informations et activation/désactivation
- Changement de mot de passe avec vérification du mot de passe actuel
- Hiérarchie des rôles : un utilisateur ne peut modifier que les utilisateurs de niveau inférieur
- Impossible de désactiver son propre compte
- Les utilisateurs sont désactivés (suppression logique), jamais supprimés physiquement

Données d'un utilisateur :
- Nom, Login, Mot de passe (hashé), Rôle, Magasin assigné, État (actif/inactif)
- Secret TOTP et état 2FA (infrastructure prête mais pas encore activée)

### 2.5. Profil utilisateur

**Fichier :** profil.php

- Tout utilisateur connecté peut consulter et modifier son profil
- Changement de mot de passe obligatoire avec :
  - Vérification du mot de passe actuel
  - Nouveau mot de passe différent de l'actuel
  - Confirmation du nouveau mot de passe
  - Minimum 8 caractères
  - Rate limiting : 5 tentatives par 15 minutes

### 2.6. Sélection du magasin (Directeur)

**Fichier :** auth/choisir_magasin.php

- Uniquement pour le rôle Directeur
- Permet de choisir le magasin à gérer
- Le choix est stocké en session
- Le Directeur peut changer de magasin à tout moment

### 2.7. Système de permissions (RBAC)

Le système utilise une base de données pour gérer les permissions :

- Table permissions : ~100+ permissions par clé
- Table role_permissions : association rôle / permission
- Fonctions PHP : peut($cle) pour vérifier, exiger_permission($cle) pour bloquer

Exemples de permissions :

| Clé | Description | Directeur | Admin | Magasinier | Vendeur |
|---|---|---|---|---|---|
| articles_gerer | Gérer les articles | OUI | OUI | OUI | NON |
| articles_modifier | Modifier articles via API | OUI | OUI | OUI | NON |
| stock_consulter | Consulter le stock | OUI | OUI | OUI | NON |
| caisse_gerer | Utiliser la caisse | OUI | OUI | NON | OUI |
| clients_consulter | Consulter les clients | OUI | OUI | NON | OUI |
| clients_gerer | Gérer les clients | OUI | OUI | NON | NON |
| achats_consulter | Voir les commandes | OUI | OUI | OUI | NON |
| inventaire_consulter | Voir l'inventaire | OUI | OUI | OUI | NON |
| retours_consulter | Voir les retours | OUI | OUI | NON | OUI |
| promotions_consulter | Voir les promotions | OUI | OUI | NON | OUI |
| audit_consulter | Voir le journal | OUI | OUI | OUI | OUI |
| conformite_archives | Gérer conformité | OUI | NON | NON | NON |
| conformite_export_syscohada | Export SYSCOHADA | OUI | NON | NON | NON |

---

## 3. GESTION DES ARTICLES

### 3.1. Page de gestion

**Fichier :** articles.php
**Accès :** Directeur, Admin, Magasinier

### 3.2. Liste des articles

La page affiche un tableau paginé (25 articles par page) avec les colonnes :
- Nom (en gras)
- Code-barres (format code)
- SKU
- Prix de vente (formaté en FCFA)
- Stock (badge coloré : vert si OK, rouge si bas)
- Rayon/Emplacement
- Fournisseur
- Actions (Modifier, Supprimer)

Recherche : Recherche par nom, code-barres ou SKU via un champ de recherche en haut de page.

### 3.3. Données d'un article

| Champ | Type | Obligatoire | Description |
|---|---|---|---|
| Code-barres | EAN-13 | Oui | Code unique à 13 chiffres |
| Nom | Texte (max 200) | Oui | Désignation de l'article |
| SKU | Texte (max 64) | Non | Unité de gestion des stocks |
| Prix d'achat | Décimal | Oui | Prix unitaire d'achat HT |
| Prix de vente | Décimal | Oui | Prix unitaire de vente TTC |
| Seuil d'alerte | Entier | Oui (défaut: 5) | Seuil de stock bas |
| Emplacement | Texte (max 100) | Non | Position en rayon (ex: A1-01) |
| Fournisseur | Sélection | Non | Lien vers le fournisseur |
| Catégorie | Sélection | Non | Lien vers la catégorie |
| Taux TVA | Décimal | Non | Taux TVA spécifique (sinon taux global) |

### 3.4. Génération de code-barres EAN-13

Fonctionnement :
1. Clic sur le bouton "Générer" à côté du champ code-barres
2. L'application appelle l'API pour générer un code EAN-13 unique
3. Le code est pré-rempli dans le champ
4. Un aperçu visuel du code-barres s'affiche en temps réel (via JsBarcode)

Format du code : Préfixe "20" + 10 chiffres aléatoires + 1 chiffre de contrôle

### 3.5. Catégories

**Fichier :** categories.php
**Accès :** Directeur, Admin, Magasinier (avec permission articles_gerer)

Fonctionnalités :
- Liste des catégories avec nombre d'articles
- Création, modification, désactivation
- Chaque catégorie a : nom, description, état (actif/inactif)

### 3.6. Fournisseurs

**Fichier :** fournisseurs.php
**Accès :** Directeur, Admin, Magasinier

Fonctionnalités :
- Liste des fournisseurs
- Création, modification, désactivation
- Chaque fournisseur a : nom, adresse, téléphone, email, contact

### 3.7. Validation

- Code-barres : doit être un EAN-13 valide (13 chiffres + check digit)
- Unicité du code-barres dans la base
- Nom : obligatoire, max 200 caractères
- Prix : positifs ou nuls
- Protection CSRF sur tous les formulaires

---

## 4. GESTION DU STOCK

### 4.1. Consultation du stock

**Fichier :** stock.php
**Accès :** Directeur, Admin, Magasinier

La page affiche l'état du stock pour le magasin de l'utilisateur :
- Liste des articles avec quantité en stock
- Badge coloré : vert (stock OK), rouge (stock bas ou épuisé)
- Filtrage par magasin (Admin/Directeur peuvent voir tous les magasins)
- Valeur totale du stock affichée

### 4.2. Mouvements de stock

**Fichier :** mouvements.php
**Accès :** Directeur, Admin, Magasinier

Types de mouvements :

| Type | Signe | Effet sur le stock | Origine |
|---|---|---|---|
| Entree | + | Augmente le stock | Réception fournisseur, retour client, inventaire, ajout de lot |
| Sortie | - | Diminue le stock | Sortie manuelle (casse, jet, échantillon) |
| Vente | - | Diminue le stock | Vente en caisse (en ligne ou synchro) |
| Transfert | - | Diminue le stock source | Transfert inter-magasins (côté source) |
| Ajustement | +/- | Augmente ou diminue | Validation d'inventaire physique |
| Retour_stock | + | Augmente le stock | Retour client SAV |

Formulaire d'ajout :
- Sélection de l'article
- Type de mouvement (Entrée ou Sortie)
- Quantité
- Motif (obligatoire)
- Numéro de lot (optionnel, pour les entrées)
- Date de péremption (optionnelle)

### 4.3. Inventaire physique

**Fichier :** inventaire.php
**Accès :** Directeur, Admin, Magasinier

Workflow :
1. Création d'une session d'inventaire (référence auto : INV-YYYY-NNNN)
2. Comptage article par article :
   - Scan du code-barres ou recherche par nom
   - Affichage du stock théorique (système)
   - Saisie du stock physique (compté)
   - Calcul automatique de l'écart
3. Validation : application des écarts au stock (mouvements automatiques)
4. Annulation possible (aucune modification au stock)

### 4.4. Alertes de stock bas

Fonctionnement :
- Chaque article a un seuil d'alerte (défaut : 5)
- Chaque magasin peut avoir un seuil spécifique
- Les articles en dessous du seuil sont signalés en rouge sur le tableau de bord
- Badge dans le menu indiquant le nombre d'alertes
- Notifications email possibles (si configurées)

### 4.5. Stock multi-magasins

- Chaque magasin a son propre stock pour chaque article
- Le stock global (articles.quantite_stock) est maintenu en synchronisation
- Les transferts entre magasins décrémentent le stock source et incrémentent le stock destination
- Nouvel article → initialisé dans tous les magasins actifs

### 4.6. Traçabilité des mouvements

Tout mouvement de stock est enregistré dans la table mouvements_stock avec :
- Article concerné
- Utilisateur ayant fait le mouvement
- Type de mouvement
- Quantité
- Motif
- Magasin
- Date et heure
- Numéro de lot (si applicable)
- Date de péremption (si applicable)

---

## 5. POINT DE VENTE (CAISSE)

### 5.1. Interface de caisse

**Fichier :** caisse.php
**Accès :** Directeur, Admin, Vendeur

Layout à deux colonnes :
- Colonne gauche (8/12) : Zone de scan + alertes + tableau du panier
- Colonne droite (4/12) : Panel de paiement + fidélité + totaux + bouton valider

### 5.2. Scan de codes-barres

Fonctionnement :
1. Le champ de scan reste en permanence en focus
2. Le vendeur passe le code-barres du produit sous le lecteur
3. L'application appelle l'API pour rechercher l'article
4. L'article est ajouté au panier

Fonctionnalités avancées :
- Anti-doublon : ignore les scans identiques dans les 800ms
- Vérification FEFO : bloque la vente si le lot est expiré
- Alerte péremption : avertissement si le lot expire dans moins de 7 jours
- Détection automatique des promotions (vente flash)
- Vente au poids : modal de saisie du poids si l'article est configuré

### 5.3. Gestion du panier

Fonctionnalités :
- Ajout automatique d'un article par scan
- Modification des quantités (+1/-1)
- Suppression d'une ligne
- Vidage complet du panier (touche F2)
- Calcul automatique des totaux HT, TVA, TTC
- Affichage des promotions appliquées
- Contrôle de disponibilité en stock

### 5.4. Modes de paiement

Modes supportés :
- Espèces (par défaut) : avec calcul de la monnaie
- Mobile Money : avec champ référence
- Carte bancaire

Paiement multiple (split) :
- Les trois modes peuvent être utilisés simultanément
- Barre de progression visuelle du paiement
- Le bouton "Valider" est activé uniquement quand le total TTC est atteint

Boutons rapides :
- "Exact" : montant exact du TTC
- Montages prédéfinis : 20, 50, 100 (configurables)

### 5.5. Validation de la vente

En ligne :
1. POST vers valider_facture.php avec toutes les données
2. Validation côté serveur (stock, prix, promotions, CSRF)
3. Génération du numéro de facture (FORMAT : FAC-YYYYMMDD-NNNN)
4. Déduction du stock (FEFO)
5. Enregistrement des paiements
6. Chaînage cryptographique (SHA-256)
7. Attribution des points de fidélité
8. Redirection vers l'impression du ticket

Hors-ligne :
1. Sauvegarde dans localStorage
2. Badge de connexion change (orange = hors-ligne)
3. Synchronisation automatique dès le retour de la connexion
4. Vérification d'idempotence (client_sale_id UUID)

### 5.6. Mode hors-ligne

**Fichier :** assets/js/caisse.js

Fonctionnalités :
- Badge de connexion : vert (en ligne), bleu (en ligne + ventes en attente), orange (hors-ligne)
- File d'attente des ventes dans localStorage
- Synchronisation automatique au retour de la connexion
- ID unique par vente (UUID) pour éviter les doublons
- Le scan ne fonctionne pas hors-ligne

### 5.7. Clôture de caisse

**Fichier :** cloture.php
**Accès :** Directeur, Admin, Vendeur

Workflow :
1. Résumé de la journée : nombre de ventes, montant attendu
2. Saisie du montant réel compté en caisse
3. Calcul automatique de l'écart
4. Validation : enregistrement de la clôture
5. Fermeture de la session de vente

Le compteur est "fermé" pour le jour en cours. Impossible de scanner ou valider des ventes après clôture.

### 5.8. Numérotation des factures

Format : {PRÉFIXE}-YYYYMMDD-NNNN (ex: FAC-20260823-0001)
- Préfixe configurable
- Séquence atomique (table sequences)
- Chaînage cryptographique SHA-256 pour intégrité

---

## 6. GESTION DE LA FIDÉLITÉ CLIENTS

### 6.1. Module clients

**Fichier :** clients.php
**Accès :** Directeur, Admin (permission clients_gerer)

Données d'un client :
- Nom, téléphone, email
- Type (B2C ou B2B)
- Code fidélité (auto-généré : F + ID en 8 chiffres)
- Solde de points de fidélité
- Consentement fidélité
- Données B2B (raison sociale, NIF, RCCM)
- Historique des achats

Fonctionnalités :
- Recherche par nom, téléphone, email, code fidélité
- Création, modification
- Vue détaillée avec historique des achats et points
- Anonymisation GDPR possible

### 6.2. Système de points

Fonctionnement :
- Génération de points à chaque achat : floor(total_ttc / points_par_devise)
- Points par devise configurables (défaut : 1 point pour 100 FCFA)
- Utilisation des points comme réduction en caisse
- Minimum de points requis pour utilisation (défaut : 10)
- Historique complet des transactions de points

Types de transactions :
- GAIN : à chaque achat
- UTILISATION : en caisse
- EXPIRATION : automatique
- AJUSTEMENT : manuel
- ANNULE : annulation

### 6.3. Carte de fidélité

**Fichier :** carte_fidelite.php
**Template :** templates/carte_fidelite.html.twig

- Format carte de visite (85mm x 55mm)
- Code-barres CODE128 du code fidélité
- Nom du client et solde de points
- Impression directe

---

## 7. COMMANDES FOURNISSEURS

### 7.1. Page de gestion

**Fichier :** commandes_fournisseur.php
**Accès :** Directeur, Admin, Magasinier

### 7.2. Workflow de commande

| Étape | Statut | Qui | Action |
|---|---|---|---|
| 1. Création | Brouillon | Magasinier | Création de la commande avec lignes |
| 2. Soumission | En_Attente | Magasinier | Demande validation |
| 3. Approbation | Envoyée | Directeur/Admin | Envoi au fournisseur |
| 4. Réception | Partiellement_Reçue / Reçue | Magasinier | Enregistrement des quantités reçues |

### 7.3. Ligne de commande

Chaque ligne contient :
- Article
- Quantité commandée
- Quantité reçue
- Prix unitaire d'achat

### 7.4. Impact sur le stock

Lors de la réception, pour chaque ligne reçue :
- Création d'un mouvement de stock de type "Entrée"
- Valorisation CUMP (coût unitaire moyen pondéré)
- Création de lots si numéro de lot fourni

---

## 8. GESTION DES PÉREMPTIONS ET LOTS

### 8.1. Suivi des lots

**Fichier :** peremptions.php
**Accès :** Directeur, Admin, Magasinier

Le système suit les lots avec :
- Numéro de lot
- Date de péremption (DLC/DDM)
- Quantité dans le lot
- Magasin

### 8.2. Méthode FEFO (First Expired First Out)

Le système applique automatiquement la méthode FEFO :
- À la vente, le lot le plus proche de la péremption est utilisé en premier
- Si le lot est expiré → vente bloquée
- Si le lot expire dans < 7 jours → avertissement
- Si pas de lot disponible → vente impossible

### 8.3. Alertes de péremption

- Badge dans le menu : nombre d'alertes
- Liste des lots expirés et expirants (dans 30 jours)
- Catégories : expiré, urgent (< 7j), attention (< 30j), OK, sans DLC

### 8.4. Promotions automatiques

Le système applique automatiquement des réductions :
- Péremption proche : réduction configurée si le lot expire bientôt
- Surstock : réduction si le stock dépasse un seuil

---

## 9. TRANSFERTS INTER-MAGASINS

### 9.1. Page de gestion

**Fichier :** transferts.php
**Accès :** Directeur, Admin uniquement

### 9.2. Processus de transfert

1. Sélection de l'article
2. Sélection du magasin source
3. Sélection du mouvement destination
4. Saisie de la quantité
5. Motif du transfert
6. Validation

### 9.3. Impact sur le stock

Le transfert est atomique et transactionnel :
1. Vérification de la disponibilité au magasin source
2. Décrémentation des lots FEFO au magasin source
3. Transfert des mêmes lots au magasin destination
4. Décrémentation du stock magasin source
5. Incrémentation du stock magasin destination
6. Création de deux mouvements pour traçabilité :
   - Mouvement "Transfert" (sortie) au magasin source
   - Mouvement "Entrée" au magasin destination

---

## 10. RETOURS ET AVOIRS (SAV)

### 10.1. Page de gestion

**Fichier :** retours.php
**Accès :** Directeur, Admin, Vendeur (permission retours_consulter)

### 10.2. Processus de retour

1. Recherche de la facture originale par numéro
2. Sélection des lignes à retourner (quantité limitée au non-déjà-retourné)
3. Soumission du retour
4. Le retour est enregistré avec :
   - Création d'un enregistrement retours_factures
   - Pour chaque ligne : mouvement de stock "Retour_stock" (Entrée)
   - Remboursement proportionnel des points de fidélité

### 10.3. Restrictions

- Le Magasinier ne peut retourner que les factures de son propre magasin
- Les Directeur et Admin peuvent retourner de n'importe quel magasin

---

## 11. PROMOTIONS ET RÈGLES DE VENTE FLASH

### 11.1. Promotions manuelles (codes promo)

**Fichier :** promotions.php
**Accès :** Directeur, Admin (permission promotions_gerer)

Types :
- Montant fixe (réduction en FCFA)
- Pourcentage

Règles :
- Date de début et de fin
- Montant minimum d'achat
- Nombre maximum d'utilisations
- Limité à un article spécifique (optionnel)

### 11.2. Règles de vente flash (automatiques)

La table regles_promotions définit des règles automatiques :
- PEREMPTION_PROCHE : réduction si le lot expire bientôt
- SURSTOCK : réduction si le stock dépasse un seuil

Appliquées automatiquement au scan et à la validation de facture.

---

## 12. DÉPENSES

### 12.1. Page de gestion

**Fichier :** depenses.php
**Accès :** Directeur, Admin

### 12.2. Données d'une dépense

- Titre
- Catégorie (alimentation, loyer, salaires, etc.)
- Montant
- Date
- Description

### 12.3. Impact

Les dépenses sont prises en compte dans le calcul du bénéfice net :
Bénéfice net = Chiffre d'affaires - Coût d'achat marchandises vendues (CAMV) - Dépenses d'exploitation

---

## 13. CONFORMITÉ ET EXPORT COMPTABLE

### 13.1. Page de conformité

**Fichier :** conformite.php
**Accès :** Directeur uniquement

Vérifications :
- Intégrité de la chaîne de hachage des factures
- Continuité de la numérotation
- Complétude des données

### 13.2. Export SYSCOHADA

**Fichier :** export_syscohada.php
**Accès :** Directeur (permission conformite_export_syscohada)

Journaux comptables exportés :
- VT (Ventes) : comptes 707, 4457 (TVA)
- AC (Achats) : comptes 607, 401
- DG (Dépenses) : comptes divers
- INV (Variation de stocks) : comptes 31, 6037

Format : CSV avec séparateur point-virgule, encodage UTF-8 avec BOM

---

## 14. STATISTIQUES ET TABLEAU DE BORD

### 14.1. Tableau de bord

**Fichier :** tableau_bord.php
**Accès :** Tous les rôles connectés

KPI affichés :
- Nombre d'articles actifs
- Valeur totale du stock
- Ventes du jour
- Chiffre d'affaires du jour

Widgets :
- Alertes de stock bas (tableau avec articles en dessous du seuil)
- Derniers mouvements (liste des 8 derniers mouvements)

### 14.2. Statistiques détaillées

**Fichier :** statistiques.php
**Accès :** Directeur, Admin

Fonctionnalités :
- Filtre par période (date début/fin)
- KPI : CA, nombre de ventes, panier moyen, top article
- Compte de résultat : CA - CAMV = Marge brute - Dépenses = Bénéfice net
- Graphique évolution journalière CA vs Dépenses (Chart.js)
- Graphique Top 5 articles vendus

---

## 15. EXPORTS DE DONNÉES

### 15.1. Export CSV

**Fichier :** exports.php
**Accès :** Directeur, Admin, Magasinier

Types d'export :
| Type | Contenu |
|---|---|
| articles | Liste complète des articles avec stock et valeur |
| factures | Liste des factures avec totaux |
| mouvements | Historique des mouvements de stock |
| inventaire | Détail d'une session d'inventaire |

Format : CSV UTF-8 avec BOM, séparateur point-virgule
Limites : 5000 lignes maximum

### 15.2. Impression d'étiquettes

**Fichier :** etiquettes.php
**Accès :** Directeur, Admin, Magasinier

Options :
- Format grille 3x8 (24 étiquettes/A4) ou 4x10 (40 étiquettes/A4)
- Affichage du prix TTC (optionnel)
- En-tête magasin (optionnel)
- Sélection multiple d'articles avec quantité

Chaque étiquette contient :
- Nom du magasin (optionnel)
- Nom de l'article
- Prix TTC
- Code-barres (CODE128)

---

## 16. IMPRESSION (ÉTIQUETTES, TICKETS, CARTES)

### 16.1. Ticket de caisse

**Fichier :** ticket_print.php
**Template :** templates/ticket_print.html.twig

Format : 80mm ou 58mm (configurable)
Contenu :
- En-tête magasin (nom, adresse, Tél, NIF, RCCM)
- Numéro et date de facture
- Vendeur
- Lignes d'articles (détail, remise, quantité, prix)
- Récapitulatif TVA (multi-taux)
- Total TTC
- Points gagnés/utilisés
- Paiements détaillés
- Monnaie rendue
- Code-barres de la facture (CODE128)
- Message de remerciement

Impression automatique après validation.

### 16.2. Facture (version complète)

**Fichier :** facture_view.php

Format : HTML standard (A4)
Contenu complet avec toutes les informations légales :
- En-tête magasin complet
- Informations client (si applicable)
- Détail des lignes avec promotions
- Récapitulatif TVA multi-taux
- Mode de paiement
- Avertissements de conformité (NIF manquant, etc.)

### 16.3. Carte de fidélité

**Fichier :** carte_fidelite.php
**Template :** templates/carte_fidelite.html.twig

Format : 85mm x 55mm (carte de visite)
Contenu :
- Nom du magasin
- Nom du client
- Code fidélité
- Code-barres (CODE128)
- Solde de points

---

## 17. PARAMÉTRAGE DE L'APPLICATION

### 17.1. Page de paramètres

**Fichier :** parametres.php
**Accès :** Directeur, Admin

### 17.2. Paramètres disponibles

| Catégorie | Paramètres |
|---|---|
| Général | Nom de l'application, Nom du magasin |
| Financier | Devise, symbole, position, décimales, séparateur décimal/milliers |
| Fiscal | Régime fiscal (TVA/TPU), Taux TVA par défaut |
| Ticket | En-tête ticket, Remarque ticket, Format ticket (80mm/58mm) |
| Caisse | Modes de paiement, Montants rapides |
| Fidélité | Actif/inactif, Valeur du point, Points minimum d'utilisation |
| Email | Notifications email, destinataire |
| Stock | Méthode de valorisation (CUMP/FIFO) |

---

## 18. JOURNAL D'AUDIT ET TRAÇABILITÉ

### 18.1. Page d'audit

**Fichier :** audit.php
**Accès :** Tous les rôles (avec permission audit_consulter)

Contenu :
- Liste chronologique de toutes les actions
- Filtres : type d'action, recherche texte, période
- Colonnes : date, utilisateur, action, détails, adresse IP

### 18.2. Actions journalisées

| Action | Description |
|---|---|
| CONNEXION | Connexion réussie |
| DECONNEXION | Déconnexion |
| ECHEC_CONNEXION | Tentative échouée |
| MODIFICATION_ARTICLE | Création/modification d'article |
| SUPPRESSION_ARTICLE | Désactivation d'article |
| MOUVEMENT_STOCK | Mouvement de stock manuel |
| VENTE | Vente en caisse |
| RETOUR_SAV | Retour client |
| INVENTAIRE_* | Actions d'inventaire |
| TRANSFERT_STOCK | Transfert inter-magasins |
| COMMANDE_* | Actions commandes fournisseur |
| MODIFICATION_UTILISATEUR | Gestion des utilisateurs |
| CHANGEMENT_MDP | Changement de mot de passe |
| EXPORT_CSV | Export de données |
| SYSCOHADA_EXPORT | Export comptable |
| ECART_CAISSE | Écart de caisse |
| LOT_AJOUTE | Ajout de lot |

### 18.3. Intégrité cryptographique

Les factures utilisent un chaînage SHA-256 :
- Chaque facture est liée à la précédente par un hash
- Toute modification brise la chaîne
- Outil de vérification : bin/verif_integrite.php

---

## 19. SÉCURITÉ

### 19.1. Protection CSRF

- Token CSRF généré par session
- Validation sur tous les formulaires (POST)
- En-tête X-CSRF-Token pour les appels API

### 19.2. Protection XSS

- Fonction h() : htmlspecialchars avec ENT_QUOTES, UTF-8
- CSP (Content Security Policy) avec nonce par requête
- Input validation via input_string()

### 19.3. Protection injection SQL

- Requêtes PDO avec requêtes préparées
- ATTR_EMULATE_PREPARES => false
- Liaison paramétrée

### 19.4. Rate limiting

- Connexion : 5 tentatives par IP en 15 minutes
- Changement de mot de passe : 5 tentatives par session en 15 minutes

### 19.5. Headers de sécurité

- X-Content-Type-Options: nosniff
- X-Frame-Options: DENY
- Referrer-Policy: strict-origin-when-cross-origin
- CSP avec nonce aléatoire

### 19.6. URLs signées

- HMAC-SHA256 pour les liens sensibles
- Expiration de 24 heures
- Vérification via hash_equals()

### 19.7. Immutabilité des données financières

Triggers MySQL empêchent la modification/suppression de :
- Factures (factures)
- Lignes de facture (lignes_facture)
- Paiements (paiements_facture)
- Clôtures de caisse (clotures_caisse)

---

## 20. ANNEXES

### 20.1. Structure de la base de données (26 tables)

Tables métier :
- articles, categories, fournisseurs, magasins, stock_magasins
- article_lots, article_couts

Tables de vente :
- factures, lignes_facture, paiements_facture, clotures_caisse
- retours_factures, lignes_retour

Tables d'approvisionnement :
- commandes_fournisseur, lignes_commande_fournisseur

Tables d'inventaire :
- inventaires, inventaire_lignes, mouvements_stock, transferts_stock

Tables clients :
- clients, historique_points, consentements_log

Tables promotions :
- promotions, regles_promotions

Tables d'administration :
- utilisateurs, permissions, role_permissions, parametres, sequences, depenses

Tables de sécurité :
- login_attempts, logs_activite, archives_caisse
- emails_queue, emails_consentements

### 20.2. Fichiers CLI (bin/)

| Script | Fonction |
|---|---|
| backup_db.php | Sauvegarde BDD + ZIP + rotation 30 jours |
| create_admin.php | Création de compte Directeur (min 12 car.) |
| emails_worker.php | Worker email async (cron) |
| verif_integrite.php | Vérification intégrité chaîne factures |
| disable_demo_accounts.php | Désactivation comptes démo |
| purge_donnees_clients.php | Purge GDPR données clients |

### 20.3. Variables d'environnement (.env)

| Variable | Description |
|---|---|
| DB_HOST | Hôte MySQL |
| DB_NAME | Nom de la base |
| DB_USER | Utilisateur MySQL |
| DB_PASS | Mot de passe MySQL (obligatoire) |
| SECRET_URL_KEY | Clé HMAC (min 32 car., obligatoire) |
| APP_ENV | development ou production |

### 20.4. Constantes de rôle

| Constante | Valeur |
|---|---|
| ROLE_DIRECTEUR | Directeur |
| ROLE_ADMIN | Admin |
| ROLE_MAGASINIER | Magasinier |
| ROLE_VENDEUR | Vendeur |

### 20.5. URLs principales

| URL | Fonction |
|---|---|
| / | Point d'entrée (redirect login) |
| /auth/login.php | Connexion |
| /tableau_bord.php | Tableau de bord |
| /articles.php | Gestion articles |
| /stock.php | Consultation stock |
| /mouvements.php | Mouvements de stock |
| /caisse.php | Point de vente |
| /cloture.php | Clôture de caisse |
| /factures.php | Liste factures |
| /clients.php | Gestion clients |
| /inventaire.php | Inventaire physique |
| /commandes_fournisseur.php | Commandes fournisseurs |
| /transferts.php | Transferts inter-magasins |
| /retours.php | Retours SAV |
| /promotions.php | Promotions |
| /peremptions.php | Péremptions |
| /categories.php | Catégories |
| /fournisseurs.php | Fournisseurs |
| /depenses.php | Dépenses |
| /statistiques.php | Statistiques |
| /exports.php | Exports CSV |
| /utilisateurs.php | Gestion utilisateurs |
| /magasins.php | Gestion magasins |
| /parametres.php | Paramètres |
| /audit.php | Journal d'audit |
| /documentation.php | Documentation |

### 20.6. API REST

| Endpoint | Méthode | Fonction |
|---|---|---|
| /api/articles | GET/POST | Liste/Création articles |
| /api/articles/{id} | PUT/DELETE | Modification/Suppression |
| /api/scan?code=X | GET | Scan code-barres |
| /api/generer_code_barre | GET | Génération EAN-13 |
| /api/caisse/sync | POST | Synchro ventes hors-ligne |
| /api/caisse/status | GET | État de la caisse |
| /api/clients/search?q=X | GET | Recherche clients |
| /api/promo?code=X | GET | Vérification code promo |
| /api/login | POST | Connexion |
| /api/logout | POST | Déconnexion |
| /api/me | GET | Utilisateur courant |
