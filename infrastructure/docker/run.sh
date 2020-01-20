#!/bin/sh

(cd cover-service-importers && docker build --no-cache --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers .)
(cd cover-service-jobs && docker build --no-cache --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-jobs .)

#docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers:latest
#docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-jobs:latest
