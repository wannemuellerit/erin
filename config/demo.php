<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo-Zugänge
    |--------------------------------------------------------------------------
    |
    | Diese Zugänge werden ausschließlich bei aktiviertem APP_DEMO_MODE auf
    | dem Login angezeigt. Produktionsumgebungen müssen den Modus deaktivieren.
    |
    */

    'password' => 'password',

    'accounts' => [
        [
            'id' => 'superadmin',
            'name' => 'Wannemüller Admin',
            'email' => 'admin@wannemueller.dev',
            'role' => 'super_admin',
        ],
        [
            'id' => 'mueller',
            'name' => 'Marie Müller',
            'email' => 'unternehmen.mueller@wannemueller.dev',
            'role' => 'employer',
        ],
        [
            'id' => 'rheincargo',
            'name' => 'Daniel Schneider',
            'email' => 'unternehmen.rheincargo@wannemueller.dev',
            'role' => 'employer',
        ],
        [
            'id' => 'candidate01',
            'name' => 'Anna Kowalska',
            'email' => 'candidate01@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate02',
            'name' => 'Marek Nowak',
            'email' => 'candidate02@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate03',
            'name' => 'Elena Popescu',
            'email' => 'candidate03@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate04',
            'name' => 'Andrei Ionescu',
            'email' => 'candidate04@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate05',
            'name' => 'Ivana Horvat',
            'email' => 'candidate05@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate06',
            'name' => 'Luka Kovač',
            'email' => 'candidate06@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate07',
            'name' => 'Sofía García',
            'email' => 'candidate07@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate08',
            'name' => 'Tiago Silva',
            'email' => 'candidate08@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate09',
            'name' => 'Marta Fernández',
            'email' => 'candidate09@wannemueller.dev',
            'role' => 'candidate',
        ],
        [
            'id' => 'candidate10',
            'name' => 'João Costa',
            'email' => 'candidate10@wannemueller.dev',
            'role' => 'candidate',
        ],
    ],

];
