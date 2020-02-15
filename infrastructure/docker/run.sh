#!/bin/sh

docker build --no-cache --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers --file="cover-service-importers/Dockerfile" cover-service-importers
docker build --no-cache --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-jobs --file="cover-service-jobs/Dockerfile" cover-service-jobs

docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers:latest
docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-jobs:latest
