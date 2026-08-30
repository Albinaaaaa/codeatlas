<?php

return [
    'navigation' => [
        'dashboard' => 'Dashboard',
        'projects' => 'Projects',
        'platform' => 'Platform',
        'repository' => 'Repository',
        'documentation' => 'Documentation',
        'menu' => 'Navigation menu',
        'search' => 'Search',
    ],
    'user_menu' => [
        'settings' => 'Settings',
        'logout' => 'Log out',
        'language' => 'Language',
        'english' => 'English',
        'ukrainian' => 'Ukrainian',
    ],
    'settings' => [
        'title' => 'Settings',
        'description' => 'Manage your profile and account settings',
        'aria_label' => 'Settings',
        'profile' => 'Profile',
        'security' => 'Security',
        'appearance' => 'Appearance',
    ],
    'appearance' => [
        'title' => 'Appearance settings',
        'description' => 'Update the appearance settings for your account',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],
    'projects' => [
        'created_at' => 'Created :date',
        'status' => [
            'connected' => 'Connected',
            'not_connected' => 'No source',
        ],
        'fields' => [
            'name' => 'Name',
            'name_placeholder' => 'My application',
            'description' => 'Description',
            'description_placeholder' => 'What does this project do?',
            'optional' => '(optional)',
        ],
        'index' => [
            'title' => 'Projects',
            'description' => 'Browse and manage your CodeAtlas projects.',
            'new_project' => 'New project',
            'empty_title' => 'No projects yet',
            'empty_description' => 'Create your first project to get started.',
            'create_project' => 'Create project',
            'open_project' => 'Open project',
        ],
        'create' => [
            'title' => 'Create project',
            'breadcrumb' => 'Create',
            'description' => 'Add a project shell now. You can connect a source later.',
            'details_title' => 'Project details',
            'details_description' => 'Give your project a clear name and optional description.',
            'submit' => 'Create project',
            'cancel' => 'Cancel',
        ],
        'show' => [
            'back' => 'Back to projects',
            'empty_title' => 'No source connected yet.',
            'empty_description' => 'Source connections will be available in a future step.',
        ],
    ],
];
