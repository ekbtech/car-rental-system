<<<<<<< HEAD
# Pro Car — Online Car Rental System

Modern, responsive car rental platform built with:

- **Frontend:** HTML5, CSS3, JavaScript, Bootstrap 5
- **Backend:** PHP
- **Database:** MySQL via WAMP local server

## Features

### User Roles

- **Customers:** Register, browse cars, book vehicles, view bookings, make payments
- **Company:** Manage inventory, set prices, monitor bookings
- **Admin:** Manage users, cars, bookings, and generate reports

### Core Features

1. **User Registration & Authentication** - Secure login/register with password hashing
2. **Car Search & Browsing** - Filter by category, price, year
3. **Booking Management** - Create, view, and cancel bookings
4. **Payment Processing** - Track payment status
5. **Admin Dashboard** - Manage cars, bookings, and users
6. **Responsive Design** - Modern dark theme with amber accents (matching Hire With Us)

## Installation

### Prerequisites

- PHP 8.4+
- WAMP or another MySQL-enabled local server stack
- Web browser

### WAMP Setup (recommended)

1. Copy this folder to `C:\wamp64\www\procar`.
2. Start **Apache** and **MySQL** in the WAMP control panel.
3. Copy `.env.example` to `.env`. The defaults work with a standard WAMP MySQL installation (`root` with a blank password). Update `MYSQL_USER` and `MYSQL_PASS` if your WAMP installation uses different credentials.
4. Open the Apache port shown in the WAMP control panel. In a standard WAMP installation this may be `http://localhost/procar/index.html`; if Apache is configured on another port, include it, for example `http://localhost:8081/procar/index.html`.

This repository is currently on the Desktop. WAMP will not serve it from `localhost/procar` until you copy it into `C:\wamp64\www\procar`. Alternatively, double-click `start_procar_server.bat`; it serves the current folder directly and opens a working URL such as `http://127.0.0.1:8082/index.html`.

The first request automatically creates the `pro_car` database, tables, sample cars, and the development admin account:


Change the admin password before using this outside local development.

### PHP Development Server

If Apache is not available, PHP 8.1+ and the `pdo_mysql` extension are required:

```bash
php -S 127.0.0.1:8080 -t .
```

Then open `http://127.0.0.1:8080/index.html`. The included `start_procar_server.bat` starts the same server with the WAMP PHP runtime when available.

## Project Structure

```
pro car/
├── index.html                  # Home page
├── cars.html                   # Car browsing & booking
├── dashboard.html              # User/Admin dashboard
├── review.html                 # Customer review flow
├── auth/
│   ├── login.html              # Login page
│   └── register.html           # Registration page
├── backend/
│   ├── db.php                  # Database connection and schema bootstrap
│   ├── auth.php                # Authentication API
│   ├── cars_api.php            # Cars CRUD API
│   ├── bookings.php            # Bookings API
│   ├── payments.php            # Payments API
│   ├── reviews.php             # Review endpoint
│   └── reports.php             # Admin summary reports
├── css/
│   └── style.css               # Main stylesheet (dark theme)
├── js/
│   ├── api.js                  # API base path helper
│   ├── app.js                  # Home page logic
│   ├── auth.js                 # Auth page logic
│   ├── cars.js                 # Cars page logic
│   ├── dashboard.js            # Dashboard logic
│   └── review.js               # Review summary helpers
└── README.md
```

## Database Schema

### Key Tables

- `users` - Customers, drivers, admins, company staff
- `cars` - Vehicle inventory
- `bookings` - Rental bookings
- `payments` - Payment records
- `ratings` - User reviews
- `roles` - User role definitions
- `companies` - Rental companies

## API Endpoints

### Authentication

- `POST backend/auth.php?action=register` - Register new user (returns a 2FA enrollment challenge)
- `POST backend/auth.php?action=login` - User login
- `POST backend/auth.php?action=logout` - User logout

### Cars

- `GET backend/cars_api.php` - List cars with filters (category, price, year)
- `GET backend/cars_api.php?admin=1` - List all cars (admin)
- `POST backend/cars_api.php` - Add car (admin)
- `PUT backend/cars_api.php` - Update car (admin)
- `DELETE backend/cars_api.php` - Delete car (admin)

### Bookings

- `GET backend/bookings.php?user_id=ID` - Get user bookings
- `GET backend/bookings.php?admin=1` - Get all bookings (admin)
- `POST backend/bookings.php` - Create booking
- `PUT backend/bookings.php` - Update booking status (admin)
- `DELETE backend/bookings.php` - Cancel booking

### Payments

- `GET backend/payments.php?booking_id=ID` - Get payment info
- `POST backend/payments.php` - Create payment

## Testing

### Test Credentials

if you dont have an account you
first register ....then after registering you log in into your account

**Sample Car:**

- Make: Toyota
- Model: Corolla
- Year: 2020
- Category: Sedan
- Price: $35/day

Insert via SQL:

```sql
INSERT INTO users (name, email, phone, password_hash, role_id, is_active)
VALUES ('Admin', 'admin@procar.local', '0123456789', '$2y$10$...', 4, 1);
```

## Features Demo

1. **Home Page** - View featured cars and statistics
2. **Register** - Create customer or company account
3. **Browse Cars** - Search and filter vehicles
4. **Book Car** - Select dates and complete booking
5. **Dashboard** - View bookings and manage account
6. **Admin Panel** - Manage cars, bookings, users

## Mandatory Google Authenticator 2FA

Every account must enroll in a compatible authenticator and verify a six-digit code after the password step. Existing sessions are invalidated unless the server session has completed 2FA.

2FA endpoints:

- `POST backend/two_factor.php?action=enrollment_start`
- `POST backend/two_factor.php?action=enrollment_confirm`
- `POST backend/two_factor.php?action=login_verify`
- `POST backend/two_factor.php?action=recovery_verify`
- `POST backend/two_factor.php?action=replacement_start`
- `POST backend/two_factor.php?action=regenerate_recovery_codes`
- `POST backend/two_factor.php?action=admin_reset`

Configure `TOTP_ENCRYPTION_KEY` in `.env` before production use. If it is absent, the application creates an excluded `.env` key automatically for local development; preserve that key with database backups. Never commit `.env` or TOTP recovery secrets.

Run the focused TOTP test with the installed PHP executable:

```powershell
& "C:\wamp64\bin\php\php8.4.15\php.exe" tests\totp_test.php
```

## Security Notes

### Important

⚠️ **This is a development system. Before production:**

1. Enable HTTPS/SSL
2. Implement rate limiting
3. Add CSRF tokens to all forms
4. Validate/sanitize all inputs
5. Implement proper session management
6. Use environment variables for credentials
7. Add request logging and monitoring
8. Implement backup and recovery procedures

### Example Improvements

```php
// Add to backend/db.php for security
session_start();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Validate tokens
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  die('Invalid request');
}
```

## Deployment

### Using WAMP

1. Copy the project into `C:\wamp64\www\procar`.
2. Start Apache and MySQL from the WAMP control panel.
3. Open the WAMP Apache port, for example `http://localhost:8081/procar/index.html` if Apache is listening on port 8081.

### Using the local PHP server

```bash
cd "path/to/pro car"
php -S 127.0.0.1:8080 -t .
```

Then open:

```text
http://127.0.0.1:8080/index.html
```

If port 8080 is in use, choose another free port and update the URL accordingly.

### Cloud Deployment

- **Heroku:** Use Procfile + buildpacks
- **AWS:** EC2 + RDS MySQL
- **DigitalOcean:** App Platform or Droplet

## Troubleshooting

### Database Connection Error

- Check that MySQL is running in WAMP.
- Check the values in `.env` against WAMP’s MySQL settings.
- Visit `http://localhost/procar/backend/auth.php?action=me` and inspect the PHP error log if the API still returns a database error.
- The `pro_car` database is created automatically when the configured MySQL user has permission to create databases. Otherwise create it in phpMyAdmin and reload the site.

### 404 on PHP Endpoints

- Verify WAMP Apache is running
- Check file paths are correct
- Ensure all backend files exist

### Login/Register Not Working

- Use the site through Apache or PHP, not by opening the HTML file directly. PHP endpoints cannot run from `file://`.
- Clear browser storage with `localStorage.clear()` and try again.
- Check the browser console and the Apache/PHP error log.

## Development Notes

### Local Testing Workflow

```bash
# 1. Start WAMP and ensure Apache + MySQL are running
# 2. Open browser
# http://localhost/procar/index.html
# or http://procar.local if configured

# 3. Test endpoints with curl
curl -X GET http://localhost/procar/backend/cars_api.php

# 4. View database
mysql -u root -p pro_car
SELECT * FROM cars;
```

### File Modifications

All frontend changes reload instantly. For PHP changes:

1. Save file
2. Refresh browser page
3. Check error logs: `php -d error_reporting=E_ALL`

## Contributing

To add features:

1. Create new HTML page or component
2. Add corresponding JavaScript logic
3. Create/extend PHP API endpoint
4. Add database tables if needed
5. Update README with changes

## License

Educational project - Feel free to use and modify.

## Support

For issues or questions:

1. Check error messages in browser console (F12)
2. Review database schema in `sql/schema_full.sql`
3. Check backend logs with PHP verbose mode
4. Review API endpoints documentation above

## Troubleshooting

### Issue: "Failed to execute 'json' on 'Response': Unexpected end of JSON input"

**Causes & Solutions:**

1. **Database Not Running**
   - Start MySQL/XAMPP first
   - Error handling now shows: "Database connection failed"
   - All API calls validate response before parsing

2. **Invalid API Response**
   - Check browser console (F12 → Console tab) for full error
   - Look for HTTP status codes (404, 500, etc.)
   - Backend now returns valid JSON on all errors

3. **CORS Issues**
   - Headers already set in `backend/db.php` and all API files
   - If still seeing errors, check server logs

4. **Empty Response**
   - Frontend validates data type before using
   - Error messages now display on page instead of silent failures

**Quick Fix Checklist:**

- [ ] XAMPP MySQL is running (Control Panel)
- [ ] Database imported: `mysql -u root < sql/schema_full.sql`
- [ ] db.php credentials match your MySQL setup
- [ ] Browser console shows no errors (F12)
- [ ] Check network tab (F12 → Network) for failed requests
- [ ] Clear browser cache: Ctrl+Shift+Delete

### Issue: Blank Pages or Missing Cars

- Open browser console (F12)
- Look for error messages
- Check Network tab to see API response status
- Ensure database has data: `mysql -u root -p pro_car`

### Issue: Login/Register Not Working

- Clear browser localStorage: `localStorage.clear()` in console
- Check if email already exists in database
- Verify form has all required fields
- Check backend error message in Network response

### Feature: Error Handling Improvements

All JavaScript files now:

- Check HTTP status before parsing JSON
- Validate response data type
- Display user-friendly error messages
- Log detailed errors to console for debugging

### Database Setup Alternatives

**Option 1: phpMyAdmin (GUI)**

1. Open http://localhost/phpmyadmin
2. Create database: pro_car
3. Import sql/schema_full.sql

**Option 2: MySQL Command Line**

```bash
mysql -u root
CREATE DATABASE pro_car;
USE pro_car;
SOURCE sql/schema_full.sql;
```

**Option 3: MySQL GUI Tools**

- MySQL Workbench
- DataGrip
- DBeaver

## Help & Support inbox

The dashboard support form submits to `backend/support.php`. The backend validates the request, stores it in the `support_tickets` table, associates authenticated user IDs when available, and forwards the submission to Formspree endpoint `https://formspree.io/f/meaagydz`.

### Formspree configuration

1. Copy `.env.example` to `.env`.
2. Keep `FORMSPREE_ENDPOINT=https://formspree.io/f/meaagydz` and `FORMSPREE_FORM_HASHID=meaagydz`.
3. For automatic webhook delivery, enable a Professional or Business Simple Webhook for the form and configure the public webhook URL:

```text
https://your-domain.example/backend/support.php?action=webhook
```

4. Configure the webhook signing secret as `FORMSPREE_WEBHOOK_SECRET` in `.env`.
5. If the plan provides the private submissions API, configure `FORMSPREE_API_KEY`. The admin inbox can then sync recent submissions when it is loaded.
6. Configure `MAIL_FROM` and the PHP/WAMP mail transport if admin reply email delivery is required.

Never expose `FORMSPREE_API_KEY` or `FORMSPREE_WEBHOOK_SECRET` in HTML or JavaScript. The backend database schema is created automatically when `database()` is first called.

### Testing

1. Sign in, open the Quick Actions **Help & Support** item, and submit a request with a name, email, subject, and message.
2. Sign in as an admin and open **Help & Support** in the admin tab list.
3. Verify the request appears, filter it by status, open it, change its status, and add an admin reply.
4. Verify Formspree receives the submission in the Formspree dashboard.
5. If webhook/API configuration is added, submit another request and verify no duplicate ticket appears after Formspree delivery.
6. Check direct webhook delivery with Formspree is signed with `Formspree-Signature`; unsigned deliveries are rejected.
=======
# Pro Car — Online Car Rental System

Modern, responsive car rental platform built with:

- **Frontend:** HTML5, CSS3, JavaScript, Bootstrap 5
- **Backend:** PHP
- **Database:** MySQL via WAMP local server

## Features

### User Roles

- **Customers:** Register, browse cars, book vehicles, view bookings, make payments
- **Company:** Manage inventory, set prices, monitor bookings
- **Admin:** Manage users, cars, bookings, and generate reports

### Core Features

1. **User Registration & Authentication** - Secure login/register with password hashing
2. **Car Search & Browsing** - Filter by category, price, year
3. **Booking Management** - Create, view, and cancel bookings
4. **Payment Processing** - Track payment status
5. **Admin Dashboard** - Manage cars, bookings, and users
6. **Responsive Design** - Modern dark theme with amber accents (matching Hire With Us)

## Installation

### Prerequisites

- PHP 8.4+
- WAMP or another MySQL-enabled local server stack
- Web browser


## Project Structure

```
pro car/
├── index.html                  # Home page
├── cars.html                   # Car browsing & booking
├── dashboard.html              # User/Admin dashboard
├── review.html                 # Customer review flow
├── auth/
│   ├── login.html              # Login page
│   └── register.html           # Registration page
├── backend/
│   ├── db.php                  # Database connection and schema bootstrap
│   ├── auth.php                # Authentication API
│   ├── cars_api.php            # Cars CRUD API
│   ├── bookings.php            # Bookings API
│   ├── payments.php            # Payments API
│   ├── reviews.php             # Review endpoint
│   └── reports.php             # Admin summary reports
├── css/
│   └── style.css               # Main stylesheet (dark theme)
├── js/
│   ├── api.js                  # API base path helper
│   ├── app.js                  # Home page logic
│   ├── auth.js                 # Auth page logic
│   ├── cars.js                 # Cars page logic
│   ├── dashboard.js            # Dashboard logic
│   └── review.js               # Review summary helpers
└── README.md
```

## Database Schema

### Key Tables

- `users` - Customers, drivers, admins, company staff
- `cars` - Vehicle inventory
- `bookings` - Rental bookings
- `payments` - Payment records
- `ratings` - User reviews
- `roles` - User role definitions
- `companies` - Rental companies

## API Endpoints

### Authentication

- `POST backend/auth.php?action=register` - Register new user
- `POST backend/auth.php?action=login` - User login
- `POST backend/auth.php?action=logout` - User logout

### Cars

- `GET backend/cars_api.php` - List cars with filters (category, price, year)
- `GET backend/cars_api.php?admin=1` - List all cars (admin)
- `POST backend/cars_api.php` - Add car (admin)
- `PUT backend/cars_api.php` - Update car (admin)
- `DELETE backend/cars_api.php` - Delete car (admin)

### Bookings

- `GET backend/bookings.php?user_id=ID` - Get user bookings
- `GET backend/bookings.php?admin=1` - Get all bookings (admin)
- `POST backend/bookings.php` - Create booking
- `PUT backend/bookings.php` - Update booking status (admin)
- `DELETE backend/bookings.php` - Cancel booking

### Payments

- `GET backend/payments.php?booking_id=ID` - Get payment info
- `POST backend/payments.php` - Create payment

>>>>>>> 266c62fefc17d5f08db61ab90af00066b1fa1fd2
