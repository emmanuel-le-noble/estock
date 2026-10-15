-- Restaure les accès RBAC au journal d'activité et à la gestion des droits.
INSERT IGNORE INTO `permissions` (`cle_permission`, `description`, `categorie`)
VALUES
  ('audit_consulter', 'Consulter le journal d’activité', 'Administration'),
  ('utilisateurs_gerer', 'Gérer les utilisateurs et les droits', 'Administration');

INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'Directeur', `id`
FROM `permissions`
WHERE `cle_permission` = 'audit_consulter';

INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT roles.role_nom, p.id
FROM (SELECT 'Directeur' AS role_nom UNION ALL SELECT 'Admin') AS roles
CROSS JOIN `permissions` p
WHERE p.cle_permission = 'utilisateurs_gerer';
