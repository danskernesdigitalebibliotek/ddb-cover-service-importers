#!/bin/sh

set -eux

## Run templates with configuration.
/usr/local/bin/confd --onetime --backend env --confdir /etc/confd

## Start prometheus export
/usr/local/bin/php-fpm_exporter server &

## Start the PHP process and run command if given. This trick is need to cron imports as k8s cron jobs.
if [ $# -eq 0 ]
 then
    /usr/local/bin/docker-php-entrypoint php-fpm
  else
    /usr/local/bin/docker-php-entrypoint php-fpm &
    exec "$@"
fi
