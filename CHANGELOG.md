# Changelog

## 3.0.0

The bundle presumes no provider: glitchr/typesense-bundle, the search server, is suggested.

### Changed

- `glitchr/typesense-bundle` moves from `require` to `suggest` (and `require-dev`). Without it the bundle boots,
  `Search\SafeIndexer` is not registered and the database answers the search (as when Typesense is down).
- `Entity\Video` no longer implements `Typesense\Bundle\TypesenseInterface` and has no `__typesenseGetter()`: the
  bundle indexes it through its mapping, its fields read by its own reader (optional since typesense-bundle 2.0;
  the document is the same). `__typesense()` stays, the film's title inside another's document.

### Added

- `tests/Kernel/WithoutSuggestedPackagesTest`: the bundle boots without glitchr/typesense-bundle.

## 2.0.0

omnibase/video is a video platform on omnisense/omnibase: members send their films and the platform runs them, visitors
watch, rate, comment and keep lists; channels, subscriptions, views, suggestions and moderation. It names only `Base\`
classes: its repositories extend `Base\Database\Repository\ServiceEntityRepository` and speak the core's finder
language, its services write through `Base\Contract\UnitOfWork` and read other classes through
`Base\Database\Repository\Repositories`. Each query is written once in the finder language, the views and suggestions
included (`findValuesBy()` with aggregates, `updateBy()`, `deleteBy()`, `sql()` for the popular films), and compiled by
the engine of the persistence chosen (omnibridge/doctrine or omnibridge/eloquent).
