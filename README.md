# Laravel Starter Backend

A reusable Laravel starter backend providing admin and public auth, roles/permissions,
a Website Content (CMS) mechanism, blog, FAQ, contact messages, and supporting
infrastructure (media uploads, phone/OTP, notifications). Product-specific content
has been removed — only generic, reusable starter modules remain.

## Requirements

- PHP 8.4
- Composer
- Node.js (only if building frontend assets)

## Setup

```bash
composer install
npm install   # only if frontend assets are needed
npm run build # only if frontend assets are needed
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

## Admin Login

The seeded super admin account is created from the following environment variables:

```env
SUPER_ADMIN_EMAIL=admin@pulvent.com
SUPER_ADMIN_PASSWORD=change-me
```

`config/auth_features.php` falls back to `admin@pulvent.com` for the email if
`SUPER_ADMIN_EMAIL` is unset. These are starter/local defaults only. Set your
own values in `.env` before running seeders in any real environment.

## Documentation

- [Website Content Dashboard Guide](docs/website-content-dashboard-guide.md)

## Running Tests

```bash
vendor/bin/phpunit
```

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
