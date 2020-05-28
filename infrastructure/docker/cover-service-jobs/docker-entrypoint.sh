#!/bin/sh

set -eux

## Run templates with configuration.
/usr/local/bin/confd --onetime --backend env --confdir /etc/confd

## Warm-up symfony cache (with the current configuration).
/var/www/html/bin/console --env=prod cache:warmup

## Start supervisor and the jobs.
supervisord --nodaemon --configuration /etc/supervisord.conf