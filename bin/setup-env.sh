#!/usr/bin/env bash
#
# Richtet eine WordPress-Testinstallation für den WebP-Umwandler ein.
# Gedacht für Claude-Code-Cloud-Sitzungen (Ubuntu, root, ohne Docker), läuft aber auf jedem
# Linux mit PHP, apt und Netzwerkzugriff.
#
# Installiert bei Bedarf MariaDB, holt WP-CLI, WordPress (de_DE), Elementor und das Theme
# Hello Elementor, bindet dieses Repo per Symlink als Plugin ein und legt Testdaten an.
# Wiederholbar: Vorhandenes wird übersprungen, die Testdaten werden neu angelegt.
#
# Nutzung:
#   bin/setup-env.sh            einrichten und Testdaten anlegen
#   bin/setup-env.sh --serve    zusätzlich den PHP-Server starten (http://localhost:8080)
#
# Umgebungsvariablen: AKWU_ENV_DIR (Standard: ~/akwu-env), AKWU_PORT (Standard: 8080)

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DIR="${AKWU_ENV_DIR:-$HOME/akwu-env}"
WP_DIR="$ENV_DIR/wordpress"
WP_CLI="$ENV_DIR/wp-cli.phar"
PORT="${AKWU_PORT:-8080}"
URL="http://localhost:$PORT"
DB_NAME="akwu_test"
DB_USER="akwu"
DB_PASS="akwu"

log() { printf '\n== %s\n' "$*"; }
wp() { php "$WP_CLI" --path="$WP_DIR" --allow-root "$@"; }

# Downloads brechen über Proxys gelegentlich ab, deshalb bis zu fünf Versuche.
retry() {
	local attempt
	for attempt in 1 2 3 4; do
		"$@" && return 0
		echo "Fehlgeschlagen, neuer Versuch in $((2 ** attempt)) s ..." >&2
		sleep $((2 ** attempt))
	done
	"$@"
}

mkdir -p "$ENV_DIR"

# --- MariaDB ---------------------------------------------------------------
if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
	log "MariaDB installieren"
	export DEBIAN_FRONTEND=noninteractive
	apt-get update -qq
	apt-get install -y -qq mariadb-server >/dev/null
fi

if ! mysqladmin ping --silent >/dev/null 2>&1; then
	log "MariaDB starten"
	service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1 || {
		mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
		(mysqld_safe --user=mysql >/dev/null 2>&1 &)
	}
	for _ in $(seq 1 30); do
		mysqladmin ping --silent >/dev/null 2>&1 && break
		sleep 1
	done
fi

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# --- WP-CLI ----------------------------------------------------------------
if [ ! -f "$WP_CLI" ]; then
	log "WP-CLI laden"
	retry curl -sSfL -o "$WP_CLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi

# --- WordPress -------------------------------------------------------------
if [ ! -f "$WP_DIR/wp-load.php" ]; then
	log "WordPress laden (de_DE)"
	retry wp core download --locale=de_DE --quiet
fi

if [ ! -f "$WP_DIR/wp-config.php" ]; then
	log "wp-config.php anlegen"
	wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost=127.0.0.1 --locale=de_DE --skip-check --quiet
	wp config set WP_ENVIRONMENT_TYPE local --quiet
	wp config set WP_DEBUG true --raw --quiet
	wp config set WP_DEBUG_LOG true --raw --quiet
	wp config set WP_DEBUG_DISPLAY false --raw --quiet
fi

if ! wp core is-installed >/dev/null 2>&1; then
	log "WordPress installieren"
	wp core install --url="$URL" --title="WebP-Umwandler Test" --admin_user=admin --admin_password=admin \
		--admin_email=admin@example.com --skip-email --quiet
fi

# WP-CLI errät die URL je nach Pfad mit Unterordner, deshalb immer fest setzen.
wp option update home "$URL" --quiet
wp option update siteurl "$URL" --quiet

# --- Plugins und Theme -----------------------------------------------------
ln -sfn "$REPO_DIR" "$WP_DIR/wp-content/plugins/akuma-webp-umwandler"

# Per curl laden: WordPress selbst nutzt HTTPS_PROXY nicht, in Cloud-Sitzungen schlägt der Download sonst fehl.
if ! wp plugin is-installed elementor; then
	log "Elementor installieren"
	retry curl -sSfL -o "$ENV_DIR/elementor.zip" https://downloads.wordpress.org/plugin/elementor.latest-stable.zip
	wp plugin install "$ENV_DIR/elementor.zip" --quiet
fi
if ! wp theme is-installed hello-elementor; then
	log "Hello Elementor installieren"
	retry curl -sSfL -o "$ENV_DIR/hello-elementor.zip" https://downloads.wordpress.org/theme/hello-elementor.latest-stable.zip
	wp theme install "$ENV_DIR/hello-elementor.zip" --quiet
fi
wp theme activate hello-elementor --quiet
wp plugin activate elementor akuma-webp-umwandler --quiet

# --- Testdaten -------------------------------------------------------------
log "Testdaten anlegen"
wp eval-file "$REPO_DIR/tests/seed/seed.php"

# --- Server ----------------------------------------------------------------
if [ "${1:-}" = "--serve" ]; then
	if ! curl -s -o /dev/null "$URL/wp-login.php"; then
		log "PHP-Server starten"
		nohup php -S "localhost:$PORT" -t "$WP_DIR" >"$ENV_DIR/server.log" 2>&1 &
		sleep 1
	fi
fi

log "Fertig"
echo "WordPress:  $WP_DIR"
echo "URL:        $URL/wp-admin/admin.php?page=akwu  (admin / admin)"
echo "WP-CLI:     php $WP_CLI --path=$WP_DIR --allow-root <befehl>"
