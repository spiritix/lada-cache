---
trigger: always_on
description: 
globs: 
---

- Use declare(strict_types=1); in every PHP file.
- Follow PSR-12 coding standard.
- Follow Laravel 12 official coding standards (as enforced by PHPStan + Larastan).
- Use type hints and return type declarations everywhere possible and compatible with Laravel’s method signatures.
- Avoid legacy PHPDoc types when type hints already exist.
- Use PHP 8.3 language features where stable and compatible (readonly classes, enums, promoted properties, etc.).
- Add DocBlocks only when they add real value (clarify behavior, non-obvious logic, or document arrays / generics).
- Do not duplicate type information that is already declared via type hints.
- Use @inheritDoc on all overridden Laravel methods or framework classes.
- For package-level classes (e.g. extending Connection, Builder, etc.), mirror Laravel’s own DocBlock signatures and order exactly.
- Include class-level DocBlocks describing the purpose of the class and any important architectural notes.
- Avoid inline comments except where logic is complex or non-obvious.