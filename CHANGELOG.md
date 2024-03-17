# CHANGELOG

## [Unreleased]

## [5.4] - 2024-03-17
### Added
- Added support for Laravel 11

## [5.3.1] - 2024-02-01
### Fixed
- Fixed stale state bug (see https://github.com/spiritix/lada-cache/pull/124)

## [5.3] - 2023-02-20
### Added
- Added support for Laravel 10

## [5.2] - 2022-02-14
### Added
- Added support for Laravel 9

## [5.1.2] - 2021-05-20
### Fixed
- Fixed bug in query builder related to primary key names (#104, #103, #91)

## [5.1.1] - 2021-03-19
### Fixed
- Fixed bugs related to PHP 8.0 (#102)

## [5.1] - 2020-01-10
### Added
- Added support for Laravel 8

## [5.0.2] - 2020-06-02
### Fixed
- Hotfix for previous release

## [5.0.1] - 2020-05-25
### Fixed
- Fixed bug with Laravel Telescope (#91)

## [5.0] - 2020-03-18
### Added
- Added support for Laravel 7

## [4.0.2] - 2020-01-23
### Fixed
- Fixed bug with ignoring disabled cache in invalidation (#86)

## [4.0.1] - 2019-12-18
### Fixed
- Hotfix for merge issue

## [4.0] - 2019-12-18
### Added
- Added support for Laravel 6

## [3.0.4] - 2019-11-22
### Fixed
- Fixed missing table tag for WhereHas/WhereDoesntHave functions

## [3.0.3] - 2019-05-24
### Fixed
- Fixed exception caused by raw queries (#66)

## [3.0.2] - 2019-05-08
### Fixed
- Fixed limitation with hardcoded primary key names (#16)

## [3.0.1] - 2019-05-08
### Fixed
- Fixed bug with updating pivot tables (#60)

## [3.0] - 2018-11-15
### Fixed
- Fixed bug caused by 'leftJoinSub' in Laravel 5.7

### Changed
- Updated minimum requirements to PHP 7.1+
- Updated minimum requirements to Laravel 5.7+

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
