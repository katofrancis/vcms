<?php
/**
 * Visitation Cards Management System - configuration.
 * Defaults match a fresh XAMPP install (MySQL user "root", empty password).
 */
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'vcms';
const DB_USER = 'root';
const DB_PASS = '';

define('APP_ROOT', dirname(__DIR__));      // project folder (contains index.html, uploads/ ...)

// ---- login protection ----
const MAX_FAILS    = 3;     // wrong passwords allowed per ACCOUNT (and per computer+username); the next attempt is blocked
const IP_MAX_FAILS = 20;    // wrong entries from one computer (any usernames) before that computer is blocked
const LOCK_MINUTES = 10;    // how long a lock lasts (at least 5)
const IDLE_MINUTES = 30;    // automatic sign-out after this much inactivity

// ---- backups (see docs/RECOVERY.md) ----
// Keep BACKUP_DIR OUTSIDE the web folder. Use a different physical drive than the system if you can.
define('BACKUP_DIR', DIRECTORY_SEPARATOR === '\\' ? 'C:\\vcms-backups' : dirname(APP_ROOT) . '/vcms-backups');
// Optional second copy (USB drive, other disk, network share, cloud-sync folder). Leave '' to disable. Example: 'E:\\vcms-backups'
const BACKUP_COPY_DIR = '';
const BACKUP_KEEP_LAST   = 14;   // always keep the newest N backups
const BACKUP_KEEP_MONTHS = 6;    // ...plus the newest backup of each of the last N months
const BACKUP_WARN_HOURS  = 48;   // the dashboard warns when the last good backup is older than this

// ---- QR verification ----
// Leave '' to detect automatically, or set the address phones will use, e.g. 'http://192.168.1.10/vcms'.
// It can also be set under Card design in the program. "localhost" never works from a phone.
const VERIFY_BASE_URL = '';
