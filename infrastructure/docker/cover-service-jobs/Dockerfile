ARG VERSION=develop
FROM danskernesdigitalebibliotek/cover-service-importers:${VERSION}
LABEL maintainer="ITK Dev <itkdev@mkb.aarhus.dk>"

# The fcgi packaged is required by the FPM healtcheck script.
RUN apk --update add --no-cache \
        supervisor \
        inotify-tools

# Copy configuration.
COPY etc/confd/conf.d/supervisor.toml /etc/confd/conf.d/supervisor.toml
COPY etc/confd/templates/supervisor.tmpl /etc/confd/templates/supervisor.tmpl
RUN mkdir -p /etc/supervisor.d/

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

CMD [ "docker-entrypoint.sh" ]
