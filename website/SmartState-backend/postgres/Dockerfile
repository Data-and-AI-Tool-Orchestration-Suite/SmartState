FROM postgres:18-alpine
COPY init.sql /opt/smartstate/init.sql.template
COPY 001-init.sh /docker-entrypoint-initdb.d/001-init.sh
COPY migrations/ /opt/smartstate/migrations/
