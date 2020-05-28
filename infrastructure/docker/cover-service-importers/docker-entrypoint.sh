#!/bin/sh

set -eux

## Run templates with configuration.
/usr/local/bin/confd --onetime --backend env --confdir /etc/confd

## Start prometheus export
/usr/local/bin/php-fpm_exporter server &

## Warm-up symfony cache (with the current configuration).
/var/www/html/bin/console --env=prod cache:warmup

## Start the PHP process and run command if given. This trick is need to cron imports as k8s cron jobs.
if [ $# -eq 0 ]
 then
    /usr/local/bin/docker-php-entrypoint php-fpm
  else
    /usr/local/bin/docker-php-entrypoint php-fpm &
    exec "$@"
fi
