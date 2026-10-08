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

