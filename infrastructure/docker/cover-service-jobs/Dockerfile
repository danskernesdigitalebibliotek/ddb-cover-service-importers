FROM docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers
LABEL maintainer="ITK Dev <itkdev@mkb.aarhus.dk>"

ENV FILEPATH=infrastructure/docker/cover-service-jobs

# The fcgi packaged is required by the FPM healtcheck script.
RUN apk --update add --no-cache \
        supervisor \
        inotify-tools

# Copy configuration.
COPY ${FILEPATH}/etc/supervisor.d /etc/supervisor.d

COPY ${FILEPATH}/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

CMD [ "docker-entrypoint.sh" ]
