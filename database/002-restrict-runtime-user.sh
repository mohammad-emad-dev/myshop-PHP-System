#!/bin/sh

set -eu

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE is required}"
: "${MYSQL_USER:?MYSQL_USER is required}"

# The account name is embedded only in a controlled mysql init command.
# Reject unusual identifiers instead of interpolating them into SQL.
case "${MYSQL_USER}" in
    ''|root|*[!A-Za-z0-9_]*)
        echo 'MYSQL_USER must be a non-root identifier containing only letters, digits, and underscores.' >&2
        exit 1
        ;;
esac

mysql \
    --protocol=socket \
    --user=root \
    --password="${MYSQL_ROOT_PASSWORD}" \
    --database="${MYSQL_DATABASE}" \
    --init-command="SET @myshop_runtime_user = '${MYSQL_USER}'; SET @myshop_runtime_host = '%';" \
    < /usr/local/share/myshop/batch14_runtime_privileges.sql
