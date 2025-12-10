# Changelog

All notable changes to `laravel-benchmark` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2024-12-10

### Added
- Production-safe benchmark system (disabled in production environment)
- Isolated benchmark database with Docker Compose support
- Dynamic command generation via `$code` and `$options` properties
- Intelligent Advisor module:
  - N+1 query detection with smart eager loading suggestions
  - Slow query alerts with configurable thresholds
  - Hotspot identification (code locations causing most DB activity)
  - Duplicate query detection
- Performance Score (0-100) with detailed breakdown and grades
- Multiple iterations with statistics:
  - Median, average, min/max values
  - Standard deviation with variance assessment
  - P95/P99 percentiles
  - Stability assessment (Very Stable → High Variance)
- Warmup runs support to eliminate cold cache bias
- Baseline system for performance tracking over time
- Regression detection with severity levels (warning/critical)
- CI/CD integration with `--fail-on-regression` and `--export` options
- Artisan commands:
  - `benchmark:install` - Package installation with Docker support
  - `benchmark:run {name}` - Run benchmark by class name
  - `benchmark:{code}` - Dynamic commands with custom options
  - `benchmark:list` - List all available benchmarks
  - `benchmark:baselines` - List all saved baselines
  - `make:benchmark` - Create new benchmark class
  - `make:benchmark-seeder` - Create benchmark data seeder
- Configurable thresholds for all Advisor rules
- Environment variables support for all major settings

