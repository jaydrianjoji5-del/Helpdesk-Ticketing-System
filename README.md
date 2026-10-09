# Helpdesk Ticketing System

A public ticket intake page and authenticated Admin/IT Staff workspace, using the included Bootstrap template. The backend uses Python's standard library and SQLite; there are no third-party Python packages to install.

## Run locally

1. Install Python 3.9 or newer.
2. From this directory, run `py -3 server.py` (or `python server.py`).
3. Open `http://127.0.0.1:8000`.

The root page is public. Anyone can submit a ticket with a title, description, optional name/email, and optional PNG/JPEG/GIF screenshots. New tickets default to `Open` with `Medium` priority. Admins and IT Staff use the login link in the public page header.

On first run, the server creates `data/helpdesk.sqlite3` and `data/uploads`. It seeds these demo accounts:

| User category | Username | Password |
| --- | --- | --- |
| Admin | `admin` | `Helpdesk123!` |
| IT Staff | `itstaff` | `Helpdesk123!` |

Change the demo passwords before using the application with real data. On an existing database, startup adds the `users.category`, `tickets.requester_name`, and `tickets.requester_email` fields; old Staff accounts are disabled because request submitters no longer log in.

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
| POST | `/api/login`, `/api/logout` | Public / signed-in | Start or end a session |
| GET | `/api/me`, `/api/tickets`, `/api/reports` | Admin, IT Staff | Workspace data |
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

Passwords use PBKDF2-HMAC-SHA256. Uploads have randomized storage names, an 8 MB per-file limit, and MIME-type allowlists. The server binds to localhost by default. Configure `HELPDESK_HOST` and `HELPDESK_PORT` to change its bind address and port. This standard-library server is intended for a local project/demo; use HTTPS, persistent session storage, CSRF protection, backups, and a production server/database before exposing it to a network.
