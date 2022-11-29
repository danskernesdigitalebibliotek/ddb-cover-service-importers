<!-- markdownlint-configure-file { "blanks-around-headers": { "lines_below": 0 } } -->
<!-- markdownlint-configure-file { "blanks-around-lists": false } -->

# Changelog

![Keep a changelog badge](https://img.shields.io/badge/Keep%20a%20Changelog-v1.0.0-brightgreen.svg?logo=data%3Aimage%2Fsvg%2Bxml%3Bbase64%2CPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGZpbGw9IiNmMTVkMzAiIHZpZXdCb3g9IjAgMCAxODcgMTg1Ij48cGF0aCBkPSJNNjIgN2MtMTUgMy0yOCAxMC0zNyAyMmExMjIgMTIyIDAgMDAtMTggOTEgNzQgNzQgMCAwMDE2IDM4YzYgOSAxNCAxNSAyNCAxOGE4OSA4OSAwIDAwMjQgNCA0NSA0NSAwIDAwNiAwbDMtMSAxMy0xYTE1OCAxNTggMCAwMDU1LTE3IDYzIDYzIDAgMDAzNS01MiAzNCAzNCAwIDAwLTEtNWMtMy0xOC05LTMzLTE5LTQ3LTEyLTE3LTI0LTI4LTM4LTM3QTg1IDg1IDAgMDA2MiA3em0zMCA4YzIwIDQgMzggMTQgNTMgMzEgMTcgMTggMjYgMzcgMjkgNTh2MTJjLTMgMTctMTMgMzAtMjggMzhhMTU1IDE1NSAwIDAxLTUzIDE2bC0xMyAyaC0xYTUxIDUxIDAgMDEtMTItMWwtMTctMmMtMTMtNC0yMy0xMi0yOS0yNy01LTEyLTgtMjQtOC0zOWExMzMgMTMzIDAgMDE4LTUwYzUtMTMgMTEtMjYgMjYtMzMgMTQtNyAyOS05IDQ1LTV6TTQwIDQ1YTk0IDk0IDAgMDAtMTcgNTQgNzUgNzUgMCAwMDYgMzJjOCAxOSAyMiAzMSA0MiAzMiAyMSAyIDQxLTIgNjAtMTRhNjAgNjAgMCAwMDIxLTE5IDUzIDUzIDAgMDA5LTI5YzAtMTYtOC0zMy0yMy01MWE0NyA0NyAwIDAwLTUtNWMtMjMtMjAtNDUtMjYtNjctMTgtMTIgNC0yMCA5LTI2IDE4em0xMDggNzZhNTAgNTAgMCAwMS0yMSAyMmMtMTcgOS0zMiAxMy00OCAxMy0xMSAwLTIxLTMtMzAtOS01LTMtOS05LTEzLTE2YTgxIDgxIDAgMDEtNi0zMiA5NCA5NCAwIDAxOC0zNSA5MCA5MCAwIDAxNi0xMmwxLTJjNS05IDEzLTEzIDIzLTE2IDE2LTUgMzItMyA1MCA5IDEzIDggMjMgMjAgMzAgMzYgNyAxNSA3IDI5IDAgNDJ6bS00My03M2MtMTctOC0zMy02LTQ2IDUtMTAgOC0xNiAyMC0xOSAzN2E1NCA1NCAwIDAwNSAzNGM3IDE1IDIwIDIzIDM3IDIyIDIyLTEgMzgtOSA0OC0yNGE0MSA0MSAwIDAwOC0yNCA0MyA0MyAwIDAwLTEtMTJjLTYtMTgtMTYtMzEtMzItMzh6bS0yMyA5MWgtMWMtNyAwLTE0LTItMjEtN2EyNyAyNyAwIDAxLTEwLTEzIDU3IDU3IDAgMDEtNC0yMCA2MyA2MyAwIDAxNi0yNWM1LTEyIDEyLTE5IDI0LTIxIDktMyAxOC0yIDI3IDIgMTQgNiAyMyAxOCAyNyAzM3MtMiAzMS0xNiA0MGMtMTEgOC0yMSAxMS0zMiAxMXptMS0zNHYxNGgtOFY2OGg4djI4bDEwLTEwaDExbC0xNCAxNSAxNyAxOEg5NnoiLz48L3N2Zz4K)

All notable changes to this project will be documented in this file.

See [keep a changelog](https://keepachangelog.com/en/1.0.0/) for information about writing changes to this log.

## [Unreleased]

- Updated elastic-search to version 8.5.2

## [3.5.1] - 2022-11-21

### Changed
- Added `app:vendor:remove-by-url-pattern` command to remove covers based on original filename
- Filter default covers out of global overdrive vendor importer

## [3.5.0] - 2022-10-11

### Changed
- Added support for switching agency and profile doing searches in OpenPlatform

## [3.4.1] - 2022-11-09

### Fixed
- Fixed double covers for matching ISBN10 and 13

## [3.4.0] - 2022-11-08

### Added
- Map no-hit faust search to katalog posts, when possible.

## [3.3.0] - 2022-10-03

### Added
- Abstract DatawellVendor.
- BlockBuster vendor service.
- ComicsPlus vendor service.

### Changed
- Deprecated "datawell" vendor (now "ComicsPlus" vendor).
- TheMovieDatabaseVendor has been refactored to use the Abstract DatawellVendor.
- TheMovieDatabaseVendor now supports single cover no hits processing.
- Match logic for TheMovieDatabaseVendor has been refactored for better matching.
- VendorServiceSingleIdentifierInterface now has nullable return.
- OverDriveMagazinesVendorService has been refactored to use the Abstract DatawellVendor.
- PressReaderVendorService has been refactored to use the Abstract DatawellVendor.
- Consolidated all datawell search code to a DataWellClient.
- Ordered services.yaml and changed "datawell.*" from parameters to direct injection.
- EbookCentralVendorService has been refactored to use the Abstract DatawellVendor.
- EbookCentralVendorService now supports single cover no hits processing.
- `box/spout` (abandoned) replaced by `openspout/openspout` which seems to be the community replacement

## [3.2.1] - 2022-09-15

### Fixed
- Fixed vendor id NULL in user upload messages.

## [3.2.0] - 2022-09-12

### Added
- "single cover" vendor for no hits processing.
- "OpenLibrary" single cover vendor.

### Changed
- Added "single cover" support to Bogportalen vendor.

## [3.1.1] - 2022-09-03

### Fixed
- Fixed validate images headers index

## [3.1.0] - 2022-08-22

### Added
- Support for hasCover services.

## [3.0.1] - 2022-08-16

### Changed
- Updated metrics bundle.

## [3.0.0] - 2022-08-15

### Changed
- Updated url pattern in Datawell vendor url converter
- Updated code base with types to prepare for PHP 8.
- Switch to Cloudinary API version 2.
- Updated MariaDB version to 10.6 in doctrine config.
- Switched to ElasticSearch 8.2 from version 6.x.
- Upgraded elasticsearch/elasticsearch to 8.2.
- Deprecated DRbDigitalBooksVendor
- Removed flysystem
- Fixed http client options error in vendor image validation service

### Removed
- Removed FOS elasticsearch bundle and Elastica library.

## [2.3.3] - 2022-05-19

### Changed
- Ensuring database is flushed/cleared before messages are sent into queue.

## [2.3.2] - 2022-05-18

### Changed
- Updated MBU vendor to use faust number to fix issues with.

### Removed
- Microsoft SQL database SSL certificates configuration.

## [2.3.1] - 2022-04-05

### Changed
- Update docker base images.

## [2.3.0] - 2022-04-05

### Added
- Added PressReader vendor service
