<?php
// Copy this file to config.php and fill in your values.
// config.php is blocked from web access by .htaccess.

// --- Database (STRATO: Kundenlogin -> Datenbanken und Webspace -> Datenbankverwaltung)
const DB_HOST = 'rdbms.strato.de';      // host shown in your STRATO database overview
const DB_NAME = 'dbs00000000';
const DB_USER = 'dbu00000000';
const DB_PASS = 'change-me';

// --- Login password for the web client.
// Generate the hash with install.php and paste it here.
const ADMIN_PASSWORD_HASH = '';

// --- Shared secret between GitHub Actions and this web app.
// Use a long random string (install.php generates one) and store the same value
// as the GitHub secret OPT_API_TOKEN.
const API_TOKEN = '';

// --- E-mail sender. On STRATO this must be a mailbox of your own domain.
const MAIL_FROM = 'preorder-alarm@your-domain.de';

const APP_TIMEZONE = 'Europe/Berlin';
