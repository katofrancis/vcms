# Backup and data recovery procedure

> **Names used in this guide.** They are the defaults of a new installation: program folder `C:\xampp\htdocs\vcms`, database `vcms`, backup folder `C:\vcms-backups`, backup files `vcms-backup-*.zip`, scheduled jobs `VCMS-Backup-*`. A system that was upgraded from an earlier version keeps its own names (for example folder `kibatsi-visitation-cards`, database `kibatsi_cards`, backups `kibatsi-backup-*.zip`). Always use the names in **your** `api\config.php`. The tools read both kinds of backup file.

Keep a printed copy of this page next to the school computer. In an emergency, do **not** guess: find your situation in section 4 and follow the steps in order.

## 1. What is protected

Every backup is **one ZIP file** that holds

| Inside the ZIP | What it is |
|---|---|
| `database.sql` | every table: students, guardians, cards, users, settings, promotion history, the secret that signs the QR codes |
| `uploads/` | every student photo and the school logo |
| `manifest.json` | date, program version, number of rows in each table and a checksum (SHA-256) of `database.sql` |

After writing a backup the system **opens the ZIP again and checks the checksum**. A backup that fails the check is renamed `.BAD` and reported as FAILED on the dashboard. A backup you have not been able to verify is not a backup.

**Not inside the ZIP:** the program folder itself (`index.html`, `api`, `assets` ...) and `api/config.php` (it holds the database password). Keep a copy of the whole project folder on a USB drive after any change to `config.php`. The program can always be re-copied from the original ZIP you were given.

**How much can be lost?** Backups run twice a day (12:30 and 18:00). In the worst case you lose the work done since the last backup, which is at most about half a day. You can also press **Back up now** (Backups page) before any risky job, such as promoting the whole school.

## 2. One-time setup (do this on the first day)

1. **Schedule the automatic backup.** Right-click `tools\install-backup-task.bat` and choose **Run as administrator**. It creates two Windows Task Scheduler jobs and runs a test backup. (If the computer is off at 12:30 or 18:00 the backup runs as soon as it is switched on.)
   - Mac/Linux: `crontab -e` and add `30 12 * * * php /path/to/vcms/tools/backup.php` and `0 18 * * * php ...` the same way.
2. **Choose where backups go.** Open `api/config.php`:
   - `BACKUP_DIR`: default `C:\vcms-backups`. It is deliberately **outside** the web folder, so nobody can download it from a browser.
   - `BACKUP_COPY_DIR`: **set this** to a second place that is not the same disk, for example `E:\vcms-backups` (USB drive or second disk) or a folder that a cloud service synchronises. Every backup is copied there automatically. *A backup that sits only on the computer that fails is lost together with it.*
3. **Check that it works.** Open **Backups**. You should see a green "Backups are working" bar and a ZIP file in the list. Open `C:\vcms-backups\backup.log` to read the history.
4. **Do a restore drill** (section 6) once, on a quiet day, so the first time you restore is not during a disaster.

## 3. Routine

| When | What | Who |
|---|---|---|
| Every day | Glance at the dashboard. A red **Backup warning** banner means no good backup in the last 48 hours: open Backups, press **Back up now**, then fix the schedule | Administrator |
| Every Friday | Copy the newest ZIP to a USB drive that you keep away from the computer (home, safe, other office) | Administrator |
| Before promoting the whole school, importing many students, or any big change | Press **Back up now** first | Whoever does the job |
| Once a month | Restore drill (section 6) and read `backup.log` | Administrator |

Old backups are removed automatically: the newest 14 are always kept, plus the newest backup of each of the last 6 months. Safety copies made before a restore (`vcms-prerestore-...zip`) are **never** deleted automatically.

## 4. What happened? Find your situation

### A. "A promotion was a mistake" (wrong class, wrong stream)
No backup needed. **Promotion > Recent promotions > Undo** puts every student back exactly where they were. Students who were moved again afterwards are left where they are and counted as "skipped".

### B. "Some students were deleted or damaged by mistake, but the rest of today's work is fine"
Do **not** restore everything (that would throw away today's good work). Bring back only the missing students. This takes about 10 minutes.

1. Press **Back up now** (so today's state is safe), or skip if one was made recently.
2. Open the newest backup that was made **before** the mistake. Copy it to your desktop, right-click > **Extract all** into a folder, for example `C:\recovery`.
3. Open **phpMyAdmin** (`http://localhost/phpmyadmin`) > **New** > create a database named `vcms_recovery` (collation `utf8mb4_unicode_ci`).
4. Select `vcms_recovery` > **Import** > choose `C:\recovery\database.sql` > **Go**. This is a *side copy* of the old data. Nothing live is touched.
5. Select the **SQL** tab (with `vcms_recovery` still selected) and find who is missing:
   ```sql
   SELECT s.admission_no, s.first_name, s.last_name
   FROM vcms_recovery.students s
   LEFT JOIN vcms.students l ON l.id = s.id
   WHERE l.id IS NULL;
   ```
6. Bring back the ones you choose (replace the admission numbers; run all three statements, **in this order**):
   ```sql
   INSERT IGNORE INTO vcms.guardians
     SELECT g.* FROM vcms_recovery.guardians g
     JOIN vcms_recovery.students s ON s.guardian_id = g.id
     WHERE s.admission_no IN ('ABC/0003', 'ABC/0004');

   INSERT IGNORE INTO vcms.students
     SELECT s.* FROM vcms_recovery.students s
     WHERE s.admission_no IN ('ABC/0003', 'ABC/0004');

   INSERT IGNORE INTO vcms.visitation_cards
     SELECT c.* FROM vcms_recovery.visitation_cards c
     JOIN vcms_recovery.students s ON s.id = c.student_id
     WHERE s.admission_no IN ('ABC/0003', 'ABC/0004');
   ```
   They come back with the **same admission number, class, card number and QR code**.
7. Photos: copy the files from `C:\recovery\uploads\photos\` into `C:\xampp\htdocs\vcms\uploads\photos\` (keep the sub-folder). Choose **Skip** when Windows asks about files that already exist.
8. Delete the side database (`vcms_recovery` > **Operations** > **Delete the database**) and the `C:\recovery` folder, because they contain children's data.
9. Open **Students**, search for one of them, check the details and photo.

If the backup is from an older program version whose table columns differ, the `SELECT *` statements will complain about column counts. Then ask for help rather than forcing it.

### C. "Everything is wrong or gone": database damaged, MySQL will not start, tables empty, a bad import wrecked the data
Restore the latest backup (section 5). You lose only the work since that backup.

### D. "The computer died, was stolen, or is being replaced"
1. On the new computer install **XAMPP**, start **Apache** and **MySQL**.
2. Copy the project folder (from the USB copy or the original ZIP) to `C:\xampp\htdocs\vcms\`. Put your `api\config.php` back (or re-edit the defaults).
3. Copy the newest backup ZIP from your USB drive / second disk to `C:\vcms-backups\`.
4. Do **not** run `install.php`. Open a Command Prompt and follow section 5. The restore creates the database by itself.
5. **Network address:** every printed QR code contains the old computer's address (for example `http://192.168.1.10/...`). Give the new computer **the same address**: in the router, reserve that IP for the new computer ("DHCP reservation"), or set the same static IP in Windows. If you cannot, the cards still *work* on the new computer once the address matches; if the address has to change, the QR codes on already printed cards will not open and the cards must be reprinted.
6. Re-run the scheduled-backup setup (section 2, step 1) and set `BACKUP_COPY_DIR` again.

### E. "Virus, ransomware, or someone tampered with the system"
1. Disconnect the computer from the network. Do not restore onto an infected computer; clean or replace it first.
2. Use a backup from **before** the problem began. If unsure, try the newest backup first and look at the data; if it is damaged, go one backup older (section 5 lets you choose any ZIP).
3. After the restore, **reset every password** (Users > Reset password) and check the activity log (Users & security > Recent activity).
4. Replace backups on the infected computer with the USB copy you keep away from it. That is what the Friday copy is for.

### F. "Only the photos are missing" (database fine)
Extract the newest good backup ZIP somewhere, then copy its `uploads\photos` folder over `C:\xampp\htdocs\vcms\uploads\photos`, choosing **Skip** for files that already exist. Do not run the restore tool for this.

### G. "I restored the wrong backup"
The restore tool saved the system as it was just before the restore, as `vcms-prerestore-<date>.zip` in the backup folder. Restore that ZIP (section 5) to undo the mistake.

### H. "The administrator forgot the password and nobody can sign in"
On the school computer open Command Prompt:
```
cd C:\xampp\htdocs\vcms
C:\xampp\php\php.exe tools\reset-password.php admin
```
It prints a one-time password and unlocks the account; the person must choose a new password at the next sign-in. (A person with another administrator account can use Users > Reset password instead.)

### I. "Backups stopped / the dashboard shows a backup warning"
Open **Backups**. The red bar says why: zip extension off, folder not writable, disk full, task not running. Press **Back up now**. Then in Task Scheduler check that `VCMS-Backup-Midday` and `VCMS-Backup-Evening` exist and that "Last Run Result" is `0x0`. Read `C:\vcms-backups\backup.log`.

## 5. Restoring from a backup (step by step)

The restore tool refuses to continue if any check fails, so it cannot make things worse by accident.

1. **Tell everyone to stop** and sign out. Open XAMPP and make sure **MySQL** is running (Apache can stay on).
2. Open **Command Prompt**:
   ```
   cd C:\xampp\htdocs\vcms
   ```
3. Run the restore (choose one):
   ```
   C:\xampp\php\php.exe tools\restore.php latest
   C:\xampp\php\php.exe tools\restore.php "D:\USB\vcms-backup-20261110-130001.zip"
   ```
   Add `--db-only` to leave the photos folder alone.
4. Read what it shows you and check that it matches what you expect:
   ```
   Checking ... vcms-backup-20261110-130001.zip
     Backup made : 2026-11-10T13:00:01+03:00  (program 3.1, schema 4)
     Students    : 612   Photo files: 618
     Backup is intact.
   ```
   If it says **BACKUP REJECTED** nothing was changed: try an older ZIP.
5. Type `RESTORE` and press Enter. It now
   1. takes a **safety backup** of the current state (`vcms-prerestore-...zip`),
   2. replaces every table with the backup's tables,
   3. moves the current photos to `uploads\_before_restore_<time>\` and puts the backed-up photos back,
   4. upgrades the database to the current program version if the backup is older,
   5. counts every table and every photo.
6. The last lines must be:
   ```
   Every table has exactly the number of rows the backup recorded.
   All student photos referenced in the database are present.
   RESTORE COMPLETE.
   ```
   `MISMATCH` or `WARNING ... missing` lines mean the result differs from the backup: stop and read section 7.
7. **Check by hand** (5 minutes):
   - [ ] Sign in. The dashboard total equals the number the tool printed (`Students: ...`).
   - [ ] Open **Students**, search for three students you know. Details and photos are right.
   - [ ] **Cards & printing**: preview a card, scan its QR code with a phone on the school network. The page must say **VALID CARD**.
   - [ ] **Backups**: press **Back up now** and see it succeed (this also proves the backup system works on the restored data).
   - [ ] Tell staff that anything they entered after the backup time must be entered again, and give them that time.
8. After a few days, delete the `uploads\_before_restore_*` folders to free disk space.

## 6. Restore drill (once a month, 15 minutes)
Prove your backups work **before** you need them.
1. On another computer (or this one after pressing **Back up now**), install XAMPP and copy the project folder.
2. Copy the newest ZIP from your **USB/second copy** (not from the main folder, so you also test that copy).
3. Edit `api\config.php` of the test installation so `DB_NAME` is `vcms_drill` (never drill on the live database).
4. Run `php tools\restore.php "<zip>" --yes`, sign in, check the student count and one photo.
5. Write the date and result in the school's logbook. Delete the drill database and folder afterwards.

## 7. If the restore tool itself fails
| Message | Meaning and fix |
|---|---|
| `PHP zip extension is not enabled` | In `C:\xampp\php\php.ini` remove the `;` before `extension=zip`, restart Apache, run again |
| `BACKUP REJECTED ... checksum` or `damaged` | This ZIP is bad. Use the other copy or an older ZIP |
| `Safety backup FAILED ... Restore cancelled` | The current system could not be saved first (disk full?). Free space and try again. If the current data is worthless (for example ransomware), see below |
| `RESTORE ERROR in statement N` | The database may be half-restored. Run the same restore again; the safety backup is in the backup folder |
| `Can't connect to MySQL` | Start MySQL in the XAMPP Control Panel; check `DB_HOST`, `DB_USER`, `DB_PASS` in `api\config.php` |

**Emergency manual method (no PHP tool):** extract the ZIP; in phpMyAdmin create/select the `vcms` database, **Import** `database.sql` (it drops and recreates each table); copy the extracted `uploads\photos` and `uploads\logo` folders into the project's `uploads` folder. Then open the system once so it upgrades itself.

## 8. Reference

| | |
|---|---|
| Make a backup now | Backups page > **Back up now**, or `php tools\backup.php` |
| Backup folder | `BACKUP_DIR` in `api\config.php` (default `C:\vcms-backups`) |
| History / errors | `backup.log` and `last_backup.json` in the backup folder |
| Restore | `php tools\restore.php <zip or latest> [--db-only] [--yes]` |
| Reset a password | `php tools\reset-password.php <username>` |
| Scheduled jobs | Task Scheduler: `VCMS-Backup-Midday`, `VCMS-Backup-Evening` |
