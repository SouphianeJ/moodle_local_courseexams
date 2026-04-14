# `moodle_local_courseexams`

Plugin Moodle autonome centré sur une seule page:

- saisie manuelle d'un `courseid`
- contrôle d'accès strict: rôle `teacher` ou `editingteacher` requis sur le cours
- centralisation des activités `assign` et `quiz`
- dates, visibilité, URLs, paramètres utiles
- dérogations individuelles et de groupe détaillées
- questions de quiz par slot
- rafraîchissement automatique côté navigateur

## Page

- `/local/courseexams/index.php`

## Installation

Copier le plugin dans `local/courseexams`, puis lancer l'upgrade Moodle.

## Notes techniques

- pas d'endpoint MCP
- pas de web service externe requis
- endpoint JSON interne: `/local/courseexams/ajax.php`
