FROM postgres:16.11-alpine
RUN apk add --no-cache sed

COPY init.sql /init.sql
ARG ADMIN_EPPN
RUN sed "s/<ADMIN_EPPN>/$ADMIN_EPPN/g" "/init.sql" > "/docker-entrypoint-initdb.d/init.sql"