# Laravel (Opionionated) Starter Kit

A modern Laravel starter kit with opinionated defaults and essential tooling for rapid application development.

This starter kit is based off the **Livewire** with **Volt** starter kit.

## Getting Started

Install via the Laravel installer

```
laravel new <app-name> --using=cjmellor/starter-kit
```

## Differences

### Development Tools & Configuration
- Added Rector with Laravel extension for automated PHP refactoring
- Configured Prettier for consistent code formatting
- Added Pint configuration for Laravel-specific PHP code styling
- Updated PHP version requirement to ^8.4
- Implemented GitHub Actions workflows for linting and testing

### Laravel Customizations
- Enhanced AppServiceProvider with opinionated Laravel defaults:
  - Configured query logging for local development
  - Set up model strictness and prevention of lazy loading
  - Added performance optimization settings