#!/bin/sh

(cd ../../ && docker build --no-cache --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers --file="infrastructure/docker/cover-service-importers/Dockerfile" .)
(cd ../../ && docker build --no-cache --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-jobs --file="infrastructure/docker/cover-service-jobs/Dockerfile" .)

docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers:latest
docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-jobs:latest
