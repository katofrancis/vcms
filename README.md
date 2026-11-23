# Visitation Cards Management System

Student visitation cards with a QR verification code, for any school. You install it on the school's own computer, enter the school's information once, and it prints cards carrying that school's name, logo and details. HTML, CSS and JavaScript front end, PHP and MySQL back end. Runs on XAMPP and does not need internet (the PDF and QR libraries are included).

Version 4.0. This is a **single-school system**: one installation serves one school. Give each school its own copy.

Each student has one card, which stays valid while the student is enrolled. Admission numbers start with the school's own initials, for example `GHSS/0001`.

## 1. Install on XAMPP (Windows)

1. Install XAMPP (PHP 8.0 or newer). Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Unzip this package so the program is in `C:\xampp\htdocs\vcms\` (you can use another folder name; it becomes part of the address).
3. Open `http://localhost/vcms/install.php`. All checks should say OK. Enter your name, a username and a password, then press **Install**. This creates the database and your administrator account only.
4. Open `http://localhost/vcms/` and sign in.
5. **Enter the school's information.** The Dashboard shows a setup form the first time:
   - **School name**, and the **school initials** (suggested from the name, for example *Green Hills Secondary School* gives `GHSS`). The initials start every admission number, so choose them carefully: they cannot be changed once students are registered.
   - Motto, address, contact numbers, the label under the signature on the card (for example Headteacher), the card colour, and the school logo.
   - A live preview shows how the top of the card will look. Press **Save school information**.

   From then on the school's name and logo appear on the sign-in screen, the sidebar, the Dashboard, every visitation card and the QR verification page.
6. Delete `install.php` once you are done. Then follow the **Getting started** list on the Dashboard: classes and streams, the verification address (section 3), the first backup, then register students.
7. Set up the backups (`docs/RECOVERY.md`, section 2): right-click `tools\install-backup-task.bat`, choose Run as administrator, and set `BACKUP_COPY_DIR` in `api\config.php`.

If MySQL has a password or another port, edit `api/config.php` before step 3. If a check says GD or zip is missing, remove the `;` before `extension=gd` or `extension=zip` in `C:\xampp\php\php.ini` and restart Apache. Without zip, backups and Excel upload do not work.

## 2. Upgrading an installed system (keeps all data)

Use the **upgrade patch** (`vcms-4.0-upgrade-patch.zip`), not the full package. It was tested on installations of version 3.1 and version 2.0 (versions in between use the same database upgrade steps). Everything stays: students, photos, users, settings, the school logo, and your `api\config.php`.

1. Unzip the patch anywhere (for example on the desktop).
2. Make sure **MySQL is running** in XAMPP.
3. Double-click `apply-patch.bat` and type the folder of the installed system (for example `C:\xampp\htdocs\vcms`). Or in a Command Prompt:
   `C:\xampp\php\php.exe apply-patch.php "C:\xampp\htdocs\vcms"`
   (add `--dry-run` first if you only want to see what would change).
4. The patch tool stops at the first problem. In order it: takes a **verified backup** of the database and photos, saves the old program files into a ZIP (so the upgrade can be undone), copies the new files, upgrades the database and reports the student count.
5. Open the system and press **Ctrl+F5** once, then sign in. A school that already has a name does **not** see the setup form; its details are kept as they are.

To undo: `C:\xampp\php\php.exe apply-patch.php --rollback "<the saved ZIP>" "<the installed folder>"`. The patch tool prints the exact command at the end.

What changes for an upgraded school: the product is renamed in the screens, new backups are named `vcms-backup-*.zip` (older `kibatsi-backup-*.zip` files stay listed, restorable and pruned as before), and the scheduled tasks are recreated under new names if you run `tools\install-backup-task.bat` again. Admission numbers already issued do not change, and the school's initials become fixed as soon as students exist.

## 3. Use it from several computers

The server is the computer running XAMPP. Everyone else opens a browser.

1. Give the server a fixed address (reserve an IP in the router), for example `192.168.1.10`. Find the current one with `ipconfig`.
2. Allow web traffic through the Windows firewall (Command Prompt as administrator):
   `netsh advfirewall firewall add rule name="VCMS web" dir=in action=allow protocol=TCP localport=80`
   Do not open port 3306.
3. On the other computers open `http://192.168.1.10/vcms/`.
4. Under **Card design** enter the same address in **Verification address** (for example `http://192.168.1.10/vcms`). This is what the QR codes contain, so phones on the school Wi-Fi can open them. `localhost` does not work from a phone. If the address changes, printed cards stop opening, so keep it fixed. The Dashboard warns you until this is set.

## 4. Users and permissions

Add staff under **Users & security**. A one-time password is shown once and the person chooses their own at first sign-in.

| Role | Standard permissions |
|---|---|
| System administrator | everything |
| School administrator | everything |
| Data entry officer | view students, register and edit, upload photos, reports |
| Printing officer | view students, print cards, reports |

Permissions can be changed for each person, and take effect at once, even for people already signed in. Three wrong passwords lock that account for 10 minutes. The administrator can reset or unlock any account. If nobody can sign in, run `php tools\reset-password.php <username>` on the server.

Until the school information is saved, only the administrator can use the system (the Dashboard shows the setup form) and other staff see a message that the school is not set up yet.

## 5. Daily use

1. **Register students** one by one or from an Excel file. Leave the admission number empty; the system gives the next one (`GHSS/0001`, `GHSS/0002`, ...).
2. **Photos**: use the Photo button on a student's row, or upload many at once under **Photos**. Name each file after the admission number (`GHSS/0001` becomes `GHSS_0001.jpg`) or after the student (`Anna Okello.jpg`; capital letters and the order of the names do not matter). A photo is saved only when exactly one enrolled student matches. If two students have the same name the file is not saved and you are told to use the admission number. The **Name list** button (under Students and under Photos) downloads the names and admission numbers of a class or stream as a CSV file, with the file name each photo should have. The result for each file is listed.
3. **Cards and printing**: filter by class or stream, tick the cards (or tick none to print all that match) and press Print or Save PDF. Four cards fit on one A4 page. In the print dialog choose Portrait, Margins: None, and tick Background graphics.
4. **Checking a card**: scan the QR code with a phone. The page shows the school, the photo, name and class, with VALID or NOT VALID. Compare the photo with the person. Guardian phone numbers and addresses are never shown.

### Promoting students

1. Press **Back up now** first.
2. **Promotion**: use "Promote the whole school" (S6 leave, S5 move to S6, and so on) or move one class or selected students. The move happens all together and can be undone from the history list.
3. The card of a moved student is marked to print again because the class on it changed.

### Changing the school's details later

**Card design** changes the name, motto, address, contact, colour, font, logo, card size and verification address at any time. The initials can be changed only while no student has been registered.

## 6. What is checked

| Field | Rule |
|---|---|
| School initials | 2 to 6 capital letters; fixed once students exist |
| Names | letters only |
| Admission number | given by the system, unique, never changes |
| Class and stream | the stream must exist for that class |
| Contact number | digits only, 10 to 12 digits |
| Duplicates | same name and same guardian contact is refused |
| Photo | JPG, PNG, GIF or WebP up to 10 MB; stored at most 480 px tall |
| Editing at the same time | the second person to save is told and nothing is overwritten |

## 7. Limits to know before you give it to a school

- Classes are **S1 to S6** (secondary school). A primary school or another class system is not supported yet.
- Contact numbers follow the Ugandan pattern (10 to 12 digits, and `256` at the start is read as `0`).
- One installation holds one school.

## 8. Capacity and speed

Measured on a small test computer (PHP 8.3, MariaDB) with a school of **5,001 students, each with a photo** (118 MB of photos):

| Action | Server time |
|---|---|
| Open a page of 25 students, with their small photos (8 KB) | 3 ms; 20 ms deep in the list |
| Search by name, admission number or phone | 10 ms |
| Dashboard figures | 10 ms |
| Export all 5,001 students to CSV (515 KB) | 32 ms |
| Backup of everything (database and 10,002 photo files) | 2.6 s, an 86 MB file |
| Restore after losing everything | 1.5 s |
| 12 people loading lists at the same time, non-stop | 70 requests a second, median 115 ms, no errors |

These are times measured on the server itself; the school network adds a little transfer time. A real school has far fewer people working at the same moment than the 12 tested.

Plan about **25 KB of disk per student** for photos, and about 86 MB per backup for a school of 5,000 (the 14 newest backups plus one per month are kept, so reserve about 2 GB for backups).

Why it stays fast:


- Lists show small photos that arrive inside the list itself, so a page of 50 students is one request instead of 51.
- Stored photos are at most 480 px, which is enough for a card printed at 300 dpi, and the browser keeps them for a year.
- The PDF library loads only when you press Save PDF. Photos for a PDF are fetched several at a time.
- For the best speed on XAMPP, check that OPcache is on: in `C:\xampp\php\php.ini` there should be `zend_extension=opcache` and `opcache.enable=1`. Restart Apache after changing it.

## 9. Security

- Every query is a prepared statement, output is escaped, every change needs a CSRF token, and sessions end after 30 minutes without use.
- Photos are shown only to signed-in staff. Backups, tools and docs are blocked from the web.
- The QR page answers only for a card number with its signature. Wrong guesses are slowed down and logged.
- A QR code can be photocopied, so always compare the photo with the person.

## 10. Folders

`index.html` and `assets/` the program, `api/` server code, `verify.php` the QR page, `install.php` the one-time installer, `database/` the table definitions, `uploads/` photos and logo, `tools/` backup, restore and password reset (command line), `docs/RECOVERY.md` the recovery procedure.

RwenzoriStack Systems Ltd (c) All rights reserved.
