# Installation

Install the PHP package with Composer, then publish its Laravel configuration.

## Requirements

The PHP package requires:

- PHP 8.2 or higher
- Laravel `^12.69.0` or `^13.30.0`
- PHP extensions: `fileinfo`, `filter`, `mbstring` and `openssl`

## Step 1: Composer Installation

Run this command from your project directory:

```bash
composer require particle-academy/prism
```

This command will download Prism and its dependencies into your project.

## Step 2: Publish the Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=prism-config
```

This creates `config/prism.php`. Continue to [Configuration](/getting-started/configuration) to set up provider credentials.
