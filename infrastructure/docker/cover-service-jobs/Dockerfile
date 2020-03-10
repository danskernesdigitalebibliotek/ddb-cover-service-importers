ARG VERSION=develop
FROM docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers:${VERSION}
LABEL maintainer="ITK Dev <itkdev@mkb.aarhus.dk>"

# The fcgi packaged is required by the FPM healtcheck script.
RUN apk --update add --no-cache \
        supervisor \
        inotify-tools

# Copy configuration.
COPY etc/supervisor.d /etc/supervisor.d

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

CMD [ "docker-entrypoint.sh" ]
