# CEO Campus Result Checker

A PHP and MariaDB app for a school: teachers create student logins and enter marks; students use their student number and private PIN to see their latest result.

## Try the fictional demo accounts

The student portal includes five built-in sample logins. Use a student number and PIN displayed on the portal to view example results. These are fictional demo accounts, not real student records. Real student records still require the database and teacher-account setup below.

### Demo teacher login

For testing only, the local XAMPP database has a demo teacher account:

| Username | Password |
| --- | --- |
| `demo.teacher` | `CampusDemo2026` |

MySQL must be running in XAMPP for teacher login to work. Use this account only with fictional demo data. Before using real student records, remove it in phpMyAdmin's **SQL** tab with `DELETE FROM teachers WHERE username = 'demo.teacher';` and create a private teacher account instead.

## Install in XAMPP on Windows

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this project into `C:\xampp\htdocs\student-results`.
3. Generate a private database password in PowerShell with `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Open `C:\xampp\htdocs\student-results\config.php` and replace `DB_PASSWORD` with that value. Keep it private.
4. The database schema has already been imported on this computer. In phpMyAdmin, open the **SQL** tab and run `ALTER USER 'student_app'@'localhost' IDENTIFIED BY 'PASTE_YOUR_GENERATED_PASSWORD_HERE';`, replacing the example text with the same generated password from step 3. For a fresh installation instead, replace the example password in `database.sql` with your generated value, then use **Import** in phpMyAdmin to import the file.
5. Open PowerShell and create the first teacher account. Choose a unique username and a password of at least 12 characters when prompted:

   ```powershell
   C:\xampp\php\php.exe C:\xampp\htdocs\student-results\create_admin.php
   ```

6. Delete `C:\xampp\htdocs\student-results\create_admin.php` after creating the teacher account.
7. Open `http://localhost/student-results/`. Teacher entry is at `http://localhost/student-results/admin.php`.

## Entering results

Sign in to the teacher portal. Add each student once; the system generates a PIN and shows it only once. Give the student number and PIN to that student privately. Then enter the student's report for an academic year and term. A student can have one report per term; their portal shows the most recently entered report.

## Protect student information

This is a starter app for a local XAMPP setup, **not a production-ready school information system**. Keep it on a trusted school network and do not expose XAMPP to the internet. Before using real student records, arrange HTTPS, backups, restricted server and database access, a school-approved privacy policy, and security review. Keep database credentials private, never share student details or PINs in chat, and securely deliver PINs to students. The app stores student and teacher PINs/passwords as password hashes and uses prepared database statements; student lookup and teacher login are temporarily locked after repeated failed attempts.
