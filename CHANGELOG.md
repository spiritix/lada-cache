# CHANGELOG

## [Unreleased]

## [2.1.2] - 2018-05-14
### Fixed
- Fixed race condition which possibly led to outdated query results being returned after keys expire

## [2.1.1] - 2018-01-29
### Fixed
- Fixed bug in publishing config

### Added
- Added a basic Wiki

## [2.1] - 2017-12-05
### Fixed
- Fixed bug with subqueries (#46)

### Changed
- Updated README

### Added
- Added support for Laravel 5.5

## [2.0] - 2017-07-20
### Changed
- Now using a trait instead of a base model class

### Added
- Added support for Laravel 5.4

## [1.4.2] - 2016-12-09
### Fixed
- Fixed bug in debug bar collector

### Changed
- Optimized reflector for some edge cases

## [1.4.1] - 2016-12-07
### Fixed
- Fixed bug regarding union select queries

## [1.4] - 2016-12-07
### Fixed
- Fixed a bug in relation to the Debug Bar, write queries were not displayed properly
- Fixed bug in invalidator (#30)

### Changed
- Improved caching strategy
- Refactored parts of the library

### Added
- Added a lot of new tests

## [1.3.1] - 2016-09-20
### Added
- Added support for PhpRedis

### Changed
- Refactored and improved service provider

### Fixed
- Fixed bug with tag invalidation

## [1.3] - 2016-09-19
### Changed
- Changed the encoder to make use of serialization instead of JSON since there were issues with associative arrays

### Fixed
- Fixed bug with installed but not enabled debug bar (#26)
- Fixed bug with Redis 'exists' return value

## [1.2.2] - 2016-05-18
### Fixed
- Fixed bug with Travis CI (#19)
- Fixed bug in query builder (#21)

### Changed
- Improved unit tests

## [1.2.1] - 2016-05-15
### Fixed
- Fixed expiration time bug

## [1.2] - 2016-05-15
### Added
- The changelog
- A collector for Laravel Debugbar 
- It's now possible to set the expiration time in the configuration
- Integration tests

### Changed
- PHP version dependency from 5.6 to 5.5
- Refactored parts of the library, reflectors concept was revised, performance optimized

### Fixed
- Fixed bug in reflector (#11)

## [1.1.1] - 2016-03-20
### Fixed
- Fixed critical bug, invalidating multiple tags was not working

## [1.1] - 2016-02-11
### Added
- It's now possible to cache only specific models or exclude some of them

### Changed
- Refactored most of the classes and the structure of the library

### Fixed
- Fixed various major and minor bugs

## [1.0] - 2015-12-02
### Added
- Initial stable release
