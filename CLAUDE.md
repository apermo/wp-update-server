# Project: WP Update Server

## Repository
- **GitHub**: apermo/wp-update-server (fork of YahnisElsts/wp-update-server)
- **Upstream**: YahnisElsts/wp-update-server

## Overview
A custom update API for WordPress plugins and themes, used with the plugin-update-checker library.

## Tech Stack
- PHP 5.3+
- Zip extension required

## Project Structure
- `index.php` — Entry point / init script
- `loader.php` — Autoloader
- `includes/Wpup/` — Core server classes (UpdateServer, Request, Package, Cache, etc.)
- `packages/` — Plugin/theme ZIP files served by the API
- `cache/` — Writable cache directory
- `logs/` — Request logs

## Issue Tracking
- GitHub Issues on apermo/wp-update-server

## Code Style
- PHP classes use `Wpup_` prefix (PSR-0 style)
- Server is designed for extensibility via class inheritance