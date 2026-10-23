# Helpdesk Ticketing System

A public ticket intake page and authenticated Admin/IT Staff workspace, using the included Bootstrap template. The backend uses PHP and MariaDB. The existing HTML, CSS, and JavaScript frontend remains in place.

## Run locally

1. Start Apache and MariaDB (for example, through XAMPP) and enable PHP's PDO MySQL extension and Apache `mod_rewrite`.
2. From this directory, run `composer install` and `php setup.php` once to install PHPMailer and create/migrate the database, demo accounts, uploads directory, and private OTP secret. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the PHP/Apache environment when your MariaDB credentials differ from the defaults.
3. Open the project through Apache at `http://localhost/HelpDesk/PurpleAdmin-Free-Admin-Template/`. The API URL follows the project path, so it works from this XAMPP subfolder.

The root page is public. Anyone can submit a ticket with a title, description, optional name/email, and optional PNG/JPEG/GIF screenshots. New tickets default to `Open` with `Medium` priority. After submission, the confirmation displays a clickable ticket ID, a private reference code, and copy actions. Public users can later use **Look up your ticket** with both values. The lookup is read-only and only returns that public ticket's details and current status; it cannot display tickets created for signed-in users or expose staff controls. Keep the reference code private. Admins and IT Staff use the login link in the public page header.

When the users table is empty, the setup script creates `admin` and `itstaff` accounts with separate random passwords and prints them once in the terminal. Save these credentials securely and change them after signing in. Uploaded files are kept in `data/uploads`; make sure PHP can write to that directory. Configure PHP's native mail transport in `php.ini` and set `MAIL_FROM` to a valid sender address for verification messages. PHPMailer uses its `isMail()` transport, so messages go through PHP's `mail()` implementation. On Windows XAMPP, PHP's `SMTP=localhost` and `smtp_port=25` submit to Mercury; configure Mercury's SMTP server to permit relaying from `127.0.0.1` only, then configure an outbound smart host if your network/provider requires one. Do not enable unrestricted relay. If `MAIL_FROM` and `sendmail_from` are unset, the app uses `no-reply@localhost.localdomain`, which is suitable only if the local mail relay accepts it; use a sender address authorized by your SMTP relay for real delivery. `APP_SECRET` may be set in the PHP/Apache environment; otherwise `setup.php` creates a private HMAC secret in the web-blocked `data` directory.

## Workflow

- Public visitors create tickets without an account. They can attach multiple screenshots.
- IT Staff and Admin users sign in, view and search tickets, assign IT Staff, change priority/status, prepare and print completion forms, and upload signed PDF/image copies. Closing a ticket is rejected until a signed form has been uploaded.
- Admins create, edit, and disable accounts. Each account's category is `Admin` or `IT Staff`; filtering and active user counts use that category. Ticket type/category fields are not used for new tickets.
- The dashboard reports counts by status, priority, and requester.

## API

Authenticated `/api` requests use a same-site, HttpOnly session cookie. Error responses contain an `error` message.

| Method | Endpoint | Access | Purpose |
| --- | --- | --- | --- |
| POST | `/api/public/tickets` | Public | Submit ticket and screenshot files |
| GET | `/api/public/tickets/{id}?access_key={access_key}` | Public link holder | Read the submitted details and current status of one public ticket using its private reference code |
| POST | `/api/login`, `/api/login/verify`, `/api/login/cancel`, `/api/logout` | Public / signed-in | Password login, verify/cancel email 2FA challenge, or sign out |
| GET | `/api/me`, `/api/tickets`, `/api/reports` | Admin, IT Staff | Workspace data |
| PUT | `/api/me` | Admin, IT Staff | Update profile name or Email 2FA setting |
| POST | `/api/me/email-code`, `/api/me/email-verify` | Admin, IT Staff | Send and verify an email address before saving it or enabling Email 2FA |
| POST | `/api/me/avatar` | Admin, IT Staff | Upload a JPG, PNG, or WebP avatar (3 MB maximum) |
| GET | `/api/me/avatar` | Admin, IT Staff | Retrieve the signed-in user's avatar |
| POST | `/api/me/password` | Admin, IT Staff | Change the current user's password |
| GET | `/api/users?category=Admin` or `?category=IT%20Staff` | Admin | List/filter users by user category |
| GET | `/api/users/stats` | Admin | Active counts by user category |
| GET | `/api/assignees` | Admin, IT Staff | List active IT Staff and Admin assignees |
| PATCH | `/api/tickets/{id}` | Admin, IT Staff | Assign, set priority/status; close requires a signed form |
| DELETE | `/api/tickets/{id}` | Admin | Delete ticket and attachments |
| POST | `/api/tickets/{id}/form` | Admin, IT Staff | Create/reuse a pending completion form before printing |
| PUT | `/api/tickets/{id}/form` | Admin, IT Staff | Upload signed PDF/image and comments |
| POST | `/api/users` | Admin | Create an Admin or IT Staff account |
| PUT, DELETE | `/api/users/{id}` | Admin | Edit name/category or disable user |
| GET | `/api/files/{id}` | Admin, IT Staff | View ticket attachment |

## Data model

`users.category` stores the user category (`Admin` or `IT Staff`), separate from ticket status and priority. `tickets` contains title, description, requester details, status, priority, and timestamps. `user_ticket` links ticket assignees. `forms` and `ticket_form` store completion form status/comments and their ticket links. `ticket_files` stores screenshot/signed-form metadata; file bytes are stored in `data/uploads`. `activity` records ticket events. No ticket category is required for new submissions.

Passwords use PHP's `password_hash`/`password_verify`. Uploads have randomized storage names, an 8 MB per-file limit, and MIME-type allowlists. Apache serves the PHP API and the existing static frontend through `index.php` and `.htaccess`.
