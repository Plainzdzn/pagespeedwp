#!/usr/bin/env bash
#
# Wegwerf-WordPress mit SQLite aufsetzen und alle Integrationstests laufen lassen.
# Kein Datenbankserver nötig, läuft lokal und in der CI.
#
#   bin/integration-sqlite.sh              # frischer Ordner unter /tmp
#   AKWU_SQLITE_DIR=~/akwu-sqlite bin/integration-sqlite.sh   # Ordner wiederverwenden
#   AKWU_TESTS="png-transparency roundtrip" bin/integration-sqlite.sh   # nur diese Tests
#
# png-transparency braucht Imagick und überspringt sich ohne. encoder-guards prüft ohne Imagick nur den GD-Teil. purge setzt GD voraus: Die Testdatei
# „bereits-optimiert.jpg“ ist nur für GD schon optimiert, Imagick macht daraus noch ein kleineres WebP.
#
# Die Tests legen nur eigene, entbehrliche Testbilder an und räumen sie wieder ab.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DIR="${AKWU_SQLITE_DIR:-$(mktemp -d)}"
WP_DIR="$ENV_DIR/wordpress"
WP_CLI="$ENV_DIR/wp-cli.phar"

wp() { php "$WP_CLI" --path="$WP_DIR" --allow-root "$@"; }

mkdir -p "$ENV_DIR"

if [ ! -f "$WP_DIR/wp-config.php" ]; then
	echo "== WordPress, WP-CLI und SQLite-Anbindung laden"
	curl -fsSL -o "$ENV_DIR/wp.tar.gz" https://wordpress.org/latest.tar.gz
	tar xzf "$ENV_DIR/wp.tar.gz" -C "$ENV_DIR"
	curl -fsSL -o "$WP_CLI" https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
	curl -fsSL -o "$ENV_DIR/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
	unzip -q -o "$ENV_DIR/sqlite.zip" -d "$WP_DIR/wp-content/plugins"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP_DIR/wp-content/plugins/sqlite-database-integration#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP_DIR/wp-content/db.php"

	wp config create --dbname=akwu --dbuser=unbenutzt --dbpass=unbenutzt --skip-check --quiet
	wp config set WP_ENVIRONMENT_TYPE local --quiet
	wp core install --url=http://localhost:8080 --title="AKWU Test" --admin_user=admin --admin_password=admin --admin_email=test@example.org --skip-email --quiet
fi

ln -sfn "$REPO_DIR" "$WP_DIR/wp-content/plugins/akuma-webp-umwandler"
wp plugin activate akuma-webp-umwandler --quiet

echo "== WordPress $(wp core version), PHP $(php -r 'echo PHP_VERSION;'), Plugin $(wp eval 'echo AKWU_VERSION;'), Bildbibliothek $(php -r 'echo extension_loaded("imagick") ? "Imagick" : "GD";')"

status=0
for name in ${AKWU_TESTS:-roundtrip purge heic-alias embeds alias-guards png-transparency encoder-guards}; do
	echo
	echo "== $name"
	if ! wp eval-file "$REPO_DIR/tests/integration/$name.php"; then
		status=1
	fi
done

exit $status
