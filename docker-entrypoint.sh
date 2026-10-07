#!/bin/sh
set -e

# mod_php needs prefork; make sure no other MPM is enabled alongside it.
for f in /etc/apache2/mods-enabled/mpm_*; do
    [ -e "$f" ] || [ -L "$f" ] || continue
    case "$f" in */mpm_prefork.*) ;; *) rm -f "$f" ;; esac
done
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod mpm_prefork >/dev/null

echo "[entrypoint] mods-enabled MPM files:"; ls -l /etc/apache2/mods-enabled | grep -i mpm || true
echo "[entrypoint] LoadModule mpm lines in config:"
grep -rIn "LoadModule *mpm_" /etc/apache2 --include='*.conf' --include='*.load' 2>/dev/null || true

# Railway injects $PORT; Apache must listen on it.
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
