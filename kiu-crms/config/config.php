<?php
/*
 * Settings for the KIU Complaints and Request Management System.
 * The defaults below work with a fresh XAMPP install (user "root", no password).
 */
return [

    // Database connection (XAMPP defaults)
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'kiu_crms',
        'user' => 'root',
        'pass' => '',
    ],

    'app' => [
        'name'               => 'KIU Complaints & Requests Desk',
        'timezone'           => 'Africa/Kampala',
        'db_timezone_offset' => '+03:00',   // must match the timezone above
        'secret'             => 'change-this-to-any-long-random-text', // used when hashing IP addresses
        'base_url'           => '',         // leave empty: the app finds its own folder
    ],

    // Show PHP errors on screen. Set to false when the system goes live.
    'debug' => true,

    /*
     * The SLA clock.
     * 'hours'   = real life (a limit of 24 means 24 hours).
     * 'minutes' = demo mode (a limit of 24 means 24 minutes).
     * 'seconds' = fast demo mode (a limit of 24 means 24 seconds).
     * Change this to 'minutes' or 'seconds' when you want to show escalation to your panel.
     */
    'sla_unit' => 'hours',

    // How long a resolved case waits for the student to confirm before it closes by itself.
    // Counted in the same unit as sla_unit (168 hours = 7 days).
    'auto_close_hours' => 168,

    // The system checks for overdue cases whenever someone opens a page, but not more often than this.
    // 0 turns it off (use the cron/escalate.php script instead).
    'lazy_escalation_interval' => 30, // seconds

    // The AI suggestion is only trusted when it is at least this sure (0 to 1).
    'ai_min_confidence' => 0.40,

    'upload' => [
        'max_bytes' => 5 * 1024 * 1024,
        'allowed'   => ['jpg', 'jpeg', 'png', 'pdf'],
    ],

    // Real email is optional. While 'enabled' is false, every email is saved in the
    // email_outbox table and shown at Admin > Emails, so nothing is lost and you can screenshot it.
    // To send real email run "composer require phpmailer/phpmailer" in this folder, then fill these in.
    'mail' => [
        'enabled'    => false,
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'secure'     => 'tls',
        'user'       => '',
        'pass'       => '',
        'from_email' => 'noreply@example.com',
        'from_name'  => 'KIU Complaints Desk',
    ],
];
