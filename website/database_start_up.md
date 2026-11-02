sudo mysqld_safe --datadir=/var/lib/mysql &

The error "could not find driver" means PHP is missing the PDO MySQL extension. Let me check what PHP extensions are installed:
php -m | grep -i mysql 

The MySQL driver is missing. Let me install the required PHP PDO MySQL extension:
apt update && apt install -y php-mysql

sudo apt update && sudo apt install -y php-mysql

The package is installed but the extension isn't loaded. Let me verify and enable it:
php -i | grep -A5 "PDO"

PDO is enabled but MySQL driver is missing. Let me check the PHP configuration:
php -i | grep "Loaded Configuration File"
ls -la /opt/php/8.0.30/etc/php/ | grep -i mysql
php -i | grep "extension_dir"
ls /opt/php/8.0.30/lib/php/extensions/no-debug-non-zts-20200930/ | grep -i mysql
ls /opt/php/8.0.30/lib/php/extensions/no-debug-non-zts-20200930/
which php && php --version
which apache2 nginx
sudo apache2ctl -V | grep -i "apxs\|mpm" && ls -la /usr/lib/apache2/modules/ | grep php

Apache has PHP 8.3 with a built-in module! Let me check if it has MySQL support:
/usr/bin/php8.3 -m | grep -i mysql


Perfect! PHP 8.3 has MySQL support. Let me check what's running:
lsof -i :8000,3000,80,443 2>/dev/null | grep LISTEN

The issue is the PHP dev server is running PHP 8.0 without MySQL support. Let me switch to PHP 8.3:
kill 1802 && sleep 2 && cd /workspaces/personal_details_saver && /usr/bin/php8.3 -S localhost:3000 2>&1 &

Now let me verify the server is running with the correct PHP version:
sleep 2 && lsof -i :3000 2>/dev/null | grep php