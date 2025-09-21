# Events Platform - Backend API

A Laravel-based RESTful API backend for managing sports competitions, providing comprehensive event management capabilities.

## Features

- **RESTful API**: Complete API for frontend and third-party integrations
- **Authentication & Authorization**: Sanctum-based authentication with role-based access control
- **Event Management**: Create and manage multiple sporting events
- **Discipline Management**: Support for various race disciplines and categories
- **Athlete Management**: Register and manage athlete profiles with club associations
- **Crew Management**: Handle crew formations for team events
- **Race Results**: Real-time race result recording and management
- **QR Code Generation**: Generate unique QR codes for athlete identification
- **Password Reset**: Email-based password recovery system
- **File Upload**: Support for athlete photos and document uploads
- **Data Import**: Bulk import crews from CSV/Excel files
- **API Key Authentication**: Support for third-party integrations

## Tech Stack

- **Framework**: Laravel 9.x
- **Language**: PHP 8.0.2+
- **Database**: MySQL/MariaDB
- **Authentication**: Laravel Sanctum 3.0
- **Mail**: SMTP-based email delivery
- **Testing**: PHPUnit
- **API Documentation**: RESTful JSON API

## Project Structure

```
app/
├── Console/Commands/     # Custom artisan commands
├── Http/
│   ├── Controllers/
│   │   ├── API/         # API controllers for all endpoints
│   │   └── Controller.php
│   ├── Middleware/      # Custom middleware (Admin, ApiKey auth)
│   └── Requests/        # Form request validation
├── Mail/               # Email templates
├── Models/             # Eloquent models
│   ├── Athlete.php
│   ├── Club.php
│   ├── Crew.php
│   ├── CrewAthlete.php
│   ├── CrewResult.php
│   ├── Discipline.php
│   ├── Event.php
│   ├── RaceResult.php
│   ├── Team.php
│   └── User.php
└── Providers/          # Service providers

database/
├── migrations/         # Database migrations
└── seeders/           # Database seeders

routes/
├── api.php            # API routes
└── web.php           # Web routes
```

## API Endpoints

### Authentication
- `POST /api/login` - User login
- `POST /api/logout` - User logout
- `POST /api/register` - User registration
- `POST /api/password-reset` - Request password reset
- `POST /api/password-reset/confirm` - Confirm password reset

### Events & Disciplines
- `GET/POST/PUT/DELETE /api/events` - Event CRUD operations
- `GET/POST/PUT/DELETE /api/disciplines` - Discipline CRUD operations

### Athletes & Clubs
- `GET/POST/PUT/DELETE /api/athletes` - Athlete CRUD operations
- `GET/POST/PUT/DELETE /api/clubs` - Club CRUD operations
- `POST /api/athletes/import` - Bulk import athletes

### Crews & Results
- `GET/POST/PUT/DELETE /api/crews` - Crew CRUD operations
- `GET/POST/PUT/DELETE /api/race-results` - Race result operations
- `GET /api/crews/{id}/results` - Get crew results

### Teams
- `GET/POST/PUT/DELETE /api/teams` - Team CRUD operations
- `POST /api/teams/{id}/clubs` - Manage team clubs

### Files & QR Codes
- `POST /api/file/upload` - Upload files
- `GET /api/qr-code/{athlete_id}` - Generate athlete QR code

## Getting Started

### Prerequisites

- PHP 8.0.2 or higher
- Composer
- MySQL/MariaDB
- Mail server (for password reset functionality)

### Installation

1. Clone the repository:
```bash
git clone [repository-url]
cd eventsmotion
```

2. Install dependencies:
```bash
composer install
```

3. Copy environment file:
```bash
cp .env.example .env
```

4. Generate application key:
```bash
php artisan key:generate
```

5. Configure database in `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

6. Configure mail settings in `.env`:
```
MAIL_MAILER=smtp
MAIL_HOST=your_mail_host
MAIL_PORT=587
MAIL_USERNAME=your_mail_username
MAIL_PASSWORD=your_mail_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
```

7. Run migrations:
```bash
php artisan migrate
```

8. Seed the database (optional):
```bash
php artisan db:seed
```

9. Start the development server:
```bash
php artisan serve
```

## Custom Artisan Commands

- `php artisan crews:import {file}` - Import crews from CSV/Excel
- `php artisan cleanup:stale-results` - Clean up stale crew results
- `php artisan test:database` - Test database connection
- `php artisan test:email` - Test email configuration

## Testing

Run the test suite:
```bash
php artisan test
```

## Deployment

For production deployment:

1. Set environment to production in `.env`:
```
APP_ENV=production
APP_DEBUG=false
```

2. Optimize the application:
```bash
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

3. Ensure proper file permissions:
```bash
chmod -R 755 storage
chmod -R 755 bootstrap/cache
```

## Security

- All API endpoints are protected with Sanctum authentication
- Admin-only endpoints require admin middleware
- API key authentication available for third-party integrations
- CSRF protection enabled for web routes
- SQL injection protection through Eloquent ORM
- XSS protection through blade templating

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Author

**Zoran Trandafilovic**

## License

This project is proprietary software. All rights reserved.