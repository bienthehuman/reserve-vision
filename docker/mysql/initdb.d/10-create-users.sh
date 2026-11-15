# shellcheck shell=bash
# Sourced (not executed) by the MySQL image's entrypoint on the first start
# with an empty data volume, so docker_process_sql is available.
# Runs database/sql/create-users.sql for the app schema and, when
# RV_DB_TEST_DATABASE is set (dev only, compose.yaml), for the test schema and
# one test schema per parallel Pest worker (<test>_1 … _4, see tests/TestCase.php),
# and for the concurrency suite's schema (RV_DB_CONCURRENCY_DATABASE, slice 2.5).
# compose.prod.yaml mounts this same script without RV_DB_TEST_DATABASE.

rv_schemas=("${RV_DB_DATABASE}")
if [[ -n "${RV_DB_TEST_DATABASE:-}" ]]; then
    rv_schemas+=("${RV_DB_TEST_DATABASE}" \
        "${RV_DB_TEST_DATABASE}_1" "${RV_DB_TEST_DATABASE}_2" "${RV_DB_TEST_DATABASE}_3" "${RV_DB_TEST_DATABASE}_4" \
        "${RV_DB_CONCURRENCY_DATABASE:-reservevision_concurrency}")
fi

for rv_db in "${rv_schemas[@]}"; do
    mysql_note "ReserveVision: creating schema ${rv_db} and the app/migrator users"
    {
        printf "SET @db_name = '%s', @app_password = '%s', @migrator_password = '%s';\n" \
            "${rv_db}" "${RV_DB_APP_PASSWORD}" "${RV_DB_MIGRATOR_PASSWORD}"
        cat /rv-sql/create-users.sql
    } | docker_process_sql
done
