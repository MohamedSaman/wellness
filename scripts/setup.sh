#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# POS System - one-time project setup (Ubuntu / Linux)
#
# Run from the project root AFTER:
#   1. laravel new pos-system   (Vue starter kit)
#   2. extracting the POS starter files into the project
#   3. setting your MySQL details in .env
#
#   bash scripts/setup.sh
# ---------------------------------------------------------------------------
set -euo pipefail

step() { printf '\n\033[36m==> %s\033[0m\n' "$1"; }

if [[ ! -f artisan ]]; then
  echo "Run this from the Laravel project root (the folder with the artisan file)." >&2
  exit 1
fi

step "Checking PHP extensions"
missing=()
for ext in pdo_mysql pdo_sqlite mbstring xml curl zip bcmath intl gd; do
  php -m | grep -qi "^${ext}$" || missing+=("$ext")
done
if (( ${#missing[@]} )); then
  echo "Missing PHP extensions: ${missing[*]}" >&2
  echo "Install them (see docs/SETUP-GUIDE.md, step A1) and run this script again." >&2
  exit 1
fi

step "Installing packages: Filament admin panel, Spatie roles & permissions, Laravel Pennant"
composer require 'filament/filament:^5.0' spatie/laravel-permission laravel/pennant

step "Publishing package config and migrations"
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Laravel\Pennant\PennantServiceProvider"

step "Installing the Filament admin panel (press Enter to accept the \"admin\" panel ID)"
php artisan filament:install --panels

step "Wiring the POS foundation into the project"
php tools/patch-project.php

step "Creating the database tables and demo data"
php artisan migrate:fresh --seed

step "Running the test suite"
php artisan test

printf '\n\033[32mSetup complete.\033[0m\n'
echo "  Start the app:  composer run dev"
echo "  App:            http://127.0.0.1:8000"
echo "  Admin panel:    http://127.0.0.1:8000/admin"
echo "  Owner login:    owner@pos.test / password"
echo "  Cashier login:  cashier@pos.test / password"
echo
