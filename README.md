[![Latest version](https://img.shields.io/github/v/release/magicsunday/photo-memories?sort=semver)](https://github.com/magicsunday/photo-memories/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/photo-memories)](https://github.com/magicsunday/photo-memories/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/photo-memories/actions/workflows/ci.yml)

# Photo Memories

## Development Guidelines

This section provides guidelines and instructions for developing and maintaining the Photo Memories project.

### Testing

To run tests and code quality checks:

```bash
# Update dependencies
bin/composer update

# Fix code style issues
bin/composer ci:cgl

# Run all tests and code quality checks
bin/composer ci:test

# Run specific checks
bin/composer ci:test:php:lint       # Check for syntax errors
bin/composer ci:test:php:phpstan    # Run static analysis
bin/composer ci:test:php:rector     # Check for potential code improvements
```
