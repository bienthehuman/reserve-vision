# Sourced (not executed) by the MySQL image's entrypoint on the first start
# with an empty data volume, so docker_process_sql is available.
# Runs database/sql/create-users.sql for the dev schema and the test schema.

for rv_db in "${RV_DB_DATABASE}" "${RV_DB_TEST_DATABASE}"; do
    mysql_note "ReserveVision: creating schema ${rv_db} and the app/migrator users"
    {
        printf "SET @db_name = '%s', @app_password = '%s', @migrator_password = '%s';\n" \
            "${rv_db}" "${RV_DB_APP_PASSWORD}" "${RV_DB_MIGRATOR_PASSWORD}"
        cat /rv-sql/create-users.sql
    } | docker_process_sql
done
