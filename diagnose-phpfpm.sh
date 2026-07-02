#!/bin/bash
# Bethany House - PHP-FPM Diagnostic Script

echo "======================================"
echo "Bethany House - PHP-FPM Diagnostics"
echo "======================================"
echo ""

echo "1. Container Status:"
echo "--------------------"
docker-compose ps laravel
echo ""

echo "2. PHP Version & Extensions:"
echo "----------------------------"
docker-compose exec laravel php -v
echo ""
echo "Installed PHP Extensions:"
docker-compose exec laravel php -m | grep -E "pdo|pgsql|redis|json|openssl|mbstring"
echo ""

echo "3. PHP-FPM Process Check:"
echo "-------------------------"
docker-compose exec laravel ps aux | grep php-fpm | grep -v grep || echo "⚠️  NO PHP-FPM PROCESSES RUNNING!"
echo ""

echo "4. Laravel Installation Check:"
echo "------------------------------"
docker-compose exec laravel ls -la | grep -E "artisan|vendor|composer.json" || echo "⚠️  Laravel files missing!"
echo ""

echo "5. Vendor Directory:"
echo "--------------------"
docker-compose exec laravel ls vendor/ 2>/dev/null | head -5 || echo "⚠️  Vendor directory missing - run 'composer install'"
echo ""

echo "6. Storage Permissions:"
echo "-----------------------"
docker-compose exec laravel ls -la storage/
echo ""

echo "7. Laravel Environment:"
echo "-----------------------"
docker-compose exec laravel cat .env | grep -E "APP_KEY|APP_ENV|APP_DEBUG|DB_CONNECTION" || echo "⚠️  .env file issues"
echo ""

echo "8. Can Laravel Run?"
echo "-------------------"
docker-compose exec laravel php artisan --version 2>&1 || echo "⚠️  Laravel cannot run!"
echo ""

echo "9. Database Connection:"
echo "-----------------------"
docker-compose exec laravel php artisan tinker --execute="try { DB::connection()->getPdo(); echo 'Database: CONNECTED'; } catch(Exception \$e) { echo 'Database: FAILED - ' . \$e->getMessage(); }" 2>&1
echo ""

echo "10. Routes Check:"
echo "-----------------"
docker-compose exec laravel php artisan route:list 2>&1 | head -20 || echo "⚠️  Cannot list routes"
echo ""

echo "11. Recent PHP-FPM Logs:"
echo "------------------------"
docker-compose exec laravel tail -30 /var/log/php-fpm/error.log 2>/dev/null || \
docker-compose exec laravel tail -30 /usr/local/var/log/php-fpm.log 2>/dev/null || \
echo "⚠️  No PHP-FPM logs found"
echo ""

echo "12. Recent Laravel Logs:"
echo "------------------------"
docker-compose exec laravel tail -30 storage/logs/laravel.log 2>/dev/null || echo "No Laravel logs yet"
echo ""

echo "13. Nginx Error Logs:"
echo "---------------------"
docker-compose logs nginx --tail=20 | grep error
echo ""

echo "14. Laravel Container Logs:"
echo "---------------------------"
docker-compose logs laravel --tail=30
echo ""

echo "======================================"
echo "Diagnostic Complete"
echo "======================================"
echo ""
echo "Common Issues & Fixes:"
echo ""
echo "1. If 'composer install' needed:"
echo "   docker-compose exec laravel composer install"
echo ""
echo "2. If APP_KEY empty:"
echo "   docker-compose exec laravel php artisan key:generate"
echo ""
echo "3. If permissions wrong:"
echo "   docker-compose exec laravel chown -R www-data:www-data storage bootstrap/cache"
echo "   docker-compose exec laravel chmod -R 775 storage bootstrap/cache"
echo ""
echo "4. If PHP extensions missing:"
echo "   docker-compose build --no-cache laravel"
echo "   docker-compose up -d laravel"
echo ""
echo "5. If all else fails - fresh start:"
echo "   docker-compose down -v"
echo "   docker-compose build --no-cache"
echo "   docker-compose up -d"
echo "   docker-compose exec laravel composer install"
echo ""