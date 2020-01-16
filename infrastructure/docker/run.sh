#!/bin/sh

(cd cover-service-importers && docker build --no-cache --tag=docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service-importers/cover-service-importers .)

#docker push docker.pkg.github.com/danskernesdigitalebibliotek/ddb-cover-service/cover-service-importers:latest
