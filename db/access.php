<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/courseexams:view' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:view',
    ],
];
