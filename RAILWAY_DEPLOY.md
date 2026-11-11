# Railway Deployment for Pro Car

This PHP app uses a MySQL database and is deployable on Railway using Docker.

## Local test with Docker

```bash
docker compose up --build
```

Then open:

```bash
http://localhost:8080
```

## Deploy the application and MySQL database

1. Push your repo to GitHub.
2. In your Railway project, add a **MySQL** service with **+ New → Database → MySQL**.
3. Add the application with **+ New → GitHub Repo** and select this repository. Railway uses the included `Dockerfile`.
4. Open the application service's **Variables** and add references to the MySQL service's variables. Replace `MySQL` below with the exact name of your database service if you renamed it:

   | Application variable | Railway reference |
   | --- | --- |
   | `MYSQL_HOST` | `${{MySQL.MYSQLHOST}}` |
   | `MYSQL_PORT` | `${{MySQL.MYSQLPORT}}` |
   | `MYSQL_DB` | `${{MySQL.MYSQLDATABASE}}` |
   | `MYSQL_USER` | `${{MySQL.MYSQLUSER}}` |
   | `MYSQL_PASS` | `${{MySQL.MYSQLPASSWORD}}` |

   You can also use Railway's **Add Reference** picker instead of entering the references manually. Keep the database private; the application connects over Railway's private network and does not need a public database URL.
5. Generate a public domain for the application service in **Settings → Networking** and deploy.
6. On the first API request, the application creates its tables and sample fleet in the configured MySQL database.

## Verify

```bash
curl https://<your-app-domain>/backend/cars_api.php
```

The cars endpoint should return JSON containing a `cars` array. If it returns an error, check the application service's deployment logs and verify each variable reference resolves to a value. Do not paste database passwords or connection URLs into source control, chat, or public logs.

## Notes

- `backend/db.php` uses the MySQL connector; it accepts both the app-specific variable names above and Railway's native MySQL variable names.
- Schema initialization runs on the first API request, not at image build time.
- Keep secrets in Railway Variables only.
