#!/bin/sh

VERSION=develop

docker build --no-cache --build-arg APP_VERSION=${VERSION} --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers:${VERSION} --file="cover-service-importers/Dockerfile" cover-service-importers
docker build --no-cache --build-arg APP_VERSION=${VERSION} --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-jobs:${VERSION} --file="cover-service-jobs/Dockerfile" cover-service-jobs

docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers:${VERSION}
docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-jobs:${VERSION}
