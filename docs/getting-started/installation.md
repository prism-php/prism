# Installation

Install the PHP package with Composer, then publish its Laravel configuration.

## Requirements

The PHP package requires:

- PHP 8.2 or higher
- Laravel 12.61.1+ or 13.12.0+, within the package's Composer constraints

## Step 1: Composer Installation

::: tip
Prism is pre-1.0. Choose a version constraint appropriate for your application, commit your lockfile and review release notes before upgrading.
:::

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
