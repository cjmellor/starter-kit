![ChatGPT Image Apr 5, 2025 at 04_53_38 PM](https://github.com/user-attachments/assets/f362a7b7-b488-4fdd-bfad-63e120ffca07)

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

## Features

### Authorization
During the installation process, you'll be prompted to include Authorization. When selected, this option automatically sets up a comprehensive authentication structure including:
- Role and Permission models with migrations
- Basic role assignments (Owner, Member)
